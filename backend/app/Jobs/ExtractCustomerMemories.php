<?php

namespace App\Jobs;

use App\Models\CustomerMemory;
use App\Models\Message;
use App\Services\Memory\CustomerMemoryExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractCustomerMemories implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $retailerId,
        private readonly int $customerId,
        private readonly ?string $sourceConversationId = null
    ) {
    }

    public function handle(CustomerMemoryExtractor $extractor): void
    {
        try {
            $turns = Message::query()
                ->where('retailer_id', $this->retailerId)
                ->where('customer_id', $this->customerId)
                ->whereNotNull('body')
                ->orderByDesc('id')
                ->limit((int) config('memory.extraction_recent_turns', 12))
                ->get(['direction', 'body'])
                ->reverse()
                ->map(fn (Message $message) => [
                    'role' => $message->direction === 'in' ? 'user' : 'assistant',
                    'content' => (string) $message->body,
                ])
                ->values()
                ->all();

            $facts = $extractor->extractDurableFacts($turns);

            foreach ($facts as $fact) {
                $factHash = hash('sha256', mb_strtolower(trim($fact)));

                CustomerMemory::query()->firstOrCreate([
                    'retailer_id' => $this->retailerId,
                    'customer_id' => $this->customerId,
                    'fact_hash' => $factHash,
                ], [
                    'fact' => $fact,
                    'source_conversation_id' => $this->sourceConversationId,
                    'confidence' => 0.70,
                    'pii_redacted' => false,
                ]);
            }

            $this->trimFacts();
        } catch (\Throwable $throwable) {
            Log::warning('Async customer memory extraction failed', [
                'retailer_id' => $this->retailerId,
                'customer_id' => $this->customerId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function trimFacts(): void
    {
        $maxFacts = (int) config('memory.max_facts_per_customer', 20);

        $total = CustomerMemory::query()
            ->where('retailer_id', $this->retailerId)
            ->where('customer_id', $this->customerId)
            ->count();

        $overflow = max(0, $total - $maxFacts);

        if ($overflow === 0) {
            return;
        }

        $idsToDelete = CustomerMemory::query()
            ->where('retailer_id', $this->retailerId)
            ->where('customer_id', $this->customerId)
            ->orderBy('reference_count')
            ->orderBy('created_at')
            ->limit($overflow)
            ->pluck('id')
            ->all();

        if (! empty($idsToDelete)) {
            CustomerMemory::query()->whereIn('id', $idsToDelete)->delete();
        }
    }
}
