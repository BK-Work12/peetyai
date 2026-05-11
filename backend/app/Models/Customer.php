<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = [
        'retailer_id',
        'name',
        'phone',
        'preferred_language',
        'preferred_brand',
        'lifetime_orders',
        'lifetime_value',
        'last_order_at',
        'preferences',
    ];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
            'last_order_at' => 'datetime',
            'lifetime_value' => 'decimal:2',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function insight(): HasOne
    {
        return $this->hasOne(CustomerInsight::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(CustomerMemory::class);
    }
}
