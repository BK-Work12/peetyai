<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('retailer.{retailerId}', function ($user, int $retailerId): bool {
    return $user->role->value === 'owner' || (int) $user->retailer_id === $retailerId;
});
