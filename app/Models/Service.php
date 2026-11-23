<?php

namespace App\Models;

use App\Enums\ServiceUnit;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'unit',
        'price',
        'estimated_hours',
        'commission_method',
        'commission_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit' => ServiceUnit::class,
            'price' => 'decimal:2',
            'estimated_hours' => 'integer',
            'commission_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function branchPrices(): HasMany
    {
        return $this->hasMany(BranchServicePrice::class);
    }

    public function priceForBranch(Branch $branch): string
    {
        $override = $this->relationLoaded('branchPrices')
            ? $this->branchPrices->firstWhere('branch_id', $branch->getKey())
            : $this->branchPrices()->where('branch_id', $branch->getKey())->first();

        return $override?->price ?? $this->price;
    }
}
