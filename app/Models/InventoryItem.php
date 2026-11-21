<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'unit',
        'item_category',
        'min_stock',
    ];

    protected function casts(): array
    {
        return [
            'min_stock' => 'decimal:2',
        ];
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
