<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'location_type',
        'location_id',
        'opname_date',
        'status',
        'notes',
        'created_by',
        'finalized_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'opname_date' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }
}
