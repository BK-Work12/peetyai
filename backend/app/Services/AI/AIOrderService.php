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

class AIOrderService
{
    public function __construct(
        private readonly ProductMatchingService $matchingService,
        private readonly CartService $cartService,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public entry point
    // ─────────────────────────────────────────────────────────────────────────

    public function parseMessage(Message $message, ?Customer $customer, ?array $customerContext = null): array
    {
        $text = trim((string) $message->body);

        if ($text !== '' && preg_match('/^\d+$/', $text)) {
            return $this->resolveOptionSelection($message, (int) $text);
        }

        if (str_contains(mb_strtolower($text), 'same as last')) {
            return $this->sameAsLastTime($message, $customer);
        }

        return $this->runAgent($message, $text, $customerContext);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Core agent
    // ─────────────────────────────────────────────────────────────────────────

    private function runAgent(Message $message, string $text, ?array $customerContext): array
    {
        $start        = microtime(true);
        $retailerName = (string) ($message->retailer?->name ?: 'the store');
        $customerName = trim((string) ($message->customer?->name ?: ''));
        $language     = $this->detectLanguage($text, $message);
        $catalog      = $this->buildCatalogContext($message->retailer_id);
        $cartSummary  = $this->buildCartSummary($message);
        $history      = $this->buildHistoryMessages($message);

        $customSystem = trim((string) data_get($message->retailer?->settings, 'ai.system_prompt', ''));
        $system       = $customSystem !== ''
            ? $customSystem
            : $this->buildSystemPrompt($retailerName, $catalog, $cartSummary, $language, $customerName, $customerContext);

        $provider  = config('services.ai.provider', 'openai');
        $model     = config('services.ai.model', 'gpt-4.1-mini');
        $promptLog = "[lang:{$language}] [cart:{$cartSummary}] {$text}";

        try {
            $result = $this->heuristicFallback($text, $language);

            if ($provider === 'openai' && config('services.ai.api_key')) {
                $apiResponse = Http::withToken(config('services.ai.api_key'))
                    ->timeout(20)
                    ->post('https://api.openai.com/v1/responses', [
                        'model'           => $model,
                        'input'           => [
                            ['role' => 'system', 'content' => $system],
                            ...$history,
                            ['role' => 'user', 'content' => $text],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ]);

                $jsonText = data_get($apiResponse->json(), 'output.0.content.0.text');
                $decoded  = json_decode((string) $jsonText, true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }

            $result['action'] = $this->normalizeAction((string) Arr::get($result, 'action', 'help'));

            if ($result['action'] === 'add_to_cart') {
                $result = $this->resolveCartItems($result, $message);
            }

            $this->aiLog($message, $provider, $model, $promptLog, $result, null, (int) ((microtime(true) - $start) * 1000));

            return $result;

        } catch (\Throwable $e) {
            $fallback = $this->heuristicFallback($text, $language);
            $this->aiLog($message, $provider, $model, $promptLog, $fallback, $e->getMessage(), (int) ((microtime(true) - $start) * 1000));

            return $fallback;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // System prompt
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSystemPrompt(
        string $retailerName,
        string $catalog,
        string $cartSummary,
        string $language,
        string $customerName,
        ?array $customerContext
    ): string {
        $nameHint = $customerName !== '' ? "Customer name: {$customerName}." : '';

        $langInstruction = $language === 'arabic'
            ? 'Reply ONLY in Arabic. Use natural, friendly Arabic as spoken in WhatsApp.'
            : 'Reply in English. Keep it warm and conversational.';

        $system = <<<SYSTEM
You are a smart, friendly WhatsApp shopping assistant for {$retailerName}. You talk like a helpful human store assistant — warm, natural, concise. Never robotic.
{$nameHint}

## Language
{$langInstruction}
Always match the customer's language exactly. If they write in Arabic, reply in Arabic. If English, reply in English. Never switch unless they do.

## Store Catalog
{$catalog}

## Customer's Current Cart
{$cartSummary}

## Your Job
Guide the customer naturally: browsing -> adding items -> confirming order. Use the full conversation history above for context.

## Response Format
ALWAYS return a single JSON object with exactly these keys:

{
  "action": "<action>",
  "items": [{"product": "<exact name from catalog>", "qty": <number>}],
  "reply_text": "<your message to customer>"
}

## Allowed Actions

**add_to_cart**
When customer names a specific product + quantity.
Examples: "2 milk", "3 eggs and 1 juice", "علبتين حليب", "اريد لتر عصير"
items must use the EXACT product name from the catalog.
reply_text: confirm what was added + current cart total.

**show_catalog**
When customer wants to browse OR expresses buying intent without naming a product.
Examples: "what do you have", "show menu", "i want to order", "كيف اطلب", "اريد اطلب شي"
items = []. reply_text: briefly mention 2-3 items and invite them to pick.

**confirm_order**
When customer clearly wants to finalize/place the order.
Examples: "confirm", "place order", "yes confirm", "تاكيد الطلب", "اكد الطلب", "checkout"
items = []. reply_text: tell them you are placing the order now.

**track_order**
When customer asks about delivery or order status.
Examples: "track order", "where is my order", "اين طلبي", "متى يوصل"
items = []. reply_text: brief acknowledgement.

**help**
When: greeting, confusion, general question, or anything unrelated to ordering.
Examples: "hi", "hello", "مرحبا", "اهلا", "who are you", "what can you do"
items = []. reply_text: warm greeting + one helpful instruction.

**none**
When: filler acknowledgement with no action needed.
Examples: "ok", "thanks", "تمام", "شكرا", "okay", "noted"
items = []. reply_text: short friendly reply + nudge toward next step.

## Rules
1. NEVER invent products not in the catalog above.
2. NEVER mention payment details. All orders are Cash on Delivery.
3. reply_text is plain text ONLY — no *, no #, no markdown, no bullet points.
4. Keep reply_text SHORT — max 4 lines. This is WhatsApp.
5. Be natural and warm. Avoid "I am processing", "How may I assist", "Certainly!".
6. If cart is empty and customer says "confirm" — use show_catalog and ask them to add items first.
7. If cart has items and customer says "confirm" — use confirm_order.
SYSTEM;

        if (! empty($customerContext['summary'])) {
            $system .= "\n\n## Customer History\n" . (string) $customerContext['summary'];
        }

        return $system;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Context builders
    // ─────────────────────────────────────────────────────────────────────────

    private function buildCatalogContext(?int $retailerId): string
    {
        if (! $retailerId) {
            return 'No products available.';
        }

        $products = Product::query()
            ->where('retailer_id', $retailerId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'brand', 'price']);

        if ($products->isEmpty()) {
            return 'No products available.';
        }

        return $products->map(function (Product $p): string {
            $label = trim(($p->brand ? $p->brand . ' ' : '') . $p->name);
            return "{$label} - price: {$p->price}";
        })->implode("\n");
    }

    private function buildCartSummary(Message $message): string
    {
        if (! $message->retailer_id) {
            return 'empty';
        }

        try {
            $cart = $this->cartService->getOrCreate($message->retailer_id, $message->phone, $message->customer);
            $cart->load('items.product');

            if ($cart->items->isEmpty()) {
                return 'empty';
            }

            $totals = $this->cartService->totals($cart);
            $lines  = $cart->items->map(fn ($item) =>
                "{$item->quantity}x " . ($item->product?->name ?? 'item') . " @ {$item->price}"
            )->implode(', ');

            return "{$lines} | Subtotal: {$totals['subtotal']}";
        } catch (\Throwable) {
            return 'empty';
        }
    }

    private function buildHistoryMessages(Message $message, int $limit = 12): array
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

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['direction', 'body'])
            ->reverse()
            ->values()
            ->map(fn (Message $row): array => [
                'role'    => $row->direction === 'in' ? 'user' : 'assistant',
                'content' => trim((string) $row->body),
            ])
            ->filter(fn (array $m): bool => $m['content'] !== '')
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resolve product names -> IDs
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveCartItems(array $result, Message $message): array
    {
        $rawItems = is_array(Arr::get($result, 'items')) ? $result['items'] : [];
        $resolved = [];

        foreach ($rawItems as $rawItem) {
            $name = $this->cleanProductPhrase((string) Arr::get($rawItem, 'product', ''));
            $qty  = max(1, (int) Arr::get($rawItem, 'qty', 1));

            if ($name === '') {
                continue;
            }

            $candidates = $this->matchingService->findCandidates($message->retailer_id, $name);

            if ($candidates->count() > 1) {
                Cache::put($this->optionCacheKey($message->retailer_id, $message->phone), [
                    'prompt'     => $name,
                    'candidates' => $candidates->map(fn ($p) => [
                        'id' => $p->id, 'name' => $p->name, 'brand' => $p->brand,
                    ])->values()->all(),
                    'qty' => $qty,
                ], now()->addMinutes(15));

                $options = $candidates->map(fn ($p, $i) =>
                    ($i + 1) . '. ' . trim(($p->brand ? $p->brand . ' ' : '') . $p->name)
                )->implode("\n");

                return [
                    'action'  => 'request_option',
                    'items'   => [],
                    'message' => "I found a few options. Which one?\n{$options}",
                ];
            }

            $best = $candidates->first();
            if ($best) {
                $resolved[] = ['product_id' => $best->id, 'qty' => $qty];
            }
        }

        $result['items'] = $resolved;

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Disambiguation
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveOptionSelection(Message $message, int $option): array
    {
        $payload = Cache::get($this->optionCacheKey($message->retailer_id, $message->phone));

        if (! $payload) {
            return ['action' => 'help', 'items' => [], 'reply_text' => 'What would you like to order?'];
        }

        $candidate = Arr::get($payload, 'candidates.' . ($option - 1));
        if (! $candidate) {
            $opts = collect(Arr::get($payload, 'candidates', []))
                ->map(fn ($c, $i) => ($i + 1) . '. ' . trim(($c['brand'] ?? '') . ' ' . ($c['name'] ?? '')))
                ->implode("\n");

            return ['action' => 'request_option', 'items' => [], 'message' => "Please reply with a valid number:\n{$opts}"];
        }

        Cache::forget($this->optionCacheKey($message->retailer_id, $message->phone));

        return [
            'action'     => 'add_to_cart',
            'items'      => [['product_id' => Arr::get($candidate, 'id'), 'qty' => (int) Arr::get($payload, 'qty', 1)]],
            'reply_text' => 'Got it! Adding that to your cart.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Same-as-last shortcut
    // ─────────────────────────────────────────────────────────────────────────

    private function sameAsLastTime(Message $message, ?Customer $customer): array
    {
        if (! $customer) {
            return ['action' => 'help', 'items' => [], 'reply_text' => 'I could not find your previous order. What would you like to order?'];
        }

        $lastOrder = $customer->orders()->with('items')->latest('id')->first();
        if (! $lastOrder) {
            return ['action' => 'help', 'items' => [], 'reply_text' => 'You have no previous orders yet. What would you like to order?'];
        }

        return [
            'action'     => 'add_to_cart',
            'items'      => $lastOrder->items->map(fn ($item) => [
                'product_id' => $item->product_id, 'qty' => $item->quantity,
            ])->values()->all(),
            'reply_text' => 'Sure! Adding the same items as your last order.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Heuristic fallback (used when OpenAI unavailable or errors)
    // ─────────────────────────────────────────────────────────────────────────

    private function heuristicFallback(string $text, string $language): array
    {
        $n    = mb_strtolower(trim($text));
        $isAr = $language === 'arabic';

        if ($n === '') {
            return ['action' => 'help', 'items' => [], 'reply_text' =>
                $isAr ? 'اهلا! كيف اقدر اساعدك اليوم؟' : 'Hi! What would you like to order today?'];
        }

        // Greetings
        foreach (['hi', 'hii', 'hello', 'hey', 'help', 'start', 'مرحبا', 'اهلا', 'السلام'] as $g) {
            if ($n === $g || str_starts_with($n, $g . ' ')) {
                return ['action' => 'help', 'items' => [], 'reply_text' =>
                    $isAr
                        ? 'اهلا وسهلا! انا هنا اساعدك تطلب. اكتب "قائمة" لعرض المنتجات.'
                        : 'Hi! Welcome! Type "catalog" to see what we have, or just tell me what you need.'];
            }
        }

        // Small talk
        foreach (['how are you', 'how r u', 'how are u', 'are you there', 'you there', 'whats up', 'what\'s up', 'are u there', 'كيف حالك', 'هل انت هنا', 'وينك'] as $s) {
            if ($n === $s || str_contains($n, $s)) {
                return ['action' => 'none', 'items' => [], 'reply_text' =>
                    $isAr
                        ? 'تمام الحمدلله! انا موجود معك. قل لي ماذا تريد اطلب لك؟'
                        : 'I am doing great and I am here with you. Tell me what you would like to order.'];
            }
        }

        // Acknowledgements
        foreach (['ok', 'okay', 'k', 'yes', 'yeah', 'sure', 'got it', 'noted', 'thanks',
                  'thank you', 'thx', 'fine', 'good', 'شكرا', 'تمام', 'حسنا', 'موافق', 'نعم', 'اوكي'] as $a) {
            if ($n === $a) {
                return ['action' => 'none', 'items' => [], 'reply_text' =>
                    $isAr
                        ? 'تمام! هل تريد اضافة شي ثاني؟ اكتب اسم المنتج والكمية.'
                        : 'Got it! Anything else? Type the item name and quantity to add to your cart.'];
            }
        }

        // Confirm intent
        foreach (['confirm', 'place order', 'checkout', 'تاكيد', 'تاكيد الطلب', 'اكد الطلب', 'اطلب الان'] as $c) {
            if (str_contains($n, $c)) {
                return ['action' => 'confirm_order', 'items' => [], 'reply_text' =>
                    $isAr ? 'جاري تاكيد طلبك...' : 'Placing your order now...'];
            }
        }

        // Track order
        foreach (['track', 'order status', 'where is my order', 'اين طلبي', 'متى يوصل', 'تتبع'] as $t) {
            if (str_contains($n, $t)) {
                return ['action' => 'track_order', 'items' => [], 'reply_text' =>
                    $isAr ? 'دعني اتحقق من حالة طلبك.' : 'Let me check your latest order.'];
            }
        }

        // Catalog / purchase intent
        foreach (['want to order', 'place order', 'catalog', 'menu', 'products',
                  'what do you have', 'اريد اطلب', 'قائمة', 'المنتجات', 'اريد اشتري'] as $t) {
            if (str_contains($n, $t)) {
                return ['action' => 'show_catalog', 'items' => [], 'reply_text' =>
                    $isAr ? 'اليك ما لدينا!' : 'Here is what we have!'];
            }
        }

        return ['action' => 'help', 'items' => [], 'reply_text' =>
            $isAr
                ? 'اكيد! انا معك. تقدر تكتب اسم المنتج والكمية او اكتب قائمة.'
                : 'Sure, I am here. You can type an item with quantity, or type catalog.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Language detection
    // ─────────────────────────────────────────────────────────────────────────

    private function detectLanguage(string $text, ?Message $message): string
    {
        $sample = mb_strtolower(trim($text));

        if ($sample === '' && $message) {
            $sample = mb_strtolower(trim((string) Message::query()
                ->where('retailer_id', $message->retailer_id)
                ->when($message->customer_id,
                    fn ($q) => $q->where('customer_id', $message->customer_id),
                    fn ($q) => $q->where('phone', $message->phone)
                )
                ->where('direction', 'in')
                ->orderBy('id')
                ->value('body')));
        }

        // Arabic Unicode block U+0600-U+06FF
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $sample)) {
            return 'arabic';
        }

        return 'english';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function normalizeAction(string $action): string
    {
        return match (mb_strtolower(trim($action))) {
            'add_to_cart'                                             => 'add_to_cart',
            'request_option'                                          => 'request_option',
            'track_order', 'track', 'tracking', 'order_status'       => 'track_order',
            'confirm_order', 'confirm', 'checkout', 'place_order'     => 'confirm_order',
            'show_catalog', 'catalog', 'menu', 'browse', 'new_order'  => 'show_catalog',
            'none'                                                    => 'none',
            default                                                   => 'help',
        };
    }

    private function cleanProductPhrase(string $name): string
    {
        $clean = mb_strtolower(trim($name));
        if ($clean === '') {
            return '';
        }

        $clean = preg_replace('/^[^\p{L}\p{N}]+/u', '', $clean) ?: $clean;
        $clean = preg_replace(
            '/^(i\s+want\s+to\s+order|i\s+want|want|please|pls|kindly|add|order|send|give\s+me|can\s+i\s+get|\x{0623}\x{0631}\x{064A}\x{062F}|\x{0623}\x{0639}\x{0637}\x{0646}\x{064A})\s+/u',
            '',
            $clean
        ) ?: $clean;
        $clean = preg_replace('/\s+(please|pls|kindly)$/u', '', $clean) ?: $clean;

        return trim($clean);
    }

    private function optionCacheKey(?int $retailerId, string $phone): string
    {
        return 'wa:options:' . $retailerId . ':' . $phone;
    }

    private function aiLog(Message $message, string $provider, string $model, string $prompt, array $response, ?string $error, int $latencyMs): void
    {
        try {
            AiLog::query()->create([
                'retailer_id'       => $message->retailer_id,
                'customer_id'       => $message->customer_id,
                'message_id'        => $message->id,
                'provider'          => $provider,
                'model'             => $model,
                'prompt'            => $prompt,
                'response'          => $response,
                'normalized_output' => $response,
                'latency_ms'        => $latencyMs,
                'success'           => $error === null,
                'error'             => $error,
            ]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }
}