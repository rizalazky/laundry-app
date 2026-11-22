<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    use BelongsToTenant;

    public const TYPE_GENERAL = 'general';
    public const TYPE_REFERRAL = 'referral';

    protected $fillable = [
        'tenant_id', 'promo_id', 'code', 'voucher_type', 'quota',
        'used_count', 'starts_at', 'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quota' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function remainingQuota(): ?int
    {
        return $this->quota === null ? null : max(0, $this->quota - $this->used_count);
    }
}
