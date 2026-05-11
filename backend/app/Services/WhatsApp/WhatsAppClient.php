<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Message;
use App\Models\Retailer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class WhatsAppClient
{
    public function inspectCredentials(?int $retailerId = null): array
    {
        $retailer = $retailerId ? Retailer::query()->find($retailerId) : null;
        $retailerPhoneNumberId = trim((string) data_get($retailer?->settings, 'whatsapp.phone_number_id', ''));
        $retailerToken = trim((string) data_get($retailer?->settings, 'whatsapp.access_token', ''));
        $envPhoneNumberId = trim((string) config('services.whatsapp.phone_number_id'));
        $envToken = trim((string) config('services.whatsapp.token'));

        $usingRetailerCredentials = $retailerPhoneNumberId !== '' && $retailerToken !== '';
        $phoneNumberId = $usingRetailerCredentials ? $retailerPhoneNumberId : $envPhoneNumberId;
        $token = $usingRetailerCredentials ? $retailerToken : $envToken;

        $credentialCandidates = [];
        if ($usingRetailerCredentials) {
            $credentialCandidates[] = [
                'source' => 'retailer_settings',
                'phone_number_id' => $retailerPhoneNumberId,
                'token' => $retailerToken,
            ];
        }

        if ($envPhoneNumberId !== '' && $envToken !== '') {
            $sameAsRetailer = $usingRetailerCredentials
                && $envPhoneNumberId === $retailerPhoneNumberId
                && $envToken === $retailerToken;

            if (! $sameAsRetailer) {
                $credentialCandidates[] = [
                    'source' => 'env_fallback',
                    'phone_number_id' => $envPhoneNumberId,
                    'token' => $envToken,
                ];
            }
        }

        return [
            'retailer' => $retailer,
            'phone_number_id' => $phoneNumberId,
            'token' => $token,
            'using_retailer_credentials' => $usingRetailerCredentials,
            'default_source' => $usingRetailerCredentials ? 'retailer_settings' : 'env_fallback',
            'credential_candidates' => $credentialCandidates,
            'configured' => $phoneNumberId !== '' && $token !== '',
        ];
    }

    public function testConnection(?int $retailerId = null): array
    {
        $inspection = $this->inspectCredentials($retailerId);
        $attempts = [];

        if (! $inspection['configured']) {
            return [
                'valid' => false,
                'credential_source' => 'missing',
                'message' => 'Missing WhatsApp credentials.',
                'attempts' => [],
            ];
        }

        $candidates = $inspection['credential_candidates'];
        if ($candidates === []) {
            $candidates[] = [
                'source' => $inspection['default_source'],
                'phone_number_id' => $inspection['phone_number_id'],
                'token' => $inspection['token'],
            ];
        }

        foreach ($candidates as $candidate) {
            try {
                $response = Http::withToken((string) $candidate['token'])
                    ->timeout(15)
                    ->get("https://graph.facebook.com/v20.0/{$candidate['phone_number_id']}", [
                        'fields' => 'id,display_phone_number,verified_name',
                    ])
                    ->throw();

                $payload = $response->json();
                $attempts[] = [
                    'source' => (string) $candidate['source'],
                    'success' => true,
                ];

                return [
                    'valid' => true,
                    'credential_source' => (string) $candidate['source'],
                    'message' => 'WhatsApp connection is valid.',
                    'phone_number_id' => (string) $candidate['phone_number_id'],
                    'display_phone_number' => (string) data_get($payload, 'display_phone_number', ''),
                    'verified_name' => (string) data_get($payload, 'verified_name', ''),
                    'attempts' => $attempts,
                ];
            } catch (\Throwable $throwable) {
                $attempts[] = [
                    'source' => (string) $candidate['source'],
                    'success' => false,
                    'error' => $throwable->getMessage(),
                ];
            }
        }

        $lastAttempt = end($attempts) ?: null;

        return [
            'valid' => false,
            'credential_source' => (string) ($lastAttempt['source'] ?? $inspection['default_source']),
            'message' => (string) ($lastAttempt['error'] ?? 'WhatsApp connection failed.'),
            'attempts' => $attempts,
        ];
    }

    public function sendText(
        string $phone,
        string $message,
        ?int $retailerId = null,
        ?int $customerId = null
    ): void
    {
        if ($retailerId === null && $customerId !== null) {
            $retailerId = Customer::query()->whereKey($customerId)->value('retailer_id');
        }

        $inspection = $this->inspectCredentials($retailerId);
        $phoneNumberId = (string) $inspection['phone_number_id'];
        $token = (string) $inspection['token'];
        $usingRetailerCredentials = (bool) $inspection['using_retailer_credentials'];
        $credentialCandidates = $inspection['credential_candidates'];

        $responsePayload = null;
        $externalId = null;
        $sendError = null;
        $finalCredentialSource = $usingRetailerCredentials ? 'retailer_settings' : 'env_fallback';
        $attempts = [];

        if (! $phoneNumberId || ! $token) {
            $sendError = 'missing_whatsapp_credentials';
        } else {
            if ($credentialCandidates === []) {
                $credentialCandidates[] = [
                    'source' => $finalCredentialSource,
                    'phone_number_id' => $phoneNumberId,
                    'token' => $token,
                ];
            }

            foreach ($credentialCandidates as $candidate) {
                try {
                    $response = Http::withToken((string) $candidate['token'])
                        ->post("https://graph.facebook.com/v20.0/{$candidate['phone_number_id']}/messages", [
                            'messaging_product' => 'whatsapp',
                            'to' => $phone,
                            'type' => 'text',
                            'text' => [
                                'body' => $message,
                            ],
                        ])->throw();

                    $responsePayload = $response->json();
                    $externalId = data_get($responsePayload, 'messages.0.id');
                    $sendError = null;
                    $finalCredentialSource = (string) $candidate['source'];
                    $attempts[] = [
                        'source' => (string) $candidate['source'],
                        'success' => true,
                    ];
                    break;
                } catch (\Throwable $throwable) {
                    $sendError = $throwable->getMessage();
                    $attempts[] = [
                        'source' => (string) $candidate['source'],
                        'success' => false,
                        'error' => $sendError,
                    ];
                }
            }
        }

        $rawPayload = ['request_text' => $message];
        $rawPayload['credential_source'] = $finalCredentialSource;
        $rawPayload['credential_attempts'] = $attempts;
        if (is_array($responsePayload)) {
            $rawPayload['provider_response'] = $responsePayload;
        }
        if ($sendError !== null) {
            $rawPayload['send_error'] = $sendError;
            $providerCode = Arr::get($responsePayload, 'error.code');
            if ($providerCode !== null) {
                $rawPayload['provider_error_code'] = $providerCode;
            }
        }

        Message::query()->create([
            'retailer_id' => $retailerId,
            'customer_id' => $customerId,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'message_type' => 'text',
            'external_id' => $externalId,
            'phone' => $phone,
            'body' => $message,
            'raw_payload' => $rawPayload,
            'processed' => true,
        ]);

        if ($sendError !== null && $sendError !== 'missing_whatsapp_credentials') {
            throw new \RuntimeException('WhatsApp send failed: '.$sendError);
        }
    }
}
