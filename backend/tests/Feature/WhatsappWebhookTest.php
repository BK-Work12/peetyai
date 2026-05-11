<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsappWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_accepts_payload_and_dispatches_job(): void
    {
        Queue::fake();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.123',
                            'from' => '971500000001',
                            'type' => 'text',
                            'text' => ['body' => '2 milk'],
                        ]],
                        'metadata' => [
                            'phone_number_id' => 'demo',
                        ],
                    ],
                ]],
            ]],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertOk()->assertJson(['status' => 'accepted']);
        Queue::assertPushed(ProcessIncomingMessage::class);
    }
}
