<?php

namespace App\Jobs;

use App\Jobs\ExtractCustomerMemories;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Services\AI\AIOrderService;
use App\Services\CartService;
use App\Services\Memory\CustomerContextService;
use App\Services\Memory\CustomerMemoryCommandService;
use App\Services\Memory\MemoryFeature;
use App\Services\OrderService;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(private readonly int $messageId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(
        AIOrderService $aiOrderService,
        CartService $cartService,
        CustomerContextService $customerContextService,
        CustomerMemoryCommandService $memoryCommandService,
        MemoryFeature $memoryFeature,
        OrderService $orderService,
        WhatsAppClient $whatsAppClient
    ): void
    {
        $message = Message::query()->with(['customer', 'retailer'])->find($this->messageId);

        if (! $message || $message->processed || ! $message->retailer_id) {
            return;
        }

        if ($this->handlePendingCheckoutProfile($message, $cartService, $orderService, $whatsAppClient)) {
            $message->forceFill(['processed' => true])->save();

            return;
        }

        $memoryEnabled = $memoryFeature->enabledForRetailer($message->retailer);
        $customerContext = null;

        if ($memoryEnabled) {
            $customerContext = $customerContextService->getCustomerContext($message->retailer_id, $message->phone);

            Log::info('Customer context assembled', [
                'message_id' => $message->id,
                'retailer_id' => $message->retailer_id,
                'customer_id' => $message->customer_id,
                'summary' => $customerContext['summary'] ?? null,
            ]);

            $commandResponse = $memoryCommandService->handleCommand(
                $message->retailer_id,
                $message->phone,
                (string) $message->body
            );

            if ($commandResponse !== null) {
                $this->safeSendText($whatsAppClient, $message, $commandResponse);
                $message->forceFill(['processed' => true])->save();

                return;
            }
        }

        if (mb_strtolower(trim((string) $message->body)) === 'confirm') {
            $cart = $cartService->getOrCreate($message->retailer_id, $message->phone, $message->customer);
            $cart->load('items.product');

            if ($cart->items->isEmpty()) {
                $this->safeSendText($whatsAppClient, $message, 'Your cart is empty. Send products first.');
            } else {
                $missingFields = $this->missingCheckoutFields($message->customer);
                if ($missingFields !== []) {
                    $this->startCheckoutProfileFlow($message, $missingFields, $whatsAppClient);
                    $message->forceFill(['processed' => true])->save();

                    return;
                }

                $order = $orderService->placeFromCart(
                    $cart,
                    $message->customer,
                    $this->buildOrderNote($message->customer),
                    true
                );
                $trackingHint = 'Reply TRACK ORDER anytime to check live status.';
                $this->safeSendText($whatsAppClient, $message, "Order #{$order->id} placed successfully. {$trackingHint}");
            }

            $message->forceFill(['processed' => true])->save();

            return;
        }

        $result = $aiOrderService->parseMessage($message, $message->customer, $customerContext);
        $aiReply = $this->extractAiReply($result);

        if (($result['action'] ?? null) === 'track_order') {
            $trackingText = $this->buildOrderTrackingMessage($message);
            $this->safeSendText($whatsAppClient, $message, $this->mergeReply($aiReply, $trackingText));
            $message->forceFill(['processed' => true])->save();

            return;
        }

        if (($result['action'] ?? null) === 'show_catalog') {
            $catalogText = $this->buildCatalogMessage($message->retailer_id);
            $this->safeSendText($whatsAppClient, $message, $this->mergeReply($aiReply, $catalogText));
            $message->forceFill(['processed' => true])->save();

            return;
        }

        if (($result['action'] ?? null) === 'help' || ($result['action'] ?? null) === 'none') {
            $this->safeSendText($whatsAppClient, $message, $aiReply ?: $this->buildHelpMessage($message));
            $message->forceFill(['processed' => true])->save();

            return;
        }

        if (($result['action'] ?? null) === 'request_option') {
            $optionText = (string) ($result['message'] ?? 'Please choose one option.');
            $this->safeSendText($whatsAppClient, $message, $this->mergeReply($aiReply, $optionText));
            $message->forceFill(['processed' => true])->save();

            return;
        }

        if (($result['action'] ?? null) === 'add_to_cart') {
            $items = $result['items'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $cart = $cartService->getOrCreate($message->retailer_id, $message->phone, $message->customer);
            $beforeTotals = $cartService->totals($cart->load('items.product'));
            $cart = $cartService->applyItems($cart, $items);

            $afterTotals = $cartService->totals($cart);
            $addedQty = max(0, (int) $afterTotals['items_count'] - (int) $beforeTotals['items_count']);

            if ($addedQty === 0) {
                $fallback = $this->buildNoMatchMessage($message->retailer_id);
                $this->safeSendText($whatsAppClient, $message, $this->mergeReply($aiReply, $fallback));
            } else {
                $fallback = "Added to cart. Items: {$afterTotals['items_count']}, subtotal: {$afterTotals['subtotal']}. Reply CONFIRM to place order.";
                $this->safeSendText(
                    $whatsAppClient,
                    $message,
                    $aiReply ?: $fallback
                );
            }
        } else {
            Log::info('Message processed with no actionable item', ['message_id' => $message->id]);
            $this->safeSendText($whatsAppClient, $message, $aiReply ?: $this->buildHelpMessage($message));
        }

        $message->forceFill(['processed' => true])->save();

        if ($memoryEnabled && $message->customer_id && $this->shouldExtractMemory($message)) {
            try {
                ExtractCustomerMemories::dispatch(
                    $message->retailer_id,
                    $message->customer_id,
                    $message->external_id
                )->onQueue('memory-layer');
            } catch (\Throwable $throwable) {
                Log::warning('Memory extraction dispatch failed', [
                    'message_id' => $message->id,
                    'retailer_id' => $message->retailer_id,
                    'customer_id' => $message->customer_id,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }

    private function shouldExtractMemory(Message $message): bool
    {
        $n = max(1, (int) config('memory.extraction_every_n_messages', 6));

        $messageCount = Message::query()
            ->where('retailer_id', $message->retailer_id)
            ->where('customer_id', $message->customer_id)
            ->where('direction', 'in')
            ->count();

        return $messageCount % $n === 0;
    }

    private function buildCatalogMessage(int $retailerId): string
    {
        $products = Product::query()
            ->where('retailer_id', $retailerId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->limit(12)
            ->get(['name', 'brand', 'price']);

        if ($products->isEmpty()) {
            return 'No active products found right now. Please check with the store admin.';
        }

        $lines = ['Available products:'];
        foreach ($products as $index => $product) {
            $label = trim(($product->brand ? $product->brand.' ' : '').$product->name);
            $line = ($index + 1).'. 🛒 '.$label.' - '.$product->price;
            $imageUrl = $this->extractProductImageUrl($product);
            if ($imageUrl) {
                $line .= "\n   🖼 {$imageUrl}";
            }
            $lines[] = $line;
        }

        $lines[] = 'Reply with quantity + name, e.g. "2 milk".';
        $lines[] = 'Reply TRACK ORDER to check your latest order status.';

        return implode("\n", $lines);
    }

    private function buildNoMatchMessage(int $retailerId): string
    {
        $catalog = $this->buildCatalogMessage($retailerId);

        return "I could not match those items to your store catalog.\n".$catalog;
    }

    private function safeSendText(WhatsAppClient $whatsAppClient, Message $message, string $text): void
    {
        try {
            $whatsAppClient->sendText(
                $message->phone,
                $text,
                $message->retailer_id,
                $message->customer_id,
            );
        } catch (\Throwable $throwable) {
            Log::warning('WhatsApp send failed', [
                'message_id' => $message->id,
                'retailer_id' => $message->retailer_id,
                'phone' => $message->phone,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function buildOrderTrackingMessage(Message $message): string
    {
        $order = Order::query()
            ->where('retailer_id', $message->retailer_id)
            ->when(
                $message->customer_id,
                fn ($query) => $query->where('customer_id', $message->customer_id),
                fn ($query) => $query->whereHas('customer', fn ($customerQuery) => $customerQuery->where('phone', $message->phone))
            )
            ->with('items')
            ->latest('id')
            ->first();

        if (! $order) {
            return 'No orders found for this number yet. Add items to cart and reply CONFIRM to place your first order.';
        }

        $status = ucfirst((string) $order->status->value);
        $itemSummary = $order->items->take(4)->map(fn ($item) => "- {$item->quantity} x {$item->product_name}")->implode("\n");
        if ($itemSummary === '') {
            $itemSummary = '- Items will appear soon';
        }

        return "Order #{$order->id} status: {$status}\nPlaced: {$order->placed_at?->format('Y-m-d H:i')}\nTotal: {$order->total}\nItems:\n{$itemSummary}";
    }

    private function buildHelpMessage(Message $message): string
    {
        return "Hi! 👋\nYou can send:\n- NEW ORDER (to see products)\n- 2 milk, 1 bread (to add items)\n- CONFIRM (to place order)\n- TRACK ORDER (to check status)";
    }

    private function missingCheckoutFields(?Customer $customer): array
    {
        if (! $customer) {
            return ['full_name', 'contact_number', 'address'];
        }

        $profile = Arr::get($customer->preferences ?? [], 'checkout_profile', []);

        $fullName = trim((string) ($customer->name ?: Arr::get($profile, 'full_name', '')));
        $contactNumber = trim((string) Arr::get($profile, 'contact_number', ''));
        $address = trim((string) Arr::get($profile, 'address', ''));

        $missing = [];
        if ($fullName === '') {
            $missing[] = 'full_name';
        }
        if ($contactNumber === '') {
            $missing[] = 'contact_number';
        }
        if ($address === '') {
            $missing[] = 'address';
        }

        return $missing;
    }

    private function startCheckoutProfileFlow(Message $message, array $missingFields, WhatsAppClient $whatsAppClient): void
    {
        $firstField = $missingFields[0] ?? 'full_name';

        Cache::put($this->checkoutProfileCacheKey($message), [
            'pending_fields' => $missingFields,
            'field' => $firstField,
            'purpose' => 'confirm_checkout',
        ], now()->addMinutes(30));

        $this->safeSendText($whatsAppClient, $message, $this->profilePrompt($firstField));
    }

    private function handlePendingCheckoutProfile(
        Message $message,
        CartService $cartService,
        OrderService $orderService,
        WhatsAppClient $whatsAppClient
    ): bool {
        $pending = Cache::get($this->checkoutProfileCacheKey($message));
        if (! is_array($pending)) {
            return false;
        }

        if (! $message->customer) {
            Cache::forget($this->checkoutProfileCacheKey($message));

            return false;
        }

        $field = (string) ($pending['field'] ?? '');
        $value = trim((string) $message->body);
        if ($value === '') {
            $this->safeSendText($whatsAppClient, $message, 'Please send a valid value. '.$this->profilePrompt($field));

            return true;
        }

        $this->saveCheckoutField($message->customer, $field, $value);

        $remaining = array_values(array_filter(
            (array) ($pending['pending_fields'] ?? []),
            fn ($pendingField) => $pendingField !== $field
        ));

        if ($remaining !== []) {
            $nextField = (string) $remaining[0];
            Cache::put($this->checkoutProfileCacheKey($message), [
                'pending_fields' => $remaining,
                'field' => $nextField,
                'purpose' => 'confirm_checkout',
            ], now()->addMinutes(30));

            $this->safeSendText($whatsAppClient, $message, 'Saved. '.$this->profilePrompt($nextField));

            return true;
        }

        Cache::forget($this->checkoutProfileCacheKey($message));

        $cart = $cartService->getOrCreate($message->retailer_id, $message->phone, $message->customer);
        $cart->load('items.product');

        if ($cart->items->isEmpty()) {
            $this->safeSendText($whatsAppClient, $message, 'Profile saved. Your cart is empty now, please add products again.');

            return true;
        }

        $order = $orderService->placeFromCart(
            $cart,
            $message->customer,
            $this->buildOrderNote($message->customer),
            true
        );

        $this->safeSendText(
            $whatsAppClient,
            $message,
            "Thanks. Order #{$order->id} placed successfully. Reply TRACK ORDER to get status anytime."
        );

        return true;
    }

    private function saveCheckoutField(Customer $customer, string $field, string $value): void
    {
        $preferences = $customer->preferences ?? [];
        $profile = Arr::get($preferences, 'checkout_profile', []);

        if ($field === 'full_name') {
            $customer->name = $value;
            $profile['full_name'] = $value;
        } elseif ($field === 'contact_number') {
            $profile['contact_number'] = $value;
        } elseif ($field === 'address') {
            $profile['address'] = $value;
        }

        $preferences['checkout_profile'] = $profile;
        $customer->preferences = $preferences;
        $customer->save();
    }

    private function profilePrompt(string $field): string
    {
        return match ($field) {
            'full_name' => 'Please share your full name for delivery.',
            'contact_number' => 'Please share your contact number.',
            'address' => 'Please share your full delivery address.',
            default => 'Please share the required delivery details.',
        };
    }

    private function buildOrderNote(?Customer $customer): ?string
    {
        if (! $customer) {
            return null;
        }

        $profile = Arr::get($customer->preferences ?? [], 'checkout_profile', []);
        $fullName = trim((string) ($customer->name ?: Arr::get($profile, 'full_name', '')));
        $contactNumber = trim((string) Arr::get($profile, 'contact_number', ''));
        $address = trim((string) Arr::get($profile, 'address', ''));

        $parts = [];
        if ($fullName !== '') {
            $parts[] = 'Customer: '.$fullName;
        }
        if ($contactNumber !== '') {
            $parts[] = 'Contact: '.$contactNumber;
        }
        if ($address !== '') {
            $parts[] = 'Address: '.$address;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' | ', $parts);
    }

    private function checkoutProfileCacheKey(Message $message): string
    {
        return 'wa:checkout-profile:'.$message->retailer_id.':'.$message->phone;
    }

    private function extractProductImageUrl(Product $product): ?string
    {
        $meta = $product->meta ?? [];
        if (! is_array($meta)) {
            return null;
        }

        $candidates = [
            Arr::get($meta, 'image_url'),
            Arr::get($meta, 'image'),
            Arr::get($meta, 'photo_url'),
            Arr::get($meta, 'thumbnail'),
            Arr::get($meta, 'icon_url'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '' && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))) {
                return $value;
            }
        }

        return null;
    }

    private function extractAiReply(array $result): ?string
    {
        $raw = trim((string) (
            Arr::get($result, 'reply_text')
            ?? Arr::get($result, 'message')
            ?? ''
        ));

        if ($raw === '') {
            return null;
        }

        return preg_replace("/\n{3,}/", "\n\n", $raw) ?: null;
    }

    private function mergeReply(?string $prefix, string $fallback): string
    {
        $prefix = trim((string) $prefix);
        if ($prefix === '') {
            return $fallback;
        }

        if (str_contains(mb_strtolower($prefix), mb_strtolower(trim($fallback)))) {
            return $prefix;
        }

        return $prefix."\n\n".$fallback;
    }
}
