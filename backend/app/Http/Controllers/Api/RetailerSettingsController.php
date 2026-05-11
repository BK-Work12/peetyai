<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Retailer;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailerSettingsController extends Controller
{
    public function show(Request $request, Retailer $retailer, WhatsAppClient $whatsAppClient): JsonResponse
    {
        $this->authorizeRetailerAccess($request, $retailer);

        return response()->json([
            'retailer' => $retailer->only(['id', 'name', 'slug', 'phone', 'email', 'address', 'delivery_radius_km', 'commission_rate', 'active']),
            'settings' => $retailer->settings ?? [],
            'whatsapp_status' => $this->buildWhatsAppStatus($retailer, $whatsAppClient),
        ]);
    }

    public function update(Request $request, Retailer $retailer): JsonResponse
    {
        $this->authorizeRetailerAccess($request, $retailer);

        $validated = $request->validate([
            'name'                => ['sometimes', 'string', 'max:120'],
            'phone'               => ['sometimes', 'string', 'max:30'],
            'email'               => ['sometimes', 'email', 'max:120'],
            'address'             => ['sometimes', 'string', 'max:300'],
            'delivery_radius_km'  => ['sometimes', 'numeric', 'min:0', 'max:500'],

            // WhatsApp
            'settings.whatsapp.phone_number_id'     => ['sometimes', 'string', 'max:80'],
            'settings.whatsapp.access_token'        => ['sometimes', 'string', 'max:512'],
            'settings.whatsapp.verify_token'        => ['sometimes', 'string', 'max:128'],
            'settings.whatsapp.business_account_id' => ['sometimes', 'string', 'max:80'],

            // AI
            'settings.ai.openai_api_key' => ['sometimes', 'string', 'max:256'],
            'settings.ai.model'          => ['sometimes', 'string', 'in:gpt-4o,gpt-4o-mini,gpt-4-turbo,gpt-3.5-turbo'],
            'settings.ai.temperature'    => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'settings.ai.system_prompt'  => ['sometimes', 'string', 'max:5000'],
            'settings.ai.memory_layer_enabled' => ['sometimes', 'boolean'],

            // Notifications
            'settings.notifications.email_on_new_order' => ['sometimes', 'boolean'],
            'settings.notifications.email_on_low_stock' => ['sometimes', 'boolean'],
            'settings.notifications.low_stock_threshold' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Merge top-level fields
        $retailerFields = collect($validated)->except('settings')->toArray();
        if (! empty($retailerFields)) {
            $retailer->update($retailerFields);
        }

        // Deep merge settings
        if (isset($validated['settings'])) {
            $current = $retailer->settings ?? [];
            foreach ($validated['settings'] as $group => $values) {
                $current[$group] = array_merge($current[$group] ?? [], $values);
            }
            $retailer->update(['settings' => $current]);
        }

        $retailer->refresh();

        return response()->json([
            'message'  => 'Settings saved.',
            'retailer' => $retailer->only(['id', 'name', 'slug', 'phone', 'email', 'address', 'delivery_radius_km', 'commission_rate', 'active']),
            'settings' => $retailer->settings ?? [],
        ]);
    }

    public function testWhatsApp(Request $request, Retailer $retailer, WhatsAppClient $whatsAppClient): JsonResponse
    {
        $this->authorizeRetailerAccess($request, $retailer);

        return response()->json([
            'whatsapp_status' => $this->buildWhatsAppStatus($retailer, $whatsAppClient, true),
        ]);
    }

    private function authorizeRetailerAccess(Request $request, Retailer $retailer): void
    {
        $user = $request->user();

        if ($user->role->value !== 'owner' && $user->retailer_id !== $retailer->id) {
            abort(403, 'Forbidden');
        }
    }

    private function buildWhatsAppStatus(Retailer $retailer, WhatsAppClient $whatsAppClient, bool $forceTest = false): array
    {
        $inspection = $whatsAppClient->inspectCredentials($retailer->id);
        $latestFailure = Message::query()
            ->where('retailer_id', $retailer->id)
            ->where('direction', 'out')
            ->whereNotNull('raw_payload')
            ->latest('id')
            ->get(['id', 'raw_payload', 'created_at'])
            ->first(function (Message $message) {
                return data_get($message->raw_payload, 'send_error') !== null;
            });
        $latestSuccess = Message::query()
            ->where('retailer_id', $retailer->id)
            ->where('direction', 'out')
            ->whereNotNull('external_id')
            ->latest('id')
            ->first(['id', 'created_at']);

        $status = [
            'configured' => (bool) $inspection['configured'],
            'default_source' => (string) $inspection['default_source'],
            'valid' => false,
            'message' => $inspection['configured'] ? 'Connection not tested yet.' : 'Missing WhatsApp credentials.',
            'credential_source' => (string) $inspection['default_source'],
            'last_failure' => $latestFailure ? [
                'message_id' => $latestFailure->id,
                'credential_source' => (string) data_get($latestFailure->raw_payload, 'credential_source', 'unknown'),
                'credential_attempts' => data_get($latestFailure->raw_payload, 'credential_attempts', []),
                'send_error' => (string) data_get($latestFailure->raw_payload, 'send_error', ''),
                'provider_error_code' => data_get($latestFailure->raw_payload, 'provider_error_code'),
                'created_at' => $latestFailure->created_at,
            ] : null,
        ];

        if ($forceTest || $inspection['configured']) {
            $live = $whatsAppClient->testConnection($retailer->id);
            $status = array_merge($status, $live);
        }

        $latestFailureId = $latestFailure?->id ?? 0;
        $latestSuccessId = $latestSuccess?->id ?? 0;
        $latestFailureError = (string) data_get($latestFailure?->raw_payload, 'send_error', '');
        $hasRecentAuthFailure = $latestFailureId > $latestSuccessId
            && ($latestFailureError !== '')
            && (
                str_contains($latestFailureError, 'Authentication Error')
                || str_contains($latestFailureError, 'OAuthException')
                || str_contains($latestFailureError, 'status code 401')
            );

        if ($hasRecentAuthFailure) {
            $status['valid'] = false;
            $status['message'] = 'Token can read WhatsApp metadata, but real message sending is failing with authentication error.';
        }

        return $status;
    }
}
