<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'retailer_id',
        'customer_id',
        'direction',
        'channel',
        'message_type',
        'external_id',
        'phone',
        'body',
        'media_url',
        'raw_payload',
        'processed',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'processed' => 'boolean',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
