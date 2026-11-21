<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'stock_opname_id',
        'inventory_item_id',
        'item_name_snapshot',
        'item_sku_snapshot',
        'unit_snapshot',
        'decimal_places_snapshot',
        'system_qty',
        'physical_qty',
        'difference_qty',
        'counted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places_snapshot' => 'integer',
            'system_qty' => 'decimal:4',
            'physical_qty' => 'decimal:4',
            'difference_qty' => 'decimal:4',
            'counted_at' => 'datetime',
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
