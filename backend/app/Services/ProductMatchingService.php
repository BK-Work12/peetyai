<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Synonym;
use Illuminate\Support\Collection;

class ProductMatchingService
{
    public function findCandidates(int $retailerId, string $query, int $limit = 5): Collection
    {
        $normalized = $this->normalizeQuery($query);

        if ($normalized === '') {
            return collect();
        }

        $products = Product::query()
            ->where('retailer_id', $retailerId)
            ->where('is_active', true)
            ->where(function ($builder) use ($normalized) {
                $builder
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.$normalized.'%'])
                    ->orWhereRaw('LOWER(brand) LIKE ?', ['%'.$normalized.'%'])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.$normalized.'%']);
            })
            ->orderByDesc('priority')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($products->isNotEmpty()) {
            return $products;
        }

        $tokens = $this->searchTokens($normalized);
        if ($tokens !== []) {
            $tokenMatches = Product::query()
                ->where('retailer_id', $retailerId)
                ->where('is_active', true)
                ->where(function ($builder) use ($tokens) {
                    foreach ($tokens as $token) {
                        $builder
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$token.'%'])
                            ->orWhereRaw('LOWER(brand) LIKE ?', ['%'.$token.'%'])
                            ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.$token.'%']);
                    }
                })
                ->orderByDesc('priority')
                ->orderBy('name')
                ->limit($limit)
                ->get();

            if ($tokenMatches->isNotEmpty()) {
                return $tokenMatches;
            }
        }

        $fuzzyMatches = $this->fuzzyMatches($retailerId, $normalized, $limit);
        if ($fuzzyMatches->isNotEmpty()) {
            return $fuzzyMatches;
        }

        $synonymMatches = Synonym::query()
            ->where('retailer_id', $retailerId)
            ->whereRaw('LOWER(term) LIKE ?', ['%'.$normalized.'%'])
            ->with('product')
            ->limit($limit)
            ->get()
            ->pluck('product')
            ->filter(fn ($product) => $product?->is_active)
            ->values();

        return $synonymMatches;
    }

    public function bestMatch(int $retailerId, string $query): ?Product
    {
        return $this->findCandidates($retailerId, $query, 1)->first();
    }

    private function searchTokens(string $normalized): array
    {
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $stopwords = [
            'i', 'want', 'to', 'order', 'add', 'cart', 'please', 'pls', 'the', 'a', 'an', 'for', 'and', 'with',
            'me', 'my', 'send', 'give', 'need', 'can', 'get', 'mujhe', 'krdo', 'kar', 'do',
            'liter', 'litre', 'litter', 'ltr', 'kg', 'gram', 'grams', 'ml', 'pack', 'packet', 'bottle', 'piece', 'pieces',
        ];

        return collect($parts)
            ->map(fn (string $token) => trim($token))
            ->map(fn (string $token) => preg_replace('/[^\p{L}\p{N}]+/u', '', $token) ?: '')
            ->filter(fn (string $token) => $token !== '' && mb_strlen($token) >= 3)
            ->reject(fn (string $token) => in_array($token, $stopwords, true))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeQuery(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?: $normalized;

        return $normalized;
    }

    private function fuzzyMatches(int $retailerId, string $normalizedQuery, int $limit): Collection
    {
        $queryTokens = $this->searchTokens($normalizedQuery);
        if ($queryTokens === []) {
            return collect();
        }

        $products = Product::query()
            ->where('retailer_id', $retailerId)
            ->where('is_active', true)
            ->get(['id', 'name', 'brand', 'sku', 'priority']);

        $scored = $products
            ->map(function (Product $product) use ($queryTokens) {
                $haystack = $this->normalizeQuery(trim(($product->brand ? $product->brand.' ' : '').$product->name));
                $hayTokens = collect(preg_split('/\s+/', $haystack) ?: [])
                    ->filter(fn (string $token) => $token !== '')
                    ->values()
                    ->all();

                if ($hayTokens === []) {
                    return null;
                }

                $tokenScore = 0.0;
                foreach ($queryTokens as $queryToken) {
                    $best = 0.0;

                    foreach ($hayTokens as $hayToken) {
                        if ($queryToken === $hayToken) {
                            $best = 1.0;
                            break;
                        }

                        similar_text($queryToken, $hayToken, $percent);
                        $candidate = ((float) $percent) / 100.0;

                        if (str_contains($hayToken, $queryToken) || str_contains($queryToken, $hayToken)) {
                            $candidate = max($candidate, 0.82);
                        }

                        if (soundex($queryToken) === soundex($hayToken)) {
                            $candidate = max($candidate, 0.78);
                        }

                        if ($candidate > $best) {
                            $best = $candidate;
                        }
                    }

                    $tokenScore += $best;
                }

                $avgTokenScore = $tokenScore / max(1, count($queryTokens));
                similar_text(implode(' ', $queryTokens), $haystack, $phrasePercent);
                $phraseScore = ((float) $phrasePercent) / 100.0;
                $priorityBoost = min(0.08, ((int) ($product->priority ?? 0)) / 100.0);

                return [
                    'product' => $product,
                    'score' => ($avgTokenScore * 0.8) + ($phraseScore * 0.2) + $priorityBoost,
                ];
            })
            ->filter(fn ($row) => is_array($row) && ($row['score'] ?? 0) >= 0.70)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $scored->pluck('product')->values();
    }
}
