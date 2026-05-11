<?php

namespace App\Services\Memory;

use App\Models\Retailer;

class MemoryFeature
{
    public function enabledForRetailer(?Retailer $retailer): bool
    {
        if (! $retailer || ! config('memory.enabled', false)) {
            return false;
        }

        $settingsEnabled = (bool) data_get($retailer->settings, 'ai.memory_layer_enabled', false);
        $pilotRetailers = config('memory.pilot_retailer_ids', []);

        return $settingsEnabled || in_array($retailer->id, $pilotRetailers, true);
    }
}
