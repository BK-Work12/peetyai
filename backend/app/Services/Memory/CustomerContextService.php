<?php

namespace App\Services\Memory;

use App\Models\Customer;
use App\Models\CustomerMemory;
use Illuminate\Support\Facades\DB;

class CustomerContextService
{
    public function __construct(private readonly MemoryJsonCodec $codec)
    {
    }

    public function getCustomerContext(int $retailerId, string $customerPhone): array
    {
        $customer = Customer::query()
            ->where('retailer_id', $retailerId)
            ->where('phone', $customerPhone)
            ->with(['insight', 'memories' => fn ($query) => $query->orderByDesc('last_referenced_at')->orderByDesc('created_at')->limit(20)])
            ->first();

        if (! $customer) {
            return [
                'hard_facts' => null,
                'behavioral_patterns' => null,
                'conversational_memory' => [],
                'summary' => 'No customer memory available.',
            ];
        }

        $memories = $customer->memories
            ->map(fn (CustomerMemory $memory) => [
                'id' => $memory->id,
                'fact' => $memory->fact,
                'confidence' => (float) $memory->confidence,
                'last_referenced_at' => optional($memory->last_referenced_at)?->toIso8601String(),
                'pii_redacted' => $memory->pii_redacted,
            ])
            ->values()
            ->all();

        if (! empty($memories)) {
            DB::transaction(function () use ($customer): void {
                $now = now();
                $ids = $customer->memories->pluck('id')->all();

                CustomerMemory::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'last_referenced_at' => $now,
                        'reference_count' => DB::raw('reference_count + 1'),
                    ]);
            });
        }

        $hardFacts = [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => data_get($customer->preferences, 'address'),
            'payment_method' => data_get($customer->preferences, 'payment_method'),
            'preferred_language' => $customer->preferred_language,
            'preferred_brand' => $customer->preferred_brand,
        ];

        $insight = $customer->insight;
        $behavioralPatterns = $insight ? [
            'typical_order_frequency' => $insight->typical_order_frequency,
            'top_brands' => $this->codec->readTopBrands($insight->top_brands),
            'avg_basket_value' => (float) $insight->avg_basket_value,
            'preferred_delivery_window' => $insight->preferred_delivery_window,
            'last_order_at' => optional($insight->last_order_at)?->toIso8601String(),
            'total_orders' => $insight->total_orders,
        ] : null;

        return [
            'hard_facts' => $hardFacts,
            'behavioral_patterns' => $behavioralPatterns,
            'conversational_memory' => $memories,
            'summary' => $this->buildSummary($hardFacts, $behavioralPatterns, $memories),
        ];
    }

    private function buildSummary(array $hardFacts, ?array $behavioralPatterns, array $memories): string
    {
        $lines = [];

        if (! empty($hardFacts['name'])) {
            $lines[] = 'Customer name: '.$hardFacts['name'];
        }

        if (! empty($behavioralPatterns)) {
            $lines[] = 'Typical order frequency: '.($behavioralPatterns['typical_order_frequency'] ?? 'unknown');
            $lines[] = 'Average basket value: '.number_format((float) ($behavioralPatterns['avg_basket_value'] ?? 0), 2);
            $lines[] = 'Preferred delivery window: '.($behavioralPatterns['preferred_delivery_window'] ?? 'unknown');
        }

        if (! empty($memories)) {
            $factLines = array_map(
                static fn (array $memory): string => '- '.$memory['fact'],
                array_slice($memories, 0, 8)
            );

            $lines[] = 'Durable customer facts:';
            $lines = array_merge($lines, $factLines);
        }

        return empty($lines) ? 'No customer memory available.' : implode("\n", $lines);
    }
}
