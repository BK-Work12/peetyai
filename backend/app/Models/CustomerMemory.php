<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerMemory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'retailer_id',
        'customer_id',
        'fact',
        'fact_hash',
        'source_conversation_id',
        'last_referenced_at',
        'confidence',
        'reference_count',
        'pii_redacted',
    ];

    protected function casts(): array
    {
        return [
            'last_referenced_at' => 'datetime',
            'confidence' => 'decimal:3',
            'reference_count' => 'integer',
            'pii_redacted' => 'boolean',
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
