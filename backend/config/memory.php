<?php

return [
    'enabled' => env('MEMORY_LAYER_ENABLED', false),
    'pilot_retailer_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('MEMORY_LAYER_PILOT_RETAILER_IDS', ''))
    ))),
    'max_facts_per_customer' => (int) env('MEMORY_MAX_FACTS_PER_CUSTOMER', 20),
    'extraction_every_n_messages' => (int) env('MEMORY_EXTRACTION_EVERY_N_MESSAGES', 6),
    'extraction_recent_turns' => (int) env('MEMORY_EXTRACTION_RECENT_TURNS', 12),
    'extraction_provider' => env('MEMORY_EXTRACTION_PROVIDER', 'anthropic'),
    'extraction_model' => env('MEMORY_EXTRACTION_MODEL', 'claude-3-5-haiku-latest'),
    'extraction_temperature' => (float) env('MEMORY_EXTRACTION_TEMPERATURE', 0.1),
];
