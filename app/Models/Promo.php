<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promo extends Model
{
    use BelongsToTenant;

    public const CATEGORY_BASIC = 'basic';
    public const CATEGORY_GENERAL = 'general';
    public const REWARD_PERCENT = 'percent';
    public const REWARD_FIXED = 'fixed_amount';

    protected $fillable = [
        'tenant_id', 'name', 'category', 'reward_type', 'reward_value',
        'start_at', 'end_at', 'is_active', 'rules',
    ];

    protected function casts(): array
    {
        return [
            'reward_value' => 'decimal:2',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_active' => 'boolean',
            'rules' => 'array',
        ];
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
