<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliateReferral extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'referrer_tenant_id',
        'referred_tenant_id',
        'commission_rate',
        'status',
        'referred_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'referred_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'referrer_tenant_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'referred_tenant_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }
}
