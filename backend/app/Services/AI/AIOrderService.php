<?php

namespace App\Services\AI;

use App\Models\AiLog;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Product;
use App\Services\ProductMatchingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIOrderService
{
    public function __construct(private readonly ProductMatchingService $matchingService)
    {
    }

    public function parseMessage(Message $message, ?Customer $customer, ?array $customerContext = null): array
    {
        $text = trim((string) $message->body);

        if ($text !== '' && preg_match('/^\d+$/', $text)) {
            return $this->resolveOptionSelection($message, (int) $text);
        }

        if (str_contains(mb_strtolower($text), 'same as last')) {
            return $this->sameAsLastTime($message, $customer);
        }

        $response = $this->callLlm($message, $text, $customerContext);
        $action = $this->normalizeAction((string) Arr::get($response, 'action', 'add_to_cart'));
        $replyText = $this->extractReplyText($response);

        if ($action === 'track_order' || $action === 'show_catalog' || $action === 'help' || $action === 'none') {
            return [
                'action' => $action,
                'items' => [],
                'reply_text' => $replyText,
            ];
        }

        $items = [];
        foreach (Arr::get($response, 'items', []) as $rawItem) {
            $name = $this->cleanProductPhrase((string) Arr::get($rawItem, 'product', ''));
            $qty = max(1, (int) Arr::get($rawItem, 'qty', 1));

            if ($name === '') {
                continue;
            }

            $candidates = $this->matchingService->findCandidates($message->retailer_id, $name);

            if ($candidates->count() > 1) {
                Cache::put($this->optionCacheKey($message->retailer_id, $message->phone), [
                    'prompt' => $name,
                    'candidates' => $candidates->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'brand' => $p->brand,
                    ])->values()->all(),
                    'qty' => $qty,
                ], now()->addMinutes(15));

                return [
                    'action' => 'request_option',
                    'message' => $this->buildOptionMessage($candidates),
                    'reply_text' => $replyText,
                ];
            }

            $best = $candidates->first();
            if ($best) {
                $items[] = [
                    'product_id' => $best->id,
                    'qty' => $qty,
                ];
            }
        }

        return [
            'action' => 'add_to_cart',
            'items' => $items,
            'reply_text' => $replyText,
        ];
    }

    private function sameAsLastTime(Message $message, ?Customer $customer): array
    {
        if (! $customer) {
            return [
                'action' => 'none',
                'items' => [],
            ];
        }

        $lastOrder = $customer->orders()->with('items')->latest('id')->first();
        if (! $lastOrder) {
            return [
                'action' => 'none',
                'items' => [],
            ];
        }

        return [
            'action' => 'add_to_cart',
            'items' => $lastOrder->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'qty' => $item->quantity,
            ])->values()->all(),
            'reply_text' => 'Sure, adding the same items as your last order. ✅',
        ];
    }

    private function resolveOptionSelection(Message $message, int $option): array
    {
        $payload = Cache::get($this->optionCacheKey($message->retailer_id, $message->phone));

        if (! $payload) {
            return [
                'action' => 'none',
                'items' => [],
            ];
        }

        $candidate = Arr::get($payload, 'candidates.'.($option - 1));
        if (! $candidate) {
            return [
                'action' => 'request_option',
                'message' => $this->buildOptionMessage(collect(Arr::get($payload, 'candidates', []))),
            ];
        }

        Cache::forget($this->optionCacheKey($message->retailer_id, $message->phone));

        return [
            'action' => 'add_to_cart',
            'items' => [[
                'product_id' => Arr::get($candidate, 'id'),
                'qty' => (int) Arr::get($payload, 'qty', 1),
            ]],
            'reply_text' => 'Perfect, got it. I am adding that to your cart now. ✅',
        ];
    }

    private function callLlm(Message $message, string $text, ?array $customerContext = null): array
    {
        $start = microtime(true);
        $preferredLanguage = $this->detectLanguagePreference($text, $message);

        $retailerName = (string) ($message->retailer?->name ?: 'the store');
        $customerName = trim((string) ($message->customer?->name ?: ''));
        $conversationStep = $this->inferConversationStep($text);
        $history = $this->conversationHistory($message);
        $catalogSnippet = $this->catalogSnippet($message->retailer_id);

        $defaultSystem = "You are a friendly WhatsApp shopping assistant for {$retailerName}. "
            ."Help customers browse products, place orders, and track orders in a warm, human way. "
            ."\n\n"
            ."Language rules: Auto-detect customer language from first message and mirror it (English, Urdu, Roman Urdu, or mixed). "
            ."Do not switch language unless customer switches first. "
            ."If language is unclear, always default to English. "
            ."\n\n"
            ."Tone rules: friendly, calm, concise, natural. Avoid robotic or corporate phrases. "
            ."Keep replies short for WhatsApp and guide step-by-step. "
            ."\n\n"
            ."Business rules: never invent prices/availability, never ask for payment details, treat orders as COD/separate payment flow. "
            ."For complex complaints/refund disputes, route to support using action=help. "
            ."\n\n"
            ."If asked whether you are AI, reply that you are a smart assistant helping {$retailerName}. "
            ."\n\n"
            ."CRITICAL OUTPUT FORMAT: Return strict JSON object only, with keys action, items, and reply_text. "
            ."Allowed actions: add_to_cart, request_option, track_order, show_catalog, help, none. "
            ."For add_to_cart, items must be an array of objects with product (string) and qty (integer >= 1). "
            ."reply_text must be a short natural WhatsApp message (ideally 1-4 short lines, plain text, no markdown). "
            ."Use track_order for status questions, show_catalog for browse/menu/new-order intent, help for greetings/ambiguous/support.";

        $customSystem = trim((string) data_get($message->retailer?->settings, 'ai.system_prompt', ''));
        $system = $customSystem !== '' ? $customSystem : $defaultSystem;

        if (! empty($customerContext['summary'])) {
            $system .= "\n\nCustomer context:\n".(string) $customerContext['summary'];
        }

        $prompt = "retailer_name: {$retailerName}\n"
            ."customer_name: ".($customerName !== '' ? $customerName : 'unknown')."\n"
            ."preferred_language: {$preferredLanguage}\n"
            ."conversation_step: {$conversationStep}\n"
            ."catalog_snippet:\n{$catalogSnippet}\n"
            ."conversation_history:\n{$history}\n"
            ."customer_message: {$text}";

        Log::info('AI prompt assembled', [
            'message_id' => $message->id,
            'retailer_id' => $message->retailer_id,
            'customer_id' => $message->customer_id,
            'system_prompt' => $system,
            'user_prompt' => $prompt,
        ]);

        $provider = config('services.ai.provider', 'openai');
        $model = config('services.ai.model', 'gpt-4o-mini');

        try {
            $result = $this->fallbackIntent($text);

            if ($provider === 'openai' && config('services.ai.api_key')) {
                $apiResponse = Http::withToken(config('services.ai.api_key'))
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => $model,
                        'input' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => [
                            'type' => 'json_object',
                        ],
                    ]);

                $jsonText = data_get($apiResponse->json(), 'output.0.content.0.text');
                $decoded = json_decode((string) $jsonText, true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }

            $result['action'] = $this->normalizeAction((string) Arr::get($result, 'action', 'add_to_cart'));

            $this->log($message, $provider, $model, $prompt, $result, null, (int) ((microtime(true) - $start) * 1000));

            return $result;
        } catch (\Throwable $throwable) {
            $fallback = $this->fallbackIntent($text);

            $this->log(
                $message,
                $provider,
                $model,
                $prompt,
                $fallback,
                $throwable->getMessage(),
                (int) ((microtime(true) - $start) * 1000)
            );

            return $fallback;
        }
    }

    private function heuristicItems(string $text): array
    {
        $text = trim(mb_strtolower($text));
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/,|\band\b|\+/', $text) ?: [$text];

        return collect($parts)
            ->map(function (string $part) {
                $chunk = trim($part);
                preg_match('/^(?<qty>\d+)\s*(x\s*)?(?<name>.+)$/u', $chunk, $matches);

                if (empty($matches)) {
                    preg_match('/^(?<name>.+?)\s*(x\s*)?(?<qty>\d+)$/u', $chunk, $matches);
                }

                $rawName = trim((string) ($matches['name'] ?? $chunk));
                $cleanName = $this->cleanProductPhrase($rawName);

                return [
                    'product' => $cleanName,
                    'qty' => (int) ($matches['qty'] ?? 1),
                ];
            })
            ->filter(fn (array $item) => trim((string) ($item['product'] ?? '')) !== '')
            ->values()
            ->all();
    }

    private function normalizeAction(string $action): string
    {
        $normalized = mb_strtolower(trim($action));

        return match ($normalized) {
            'add_to_cart', 'request_option', 'track_order', 'show_catalog', 'help', 'none' => $normalized,
            'catalog', 'product_list', 'new_order', 'menu' => 'show_catalog',
            'track', 'tracking', 'order_status' => 'track_order',
            default => 'add_to_cart',
        };
    }

    private function fallbackIntent(string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        $lang = $this->detectLanguagePreference($text, null);
        $isUrdu = $lang === 'urdu';

        if ($normalized === '') {
            return ['action' => 'help', 'items' => [], 'reply_text' => $isUrdu
                ? 'Ji zaroor! Aap kya order karna chahte hain? 😊'
                : 'Sure! What would you like to order today? 😊'];
        }

        if (
            str_contains($normalized, 'track order')
            || str_contains($normalized, 'order status')
            || str_contains($normalized, 'track my order')
            || str_contains($normalized, 'where is my order')
        ) {
            return ['action' => 'track_order', 'items' => [], 'reply_text' => $isUrdu
                ? 'Bilkul! Main aap ka latest order status check karta hoon. 📦'
                : 'Sure! Let me check your latest order status for you. 📦'];
        }

        if (
            str_contains($normalized, 'new order')
            || str_contains($normalized, 'start order')
            || str_contains($normalized, 'product list')
            || str_contains($normalized, 'catalog')
            || str_contains($normalized, 'menu')
        ) {
            return ['action' => 'show_catalog', 'items' => [], 'reply_text' => $isUrdu
                ? 'Bohat acha choice! Yeh raha hamara available catalog. 🛍️'
                : 'Great choice! Here is our available catalog. 🛍️'];
        }

        if (in_array($normalized, ['hi', 'hello', 'hey', 'help', 'start'], true)) {
            return ['action' => 'help', 'items' => [], 'reply_text' => $isUrdu
                ? 'Assalam o Alaikum! 👋 Bilkul, main help karta/karti hoon.'
                : 'Hi! 👋 Sure, I can help you with your order.'];
        }

        if (
            str_contains($normalized, 'how are you')
            || str_contains($normalized, 'how are u')
            || str_contains($normalized, 'how r u')
            || str_contains($normalized, 'what can you do')
            || str_contains($normalized, 'who are you')
        ) {
            return ['action' => 'help', 'items' => [], 'reply_text' => $isUrdu
                ? 'Main theek hoon, shukriya! 😊 Main aap ki shopping mein help kar sakta/sakti hoon. NEW ORDER likhein ya product ka naam bhejein.'
                : 'I am doing great, thanks for asking! 😊 I can help you shop. Send NEW ORDER to see products, or send quantity + item name.'];
        }

        return [
            'action' => 'add_to_cart',
            'items' => $this->heuristicItems($text),
            'reply_text' => $isUrdu
                ? 'Great! Main isay aap ke cart mein add karta/karti hoon. ✅'
                : 'Great! Let me add that to your cart. ✅',
        ];
    }

    private function detectLanguagePreference(string $text, ?Message $message): string
    {
        $sample = mb_strtolower(trim($text));

        if ($sample === '' && $message) {
            $firstInbound = Message::query()
                ->where('retailer_id', $message->retailer_id)
                ->when(
                    $message->customer_id,
                    fn ($query) => $query->where('customer_id', $message->customer_id),
                    fn ($query) => $query->where('phone', $message->phone)
                )
                ->where('direction', 'in')
                ->orderBy('id')
                ->value('body');

            $sample = mb_strtolower(trim((string) $firstInbound));
        }

        if ($sample === '') {
            return 'english';
        }

        if (preg_match('/[\x{0600}-\x{06FF}]/u', $sample)) {
            return 'urdu';
        }

        $urduHints = [
            'kia', 'kya', 'kaise', 'kaisay', 'kesi', 'kesy', 'hal', 'haal', 'han', 'haan',
            'nahi', 'nahin', 'acha', 'achha', 'zaroor', 'ji', 'bhai', 'karna', 'karo', 'krdo', 'mera', 'meri',
        ];
        foreach ($urduHints as $hint) {
            if (preg_match('/\b'.preg_quote($hint, '/').'\b/u', $sample)) {
                return 'urdu';
            }
        }

        return 'english';
    }

    private function extractReplyText(array $response): ?string
    {
        $raw = trim((string) (
            Arr::get($response, 'reply_text')
            ?? Arr::get($response, 'message')
            ?? Arr::get($response, 'reply')
            ?? ''
        ));

        if ($raw === '') {
            return null;
        }

        return preg_replace("/\n{3,}/", "\n\n", $raw) ?: null;
    }

    private function cleanProductPhrase(string $name): string
    {
        $clean = mb_strtolower(trim($name));
        if ($clean === '') {
            return '';
        }

        $clean = preg_replace('/^[^\p{L}\p{N}]+/u', '', $clean) ?: $clean;
        $clean = preg_replace(
            '/^(i\s+want\s+to\s+order|i\s+want|want|please|pls|kindly|add|order|send|give\s+me|can\s+i\s+get|mujhe|krdo|kar\s+do)\s+/u',
            '',
            $clean
        ) ?: $clean;
        $clean = preg_replace('/\s+(please|pls|kindly)$/u', '', $clean) ?: $clean;

        return trim($clean);
    }

    private function buildOptionMessage($candidates): string
    {
        $lines = ['I found multiple options. Reply with a number:'];

        foreach ($candidates as $index => $candidate) {
            $brand = is_array($candidate) ? ($candidate['brand'] ?? '') : ($candidate->brand ?? '');
            $name = is_array($candidate) ? ($candidate['name'] ?? '') : ($candidate->name ?? '');
            $lines[] = ($index + 1).'. '.trim($brand.' '.$name);
        }

        return implode("\n", $lines);
    }

    private function optionCacheKey(?int $retailerId, string $phone): string
    {
        return 'wa:options:'.$retailerId.':'.$phone;
    }

    private function inferConversationStep(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        if (
            str_contains($normalized, 'track')
            || str_contains($normalized, 'status')
            || str_contains($normalized, 'where is my order')
        ) {
            return 'TRACKING';
        }

        if (
            str_contains($normalized, 'confirm')
            || str_contains($normalized, 'place order')
            || str_contains($normalized, 'checkout')
        ) {
            return 'CONFIRM';
        }

        if (
            str_contains($normalized, 'new order')
            || str_contains($normalized, 'catalog')
            || str_contains($normalized, 'menu')
            || str_contains($normalized, 'product list')
        ) {
            return 'BROWSE';
        }

        if ($normalized === '' || in_array($normalized, ['hi', 'hello', 'hey', 'start', 'help'], true)) {
            return 'WELCOME';
        }

        return 'CART';
    }

    private function conversationHistory(Message $message, int $limit = 6): string
    {
        $query = Message::query()
            ->whereNotNull('body')
            ->where('retailer_id', $message->retailer_id);

        if ($message->customer_id) {
            $query->where('customer_id', $message->customer_id);
        } else {
            $query->where('phone', $message->phone);
        }

        $rows = $query
            ->latest('id')
            ->limit($limit)
            ->get(['direction', 'body'])
            ->reverse()
            ->values();

        if ($rows->isEmpty()) {
            return 'none';
        }

        return $rows
            ->map(function (Message $row): string {
                $speaker = $row->direction === 'in' ? 'customer' : 'assistant';
                return $speaker.': '.trim((string) $row->body);
            })
            ->implode("\n");
    }

    private function catalogSnippet(?int $retailerId, int $limit = 8): string
    {
        if (! $retailerId) {
            return 'none';
        }

        $products = Product::query()
            ->where('retailer_id', $retailerId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->limit($limit)
            ->get(['name', 'brand', 'price']);

        if ($products->isEmpty()) {
            return 'none';
        }

        return $products
            ->map(function (Product $product): string {
                $label = trim(($product->brand ? $product->brand.' ' : '').$product->name);
                return $label.' - '.$product->price;
            })
            ->implode("\n");
    }

    private function log(Message $message, string $provider, string $model, string $prompt, array $response, ?string $error, int $latencyMs): void
    {
        AiLog::query()->create([
            'retailer_id' => $message->retailer_id,
            'customer_id' => $message->customer_id,
            'message_id' => $message->id,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'response' => $response,
            'normalized_output' => $response,
            'latency_ms' => $latencyMs,
            'success' => $error === null,
            'error' => $error,
        ]);
    }
}
