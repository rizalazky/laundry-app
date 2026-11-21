<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'unit',
        'base_unit_id',
        'purchase_unit_id',
        'purchase_unit_conversion_factor',
        'item_category',
        'min_stock',
    ];

    protected function casts(): array
    {
        return [
            'min_stock' => 'decimal:2',
            'purchase_unit_conversion_factor' => 'decimal:6',
        ];
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'base_unit_id');
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'purchase_unit_id');
    }

    public function locationStocks(): HasMany
    {
        return $this->hasMany(LocationStock::class);
    }

    public function categoryLabel(): string
    {
        return match ($this->item_category) {
            'raw_material' => 'Bahan Baku',
            'retail_product' => 'Produk Retail',
            default => $this->item_category,
        };
    }
}
