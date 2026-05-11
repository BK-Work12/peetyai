<?php

namespace App\Console\Commands;

use App\Services\Memory\CustomerInsightAggregationService;
use Illuminate\Console\Command;

class RefreshCustomerInsights extends Command
{
    protected $signature = 'memory:refresh-customer-insights';

    protected $description = 'Rebuild behavioral customer insights from order history.';

    public function handle(CustomerInsightAggregationService $service): int
    {
        $service->refreshAll();

        $this->info('Customer insights refreshed.');

        return self::SUCCESS;
    }
}
