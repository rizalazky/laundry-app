<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchServicePrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'service_id',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BranchServicePrice $branchServicePrice): void {
            $branchServicePrice->tenant_id ??= Service::query()
                ->whereKey($branchServicePrice->service_id)
                ->value('tenant_id');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
