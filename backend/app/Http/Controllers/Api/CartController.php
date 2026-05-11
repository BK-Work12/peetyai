<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesRetailerScope;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    use ResolvesRetailerScope;

    public function index(Request $request): JsonResponse
    {
        $retailerId = $this->scopedRetailerId($request);
        $phone = $request->string('phone')->toString();

        $cart = Cart::query()
            ->where('retailer_id', $retailerId)
            ->where('session_phone', $phone)
            ->where('checked_out', false)
            ->with('items.product')
            ->latest('id')
            ->first();

        return response()->json($cart);
    }

    public function store(Request $request, CartService $cartService): JsonResponse
    {
        $validated = $request->validate([
            'retailer_id' => ['required', 'integer', 'exists:retailers,id'],
            'phone' => ['required', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $validated['retailer_id'] = $this->scopedRetailerId($request);

        $cart = $cartService->getOrCreate($validated['retailer_id'], $validated['phone']);
        $cart = $cartService->applyItems($cart, $validated['items']);

        return response()->json($cart);
    }
}
