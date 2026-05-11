<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessage;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Retailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotOrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_confirmed_by_bot_is_marked_as_received_by_bot(): void
    {
        $retailer = Retailer::query()->create([
            'name' => 'Demo Retailer',
            'slug' => 'demo-retailer-bot',
        ]);

        $customer = Customer::query()->create([
            'retailer_id' => $retailer->id,
            'name' => 'Bot Customer',
            'phone' => '971500000777',
            'preferences' => [
                'checkout_profile' => [
                    'full_name' => 'Bot Customer',
                    'contact_number' => '971500000777',
                    'address' => 'Demo Street, Test City',
                ],
            ],
        ]);

        $product = Product::query()->create([
            'retailer_id' => $retailer->id,
            'name' => 'Rice 5kg',
            'price' => 34.00,
            'stock' => 100,
            'is_active' => true,
        ]);

        $cart = Cart::query()->create([
            'retailer_id' => $retailer->id,
            'customer_id' => $customer->id,
            'session_phone' => $customer->phone,
            'checked_out' => false,
            'last_interaction_at' => now(),
        ]);

        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => $product->price,
        ]);

        $message = Message::query()->create([
            'retailer_id' => $retailer->id,
            'customer_id' => $customer->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'message_type' => 'text',
            'phone' => $customer->phone,
            'body' => 'confirm',
            'processed' => false,
        ]);

        ProcessIncomingMessage::dispatchSync($message->id);

        $order = Order::query()->latest('id')->first();

        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->received_by_bot);
        $this->assertNotNull($order->received_by_bot_at);
        $this->assertSame('whatsapp', $order->source);
    }
}
