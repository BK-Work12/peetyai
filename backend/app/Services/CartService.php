<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function getOrCreate(int $retailerId, string $phone, ?Customer $customer = null): Cart
    {
        return Cart::query()->firstOrCreate(
            [
                'retailer_id' => $retailerId,
                'session_phone' => $phone,
                'checked_out' => false,
            ],
            [
                'customer_id' => $customer?->id,
                'last_interaction_at' => now(),
            ],
        );
    }

    public function applyItems(Cart $cart, array $items): Cart
    {
        DB::transaction(function () use ($cart, $items) {
            foreach ($items as $item) {
                $product = Product::query()
                    ->where('retailer_id', $cart->retailer_id)
                    ->find(Arr::get($item, 'product_id'));

                if (! $product) {
                    continue;
                }

                $qty = max(1, (int) Arr::get($item, 'qty', 1));

                $cartItem = CartItem::query()->firstOrNew([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                ]);

                $cartItem->quantity = ($cartItem->exists ? $cartItem->quantity : 0) + $qty;
                $cartItem->price = $product->price;
                $cartItem->save();
            }

            $cart->forceFill(['last_interaction_at' => now()])->save();
        });

        return $cart->fresh(['items.product']);
    }

    public function totals(Cart $cart): array
    {
        $subtotal = $cart->items->sum(fn (CartItem $item) => $item->price * $item->quantity);

        return [
            'items_count' => (int) $cart->items->sum('quantity'),
            'subtotal' => round($subtotal, 2),
        ];
    }
}
