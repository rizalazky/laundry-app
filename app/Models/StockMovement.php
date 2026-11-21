<?php

namespace App\\Models;

use App\\Enums\\StockMovementType;
use App\\Models\\Concerns\\BelongsToTenant;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class StockMovement extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'inventory_item_id',
        'supplier_id',
        'purchase_quantity',
        'purchase_unit_id',
        'purchase_unit_name_snapshot',
        'purchase_conversion_factor',
        'movement_type',
        'quantity',
        'stock_before',
        'stock_after',
        'unit_cost',
        'notes',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'decimal:4',
            'stock_before' => 'decimal:4',
            'stock_after' => 'decimal:4',
            'purchase_quantity' => 'decimal:4',
            'purchase_conversion_factor' => 'decimal:6',
            'unit_cost' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'purchase_unit_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
