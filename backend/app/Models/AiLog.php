<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLog extends Model
{
    protected $fillable = [
        'retailer_id',
        'customer_id',
        'message_id',
        'provider',
        'model',
        'prompt',
        'response',
        'normalized_output',
        'latency_ms',
        'success',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'normalized_output' => 'array',
            'success' => 'boolean',
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

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
