<?php

namespace App\Services\Memory;

use App\Models\Customer;
use App\Models\CustomerMemory;

class CustomerMemoryCommandService
{
    public function handleCommand(int $retailerId, string $customerPhone, string $text): ?string
    {
        $normalized = trim(mb_strtolower($text));

        $customer = Customer::query()
            ->where('retailer_id', $retailerId)
            ->where('phone', $customerPhone)
            ->first();

        if (! $customer) {
            return null;
        }

        if ($normalized === 'what do you remember about me?' || $normalized === 'what do you remember about me') {
            return $this->listMemories($retailerId, $customer->id);
        }

        if ($normalized === 'forget everything' || $normalized === 'forget me') {
            CustomerMemory::query()
                ->where('retailer_id', $retailerId)
                ->where('customer_id', $customer->id)
                ->delete();

            return 'Done. I have forgotten all stored memory facts for this chat history.';
        }

        if (str_starts_with($normalized, 'forget ')) {
            $factText = trim(substr($normalized, strlen('forget ')));

            if ($factText === '') {
                return 'Please tell me what to forget after "forget".';
            }

            $deleted = CustomerMemory::query()
                ->where('retailer_id', $retailerId)
                ->where('customer_id', $customer->id)
                ->whereRaw('LOWER(fact) LIKE ?', ['%'.$factText.'%'])
                ->delete();

            if ($deleted === 0) {
                return 'I could not find a matching memory fact to remove.';
            }

            return 'Done. I removed the requested memory fact.';
        }

        return null;
    }

    private function listMemories(int $retailerId, int $customerId): string
    {
        $facts = CustomerMemory::query()
            ->where('retailer_id', $retailerId)
            ->where('customer_id', $customerId)
            ->orderByDesc('last_referenced_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->pluck('fact')
            ->all();

        if (empty($facts)) {
            return 'I do not currently have any durable memory facts stored about you.';
        }

        $lines = ['Here is what I remember:'];

        foreach ($facts as $index => $fact) {
            $lines[] = ($index + 1).'. '.$fact;
        }

        return implode("\n", $lines);
    }
}
