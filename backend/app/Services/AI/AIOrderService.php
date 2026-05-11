<?php

namespace App\Services\AI;

use App\Models\AiLog;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Product;
use App\Services\CartService;
use App\Services\ProductMatchingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIOrderService
{
    public function __construct(
        private readonly ProductMatchingService $matchingService,
        private readonly CartService $cartService,
    ) {
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
        $catalogSnippet = $this->catalogSnippet($message->retailer_id);

        // Build cart state for context
        $cartLines = 'Cart is empty.';
        if ($message->retailer_id) {
            try {
                $cart = $this->cartService->getOrCreate($message->retailer_id, $message->phone, $message->customer);
                $cart->load('items.product');
                if ($cart->items->isNotEmpty()) {
                    $totals = $this->cartService->totals($cart);
                    $cartLines = $cart->items->map(
                        fn ($item) => '- '.$item->quantity.' x '.($item->product?->name ?? 'item').' @ '.$item->price
                    )->implode("\n");
                    $cartLines .= "\nSubtotal: {$totals['subtotal']}";
                }
            } catch (\Throwable) {
                // non-fatal — proceed without cart state
            }
        }

        $customSystem = trim((string) data_get($message->retailer?->settings, 'ai.system_prompt', ''));
        $system = $customSystem !== ''
            ? $customSystem
            : $this->buildSystemPrompt($retailerName, $catalogSnippet, $cartLines, $customerContext, $preferredLanguage, $customerName);

        // Pass conversation history as real OpenAI messages for better context
        $historyMessages = $this->conversationHistoryMessages($message);
        $currentUserContent = $text;

        // Compact log string for storage
        $promptLog = "[system prompt]\n{$system}\n\n[history: ".count($historyMessages)." messages]\n[user]: {$text}";

        Log::info('AI prompt assembled', [
            'message_id' => $message->id,
            'retailer_id' => $message->retailer_id,
            'customer_id' => $message->customer_id,
            'history_count' => count($historyMessages),
            'user_message' => $text,
        ]);

        $provider = config('services.ai.provider', 'openai');
        $model = config('services.ai.model', 'gpt-4o-mini');

        try {
            $result = $this->fallbackIntent($text);

            if ($provider === 'openai' && config('services.ai.api_key')) {
                $inputMessages = [
                    ['role' => 'system', 'content' => $system],
                    ...$historyMessages,
                    ['role' => 'user', 'content' => $currentUserContent],
                ];

                $apiResponse = Http::withToken(config('services.ai.api_key'))
                    ->timeout(15)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => $model,
                        'input' => $inputMessages,
                        'response_format' => ['type' => 'json_object'],
                    ]);

                $jsonText = data_get($apiResponse->json(), 'output.0.content.0.text');
                $decoded = json_decode((string) $jsonText, true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }

            $result['action'] = $this->normalizeAction((string) Arr::get($result, 'action', 'help'));

            $this->log($message, $provider, $model, $promptLog, $result, null, (int) ((microtime(true) - $start) * 1000));

            return $result;
        } catch (\Throwable $throwable) {
            $fallback = $this->fallbackIntent($text);

            $this->log(
                $message,
                $provider,
                $model,
                $promptLog,
                $fallback,
                $throwable->getMessage(),
                (int) ((microtime(true) - $start) * 1000)
            );

            return $fallback;
        }
    }

    private function buildSystemPrompt(
        string $retailerName,
        string $catalogSnippet,
        string $cartLines,
        ?array $customerContext,
        string $preferredLanguage,
        string $customerName
    ): string {
        $nameHint = $customerName !== '' ? "The customer's name is {$customerName}." : '';

        $system = <<<PROMPT
You are a WhatsApp shopping assistant for {$retailerName}. Help customers browse products, add to cart, and place orders through natural conversation.
{$nameHint}

## Language
Preferred: {$preferredLanguage}. Mirror the customer's language in every reply (English / Urdu / Roman Urdu). Never switch language unless the customer switches first.

## Current Cart
{$cartLines}

## Product Catalog
{$catalogSnippet}

## YOUR RESPONSE FORMAT
Always reply with a single JSON object with exactly these three keys:
- "action": one of the allowed actions below (string)
- "items": array of {"product": "name", "qty": number} — only populated for add_to_cart
- "reply_text": your WhatsApp reply — PLAIN TEXT ONLY, 1–4 short lines, NO asterisks, NO markdown, NO bullet symbols

## ACTIONS

**add_to_cart**
When: customer specifies a product and quantity to order.
Examples: "2 milk", "ek bread chahiye", "olpers milk x3", "send 1 eggs and 2 juice"
Rules: items must list each product + qty using the EXACT product name from the catalog. reply_text confirms what was added and current cart total.

**show_catalog**
When: customer wants to browse OR expresses purchase intent WITHOUT naming a specific product.
Examples: "what do you have", "show products", "i want to order", "place order", "new order", "menu", "kya milta hai", "kuch order karna hai"
Rules: items = []. reply_text invites them to pick.

**track_order**
When: customer asks about delivery or order status.
Examples: "track order", "order status", "where is my order", "delivery kab hoga", "mera order kahan hai"
Rules: items = []. reply_text is a brief acknowledgement.

**help**
When: greetings, introductions, confusion, support, or anything that is not an order.
Examples: "hi", "hello", "who are you", "what can you do", "help", "Assalam o Alaikum"
Rules: items = []. reply_text gives warm greeting + one-line instruction.

**none**
When: customer sends a filler acknowledgement with no actionable content.
Examples: "ok", "okay", "thanks", "noted", "got it", "sure", "received", "thik hai", "acha"
Rules: items = []. reply_text is a short friendly reply + gentle nudge toward next step.

## STRICT RULES
1. NEVER invent products — only use items that exist in the Product Catalog above.
2. NEVER ask for or mention payment details, card numbers, or bank accounts. Payment is Cash on Delivery.
3. If the message could mean either an order OR something else, prefer show_catalog over add_to_cart.
4. If customer says "confirm", "yes confirm", "order kar do" — use show_catalog (the system handles order placement separately).
5. reply_text MUST be plain text — absolutely no *, #, -, or any markdown.
6. Keep reply_text SHORT — this is WhatsApp, not email. Max 4 lines.
7. Never say "I am processing your request", "How may I assist you", or any robotic phrase.
8. Be natural, warm, and concise — like a helpful store assistant on WhatsApp.
PROMPT;

        if (! empty($customerContext['summary'])) {
            $system .= "\n\n## Customer History\n".(string) $customerContext['summary'];
        }

        return $system;
    }

    private function conversationHistoryMessages(Message $message, int $limit = 10): array
    {
        $query = Message::query()
            ->whereNotNull('body')
            ->where('retailer_id', $message->retailer_id)
            ->where('id', '<', $message->id);

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

        return $rows
            ->map(fn (Message $row): array => [
                'role' => $row->direction === 'in' ? 'user' : 'assistant',
                'content' => trim((string) $row->body),
            ])
            ->filter(fn (array $m): bool => $m['content'] !== '')
            ->values()
            ->all();
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
            'catalog', 'product_list', 'new_order', 'menu', 'browse' => 'show_catalog',
            'track', 'tracking', 'order_status', 'order_track' => 'track_order',
            'greet', 'greeting', 'welcome', 'support', 'unknown', 'other' => 'help',
            default => 'help', // safer fallback — never silently add unknown text to cart
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

        if (in_array($normalized, ['hi', 'hello', 'hey', 'help', 'start', 'hii', 'helo'], true)) {
            return ['action' => 'help', 'items' => [], 'reply_text' => $isUrdu
                ? 'Assalam o Alaikum! 👋 Bilkul, main help karta/karti hoon.'
                : 'Hi! 👋 Sure, I can help you with your order.'];
        }

        // Acknowledgement words — not an order, just a filler reply
        if (in_array($normalized, ['ok', 'okay', 'k', 'yes', 'yeah', 'yep', 'sure', 'got it', 'thanks', 'thank you', 'thx', 'ty', 'noted', 'fine', 'great', 'good'], true)) {
            return ['action' => 'none', 'items' => [], 'reply_text' => $isUrdu
                ? 'Ji! Kuch aur chahiye? Agar order karna ho to quantity + naam likhein jaise "2 milk". 😊'
                : 'Got it! 😊 Anything else? To order, just type quantity + item like "2 milk".'];
        }

        // Purchase intent — show catalog so customer can pick
        if (
            str_contains($normalized, 'want to order')
            || str_contains($normalized, 'want to place')
            || str_contains($normalized, 'place order')
            || str_contains($normalized, 'place an order')
            || str_contains($normalized, 'i want to order')
            || str_contains($normalized, 'i want order')
            || str_contains($normalized, 'order karna')
            || str_contains($normalized, 'order chahiye')
            || str_contains($normalized, 'order please')
            || str_contains($normalized, 'mujhe order')
        ) {
            return ['action' => 'show_catalog', 'items' => [], 'reply_text' => $isUrdu
                ? 'Bohat acha! Yeh raha hamara catalog. Kya lena chahte hain? 🛍️'
                : 'Sure! Here is what we have. What would you like to order? 🛍️'];
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
