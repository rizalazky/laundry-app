<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investor extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'capital_amount',
        'share_percent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capital_amount' => 'decimal:2',
            'share_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function profitSharings(): HasMany
    {
        return $this->hasMany(ProfitSharing::class);
    }
}
