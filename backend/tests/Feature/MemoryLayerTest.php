<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessage;
use App\Models\Customer;
use App\Models\CustomerInsight;
use App\Models\CustomerMemory;
use App\Models\Message;
use App\Models\Retailer;
use App\Services\AI\AIOrderService;
use App\Services\Memory\CustomerContextService;
use App\Services\Memory\CustomerMemoryCommandService;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class MemoryLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_context_is_scoped_by_retailer_and_phone(): void
    {
        config(['memory.enabled' => true]);

        $retailerA = Retailer::query()->create([
            'name' => 'Store A',
            'slug' => 'store-a',
            'settings' => ['ai' => ['memory_layer_enabled' => true]],
        ]);

        $retailerB = Retailer::query()->create([
            'name' => 'Store B',
            'slug' => 'store-b',
            'settings' => ['ai' => ['memory_layer_enabled' => true]],
        ]);

        $customerA = Customer::query()->create([
            'retailer_id' => $retailerA->id,
            'phone' => '971500000111',
            'name' => 'Nora',
        ]);

        $customerB = Customer::query()->create([
            'retailer_id' => $retailerB->id,
            'phone' => '971500000111',
            'name' => 'Nora',
        ]);

        CustomerInsight::query()->create([
            'retailer_id' => $retailerA->id,
            'customer_id' => $customerA->id,
            'typical_order_frequency' => 'weekly',
            'top_brands' => [['brand' => 'Acme', 'orders' => 4]],
            'avg_basket_value' => 89.50,
            'preferred_delivery_window' => 'evening',
            'total_orders' => 5,
        ]);

        CustomerMemory::query()->create([
            'retailer_id' => $retailerA->id,
            'customer_id' => $customerA->id,
            'fact' => 'Usually asks for lactose-free milk.',
            'fact_hash' => hash('sha256', 'usually asks for lactose-free milk.'),
            'confidence' => 0.9,
        ]);

        CustomerMemory::query()->create([
            'retailer_id' => $retailerB->id,
            'customer_id' => $customerB->id,
            'fact' => 'Prefers budget snack packs.',
            'fact_hash' => hash('sha256', 'prefers budget snack packs.'),
            'confidence' => 0.8,
        ]);

        $context = app(CustomerContextService::class)->getCustomerContext($retailerA->id, '971500000111');

        $this->assertSame('Nora', data_get($context, 'hard_facts.name'));
        $this->assertSame('weekly', data_get($context, 'behavioral_patterns.typical_order_frequency'));
        $this->assertCount(1, $context['conversational_memory']);
        $this->assertStringContainsString('lactose-free', $context['summary']);
        $this->assertStringNotContainsString('budget snack packs', $context['summary']);
    }

    public function test_forget_commands_soft_delete_rows(): void
    {
        $retailer = Retailer::query()->create([
            'name' => 'Store',
            'slug' => 'store',
            'settings' => ['ai' => ['memory_layer_enabled' => true]],
        ]);

        $customer = Customer::query()->create([
            'retailer_id' => $retailer->id,
            'phone' => '971500000222',
            'name' => 'Tariq',
        ]);

        CustomerMemory::query()->create([
            'retailer_id' => $retailer->id,
            'customer_id' => $customer->id,
            'fact' => 'Likes sparkling water.',
            'fact_hash' => hash('sha256', 'likes sparkling water.'),
        ]);

        CustomerMemory::query()->create([
            'retailer_id' => $retailer->id,
            'customer_id' => $customer->id,
            'fact' => 'Orders on Fridays.',
            'fact_hash' => hash('sha256', 'orders on fridays.'),
        ]);

        $service = app(CustomerMemoryCommandService::class);

        $partial = $service->handleCommand($retailer->id, $customer->phone, 'forget sparkling');
        $this->assertStringContainsString('removed', (string) $partial);

        $all = $service->handleCommand($retailer->id, $customer->phone, 'forget everything');
        $this->assertStringContainsString('forgotten all', (string) $all);

        $this->assertEquals(0, CustomerMemory::query()
            ->where('retailer_id', $retailer->id)
            ->where('customer_id', $customer->id)
            ->count());

        $this->assertGreaterThan(0, CustomerMemory::withTrashed()->count());
    }

    public function test_memory_extraction_failure_does_not_break_message_processing(): void
    {
        config([
            'memory.enabled' => true,
            'memory.extraction_every_n_messages' => 1,
            'services.anthropic.api_key' => 'test-key',
            'queue.default' => 'sync',
        ]);

        Http::fake(fn () => throw new \RuntimeException('Haiku endpoint unavailable'));

        $retailer = Retailer::query()->create([
            'name' => 'Store',
            'slug' => 'store-x',
            'settings' => ['ai' => ['memory_layer_enabled' => true]],
        ]);

        $customer = Customer::query()->create([
            'retailer_id' => $retailer->id,
            'phone' => '971500000333',
            'name' => 'Amina',
        ]);

        $message = Message::query()->create([
            'retailer_id' => $retailer->id,
            'customer_id' => $customer->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'message_type' => 'text',
            'phone' => $customer->phone,
            'body' => '2 milk',
            'processed' => false,
        ]);

        $client = Mockery::mock(WhatsAppClient::class);
        $client->shouldReceive('sendText')->atLeast()->once();
        $this->app->instance(WhatsAppClient::class, $client);

        ProcessIncomingMessage::dispatchSync($message->id);

        $this->assertTrue((bool) $message->fresh()->processed);
    }

    public function test_returning_customer_context_is_injected_into_ai_prompt_logs(): void
    {
        Log::spy();

        $retailer = Retailer::query()->create([
            'name' => 'Store Prompt',
            'slug' => 'store-prompt',
        ]);

        $customer = Customer::query()->create([
            'retailer_id' => $retailer->id,
            'phone' => '971500000444',
            'name' => 'Rana',
        ]);

        $message = Message::query()->create([
            'retailer_id' => $retailer->id,
            'customer_id' => $customer->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'message_type' => 'text',
            'phone' => $customer->phone,
            'body' => '2 milk',
            'processed' => false,
        ]);

        app(AIOrderService::class)->parseMessage($message, $customer, [
            'summary' => "Customer name: Rana\nDurable customer facts:\n- Prefers evening delivery",
        ]);

        Log::shouldHaveReceived('info')
            ->with('AI prompt assembled', Mockery::on(function (array $context): bool {
                return str_contains((string) ($context['system_prompt'] ?? ''), 'Customer context')
                    && str_contains((string) ($context['system_prompt'] ?? ''), 'Prefers evening delivery');
            }))
            ->once();
    }
}
