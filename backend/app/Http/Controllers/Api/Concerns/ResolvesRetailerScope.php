<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

trait ResolvesRetailerScope
{
    protected function scopedRetailerId(Request $request, string $inputKey = 'retailer_id'): int
    {
        $user = $request->user();
        $requestedRetailerId = $request->integer($inputKey);

        if ($user && $user->role->value !== 'owner') {
            if (! $user->retailer_id) {
                abort(403, 'Retailer account is not configured.');
            }

            if ($requestedRetailerId && $requestedRetailerId !== (int) $user->retailer_id) {
                abort(403, 'Forbidden');
            }

            return (int) $user->retailer_id;
        }

        if ($requestedRetailerId <= 0) {
            abort(422, 'retailer_id is required.');
        }

        return $requestedRetailerId;
    }

    protected function ensureRetailerResourceAccess(Request $request, ?int $resourceRetailerId): void
    {
        if ($resourceRetailerId === null) {
            abort(404);
        }

        $user = $request->user();
        if ($user && $user->role->value !== 'owner' && (int) $user->retailer_id !== (int) $resourceRetailerId) {
            abort(403, 'Forbidden');
        }
    }
}
