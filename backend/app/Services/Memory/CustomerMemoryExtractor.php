<?php

namespace App\Services\Memory;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CustomerMemoryExtractor
{
    public function __construct(private readonly MemoryJsonCodec $codec)
    {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $turns
     * @return array<int, string>
     */
    public function extractDurableFacts(array $turns): array
    {
        if (empty($turns)) {
            return [];
        }

        $provider = config('memory.extraction_provider', 'anthropic');

        if ($provider !== 'anthropic' || ! config('services.anthropic.api_key')) {
            return [];
        }

        $model = config('memory.extraction_model', 'claude-3-5-haiku-latest');
        $temperature = (float) config('memory.extraction_temperature', 0.1);

        $prompt = $this->buildPrompt($turns);

        try {
            $response = Http::withToken(config('services.anthropic.api_key'))
                ->withHeaders([
                    'anthropic-version' => '2023-06-01',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 200,
                    'temperature' => $temperature,
                    'system' => 'You extract only durable customer memory facts in strict JSON.',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            $text = data_get($response->json(), 'content.0.text');
            $decoded = json_decode((string) $text, true);

            if (! is_array($decoded)) {
                return [];
            }

            return $this->sanitizeFacts($this->codec->readStringList($decoded));
        } catch (\Throwable $throwable) {
            Log::warning('Customer memory extraction failed', [
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, string>  $facts
     * @return array<int, string>
     */
    public function sanitizeFacts(array $facts): array
    {
        $filtered = [];

        foreach ($facts as $fact) {
            if ($this->containsBlockedPii($fact)) {
                continue;
            }

            $normalized = trim($fact);
            if ($normalized === '') {
                continue;
            }

            $filtered[] = $normalized;

            if (count($filtered) >= 3) {
                break;
            }
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $turns
     */
    private function buildPrompt(array $turns): string
    {
        $lines = [
            'Extract up to 3 durable facts about this customer that would be useful in future conversations.',
            'Return JSON array of strings.',
            'Exclude payment info, ID numbers, exact addresses, one-off requests, and anything time-sensitive.',
            'If nothing useful, return [].',
            '',
            'Conversation turns:',
        ];

        foreach ($turns as $turn) {
            $role = strtoupper((string) ($turn['role'] ?? 'USER'));
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $lines[] = "{$role}: {$content}";
        }

        return implode("\n", $lines);
    }

    private function containsBlockedPii(string $fact): bool
    {
        $value = mb_strtolower($fact);

        $keywords = [
            'credit card',
            'debit card',
            'card number',
            'payment',
            'bank account',
            'ssn',
            'national id',
            'passport',
            'routing number',
            'full address',
            'street',
            'avenue',
            'apt',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($value, $keyword)) {
                return true;
            }
        }

        return (bool) preg_match('/\b\d{8,}\b/', $value);
    }
}
