<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\Concerns\ResolvesRetailerScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ResolvesRetailerScope;

    public function index(Request $request): JsonResponse
    {
        $retailerId = $this->scopedRetailerId($request);
        $status = $request->string('status')->toString();
        $receivedByBot = $request->query('received_by_bot');

        $orders = Order::query()
            ->select(['id', 'retailer_id', 'customer_id', 'status', 'total', 'created_at'])
            ->with(['customer:id,name,phone'])
            ->where('retailer_id', $retailerId)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when(
                $receivedByBot !== null,
                fn ($query) => $query->where('received_by_bot', filter_var($receivedByBot, FILTER_VALIDATE_BOOLEAN))
            )
            ->latest('id')
            ->simplePaginate(20);

        return response()->json($orders);
    }

    public function store(StoreOrderRequest $request, CartService $cartService, OrderService $orderService): JsonResponse
    {
        $data = $request->validated();
        $data['retailer_id'] = $this->scopedRetailerId($request);

        $customer = Customer::query()->firstOrCreate(
            [
                'retailer_id' => $data['retailer_id'],
                'phone' => $data['phone'],
            ],
            [
                'name' => null,
            ]
        );

        $cart = $cartService->getOrCreate($data['retailer_id'], $data['phone'], $customer);
        $cart = $cartService->applyItems($cart, $data['items']);
        $order = $orderService->placeFromCart($cart, $customer, $data['notes'] ?? null);

        return response()->json($order, 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $order->retailer_id);

        return response()->json($order->load(['customer', 'items', 'statusLogs']));
    }

    public function update(UpdateOrderStatusRequest $request, Order $order, OrderService $orderService): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $order->retailer_id);

        $status = OrderStatus::from($request->validated('status'));
        $updated = $orderService->updateStatus($order, $status, $request->user(), $request->validated('note'));

        return response()->json($updated);
    }
}
