<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_order(): void
    {
        $retailer = Retailer::query()->create([
            'name' => 'Demo Retailer',
            'slug' => 'demo-retailer',
        ]);

        $user = User::query()->create([
            'retailer_id' => $retailer->id,
            'name' => 'Retailer Staff',
            'email' => 'staff@example.com',
            'password' => 'password',
            'role' => UserRole::Staff,
        ]);

        $product = Product::query()->create([
            'retailer_id' => $retailer->id,
            'name' => 'Milk 1L',
            'price' => 8.5,
            'stock' => 100,
            'is_active' => true,
        ]);

        $payload = [
            'retailer_id' => $retailer->id,
            'phone' => '971500000001',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('status', 'placed')
            ->assertJsonPath('received_by_bot', false)
            ->assertJsonPath('received_by_bot_at', null);
    }
}
