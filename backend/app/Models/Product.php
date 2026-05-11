<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    protected $fillable = [
        'retailer_id',
        'category_id',
        'name',
        'sku',
        'brand',
        'unit',
        'price',
        'stock',
        'priority',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'retailer_id' => $this->retailer_id,
            'name' => $this->name,
            'brand' => $this->brand,
            'sku' => $this->sku,
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function synonyms(): HasMany
    {
        return $this->hasMany(Synonym::class);
    }
}
