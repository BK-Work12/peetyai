<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderUpdated;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(private readonly WhatsAppClient $whatsAppClient)
    {
    }

    public function placeFromCart(
        Cart $cart,
        ?Customer $customer = null,
        ?string $note = null,
        bool $receivedByBot = false
    ): Order
    {
        return DB::transaction(function () use ($cart, $customer, $note, $receivedByBot) {
            $subtotal = $cart->items->sum(fn ($item) => $item->price * $item->quantity);
            $deliveryFee = 0;

            $order = Order::query()->create([
                'retailer_id' => $cart->retailer_id,
                'customer_id' => $customer?->id,
                'cart_id' => $cart->id,
                'status' => OrderStatus::Placed,
                'source' => 'whatsapp',
                'received_by_bot' => $receivedByBot,
                'received_by_bot_at' => $receivedByBot ? now() : null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
                'notes' => $note,
                'placed_at' => now(),
            ]);

            foreach ($cart->items as $item) {
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name ?? 'Unknown',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'line_total' => $item->price * $item->quantity,
                    'meta' => $item->meta,
                ]);
            }

            $cart->forceFill(['checked_out' => true])->save();

            OrderStatusLog::query()->create([
                'order_id' => $order->id,
                'to_status' => OrderStatus::Placed->value,
                'note' => 'Order created from WhatsApp cart',
            ]);

            $this->refreshCustomerStats($customer);

            broadcast(new OrderUpdated($order->fresh(['items', 'customer'])));

            return $order->fresh(['items', 'statusLogs']);
        });
    }

    public function updateStatus(Order $order, OrderStatus $status, ?User $actor = null, ?string $note = null): Order
    {
        $previous = $order->status;

        $order->forceFill([
            'status' => $status,
            'delivered_at' => $status === OrderStatus::Delivered ? now() : $order->delivered_at,
        ])->save();

        OrderStatusLog::query()->create([
            'order_id' => $order->id,
            'user_id' => $actor?->id,
            'from_status' => $previous?->value,
            'to_status' => $status->value,
            'note' => $note,
        ]);

        if ($previous !== $status) {
            $this->notifyCustomerStatusChanged($order->fresh(['customer']), $status);
        }

        broadcast(new OrderUpdated($order->fresh(['items', 'customer'])));

        return $order->fresh(['items', 'statusLogs']);
    }

    private function refreshCustomerStats(?Customer $customer): void
    {
        if (! $customer) {
            return;
        }

        $customer->forceFill([
            'lifetime_orders' => $customer->orders()->count(),
            'lifetime_value' => $customer->orders()->sum('total'),
            'last_order_at' => now(),
        ])->save();
    }

    private function notifyCustomerStatusChanged(Order $order, OrderStatus $status): void
    {
        $customer = $order->customer;
        $phone = trim((string) ($customer?->phone ?? ''));
        if ($phone === '') {
            $order->loadMissing('cart');
            $phone = trim((string) ($order->cart?->session_phone ?? ''));
        }

        if ($phone === '') {
            return;
        }

        $icon = match ($status) {
            OrderStatus::Picking => '🛍️',
            OrderStatus::Packed => '📦',
            OrderStatus::Dispatched => '🚚',
            OrderStatus::Delivered => '✅',
            default => 'ℹ️',
        };

        $statusLabel = ucfirst($status->value);
        $message = "{$icon} Order #{$order->id} update: {$statusLabel}.";

        if ($status === OrderStatus::Delivered) {
            $message .= ' Thank you for ordering with us.';
        }

        try {
            $this->whatsAppClient->sendText(
                $phone,
                $message,
                $order->retailer_id,
                $customer?->id,
            );
        } catch (\Throwable $throwable) {
            Log::warning('Order status WhatsApp notification failed', [
                'order_id' => $order->id,
                'customer_id' => $customer?->id,
                'phone' => $phone,
                'status' => $status->value,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
