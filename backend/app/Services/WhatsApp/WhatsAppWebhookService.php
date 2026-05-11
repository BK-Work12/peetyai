<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessIncomingMessage;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Retailer;
use Illuminate\Support\Arr;

class WhatsAppWebhookService
{
    public function processInboundPayload(array $payload): void
    {
        $entries = Arr::get($payload, 'entry', []);
        $envPhoneNumberId = trim((string) config('services.whatsapp.phone_number_id'));

        foreach ($entries as $entry) {
            foreach (Arr::get($entry, 'changes', []) as $change) {
                $value = Arr::get($change, 'value', []);
                $phoneNumberId = Arr::get($value, 'metadata.phone_number_id');

                $retailer = $this->resolveRetailer($phoneNumberId, $envPhoneNumberId);
                $contactsByWaId = collect(Arr::get($value, 'contacts', []))
                    ->keyBy(fn ($contact) => (string) Arr::get($contact, 'wa_id'));

                foreach (Arr::get($value, 'messages', []) as $incoming) {
                    $phone = (string) Arr::get($incoming, 'from');
                    $type = (string) Arr::get($incoming, 'type', 'text');
                    $contact = $contactsByWaId->get($phone, []);

                    $body = Arr::get($incoming, 'text.body')
                        ?? Arr::get($incoming, 'voice.caption')
                        ?? Arr::get($incoming, 'image.caption');

                    $mediaUrl = Arr::get($incoming, $type.'.id');

                    $customer = null;
                    if ($retailer) {
                        $customer = Customer::query()->firstOrCreate(
                            [
                                'retailer_id' => $retailer->id,
                                'phone' => $phone,
                            ],
                            [
                                'name' => Arr::get($contact, 'profile.name'),
                            ],
                        );
                    }

                    $message = Message::query()->create([
                        'retailer_id' => $retailer?->id,
                        'customer_id' => $customer?->id,
                        'direction' => 'in',
                        'channel' => 'whatsapp',
                        'message_type' => $type,
                        'external_id' => Arr::get($incoming, 'id'),
                        'phone' => $phone,
                        'body' => $body,
                        'media_url' => $mediaUrl,
                        'raw_payload' => $incoming,
                    ]);

                    ProcessIncomingMessage::dispatch($message->id);
                }
            }
        }
    }

    private function resolveRetailer(?string $phoneNumberId, string $envPhoneNumberId): ?Retailer
    {
        $phoneNumberId = trim((string) $phoneNumberId);

        if ($phoneNumberId !== '') {
            $retailer = Retailer::query()
                ->where('settings->whatsapp->phone_number_id', $phoneNumberId)
                ->first();

            if ($retailer) {
                return $retailer;
            }
        }

        if ($phoneNumberId !== '' && $envPhoneNumberId !== '' && $phoneNumberId === $envPhoneNumberId) {
            // Env credentials are shared app-wide; if a single retailer exists, route inbound messages there.
            if (Retailer::query()->count() === 1) {
                return Retailer::query()->first();
            }
        }

        return null;
    }
}
