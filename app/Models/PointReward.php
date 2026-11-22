<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PointReward extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'reward_name', 'points_required', 'stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'points_required' => 'integer',
            'stock' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function hasAvailableStock(): bool
    {
        return $this->stock === null || (float) $this->stock > 0;
    }
}
