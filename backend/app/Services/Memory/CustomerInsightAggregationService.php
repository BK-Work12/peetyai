<?php

namespace App\Services\Memory;

use App\Models\Customer;
use App\Models\CustomerInsight;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CustomerInsightAggregationService
{
    public function __construct(private readonly MemoryJsonCodec $codec)
    {
    }

    public function refreshAll(): void
    {
        $groups = Order::query()
            ->select(['retailer_id', 'customer_id'])
            ->whereNotNull('customer_id')
            ->groupBy(['retailer_id', 'customer_id'])
            ->get();

        foreach ($groups as $group) {
            $this->refreshForCustomer((int) $group->retailer_id, (int) $group->customer_id);
        }
    }

    public function refreshForCustomer(int $retailerId, int $customerId): void
    {
        $customer = Customer::query()
            ->where('retailer_id', $retailerId)
            ->find($customerId);

        if (! $customer) {
            return;
        }

        $orders = Order::query()
            ->where('retailer_id', $retailerId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at')
            ->get(['id', 'total', 'placed_at', 'created_at']);

        if ($orders->isEmpty()) {
            return;
        }

        $totalOrders = $orders->count();
        $avgBasket = (float) $orders->avg('total');
        $lastOrderAt = $orders->last()?->placed_at ?? $orders->last()?->created_at;

        $typicalFrequency = $this->computeTypicalFrequency($orders->pluck('created_at')->filter()->values()->all());

        $preferredWindow = $this->computePreferredDeliveryWindow($orders->pluck('placed_at')->filter()->values()->all());

        $topBrands = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.retailer_id', $retailerId)
            ->where('orders.customer_id', $customerId)
            ->whereNotNull('products.brand')
            ->selectRaw('products.brand as brand, COUNT(*) as orders_count')
            ->groupBy('products.brand')
            ->orderByDesc('orders_count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['brand' => (string) $row->brand, 'orders' => (int) $row->orders_count])
            ->all();

        CustomerInsight::query()->updateOrCreate(
            [
                'retailer_id' => $retailerId,
                'customer_id' => $customerId,
            ],
            [
                'typical_order_frequency' => $typicalFrequency,
                'top_brands' => $this->codec->writeTopBrands($topBrands),
                'avg_basket_value' => round($avgBasket, 2),
                'preferred_delivery_window' => $preferredWindow,
                'last_order_at' => $lastOrderAt,
                'total_orders' => $totalOrders,
            ]
        );
    }

    /**
     * @param  array<int, \Illuminate\Support\Carbon>  $timestamps
     */
    private function computeTypicalFrequency(array $timestamps): string
    {
        if (count($timestamps) < 2) {
            return 'insufficient_data';
        }

        $gaps = [];

        for ($i = 1; $i < count($timestamps); $i++) {
            $gaps[] = max(1, $timestamps[$i - 1]->diffInDays($timestamps[$i]));
        }

        $avgGapDays = array_sum($gaps) / count($gaps);

        if ($avgGapDays <= 7) {
            return 'weekly';
        }

        if ($avgGapDays <= 20) {
            return 'biweekly';
        }

        if ($avgGapDays <= 45) {
            return 'monthly';
        }

        return 'occasional';
    }

    /**
     * @param  array<int, \Illuminate\Support\Carbon>  $placedAt
     */
    private function computePreferredDeliveryWindow(array $placedAt): ?string
    {
        if (empty($placedAt)) {
            return null;
        }

        $buckets = [
            'morning' => 0,
            'afternoon' => 0,
            'evening' => 0,
            'night' => 0,
        ];

        foreach ($placedAt as $time) {
            $hour = (int) $time->format('G');

            if ($hour >= 6 && $hour < 12) {
                $buckets['morning']++;
            } elseif ($hour >= 12 && $hour < 17) {
                $buckets['afternoon']++;
            } elseif ($hour >= 17 && $hour < 22) {
                $buckets['evening']++;
            } else {
                $buckets['night']++;
            }
        }

        arsort($buckets);

        return (string) array_key_first($buckets);
    }
}
