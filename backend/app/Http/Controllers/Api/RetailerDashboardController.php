<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesRetailerScope;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RetailerDashboardController extends Controller
{
    use ResolvesRetailerScope;

    public function __invoke(Request $request): JsonResponse
    {
        $retailerId = $this->scopedRetailerId($request);

        $orders = Order::query()->where('retailer_id', $retailerId);

        $stats = [
            'total_orders' => (clone $orders)->count(),
            'revenue' => (float) (clone $orders)->sum('total'),
            'live_orders' => (clone $orders)->whereIn('status', ['placed', 'picking', 'packed'])->count(),
            'low_stock' => Product::query()->where('retailer_id', $retailerId)->where('stock', '<=', 5)->count(),
        ];

        $trends = Order::query()
            ->selectRaw('DATE(created_at) as date, SUM(total) as gmv, COUNT(*) as orders_count')
            ->where('retailer_id', $retailerId)
            ->where('created_at', '>=', now()->subDays(14))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return response()->json([
            'stats' => $stats,
            'trends' => $trends,
        ]);
    }
}
