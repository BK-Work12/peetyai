<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInsight extends Model
{
    protected $fillable = [
        'retailer_id',
        'customer_id',
        'typical_order_frequency',
        'top_brands',
        'avg_basket_value',
        'preferred_delivery_window',
        'last_order_at',
        'total_orders',
    ];

    protected function casts(): array
    {
        return [
            'top_brands' => 'array',
            'avg_basket_value' => 'decimal:2',
            'last_order_at' => 'datetime',
            'total_orders' => 'integer',
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
