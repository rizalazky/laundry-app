<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionRenewalReminder extends Model
{
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'period_end',
        'days_before_expiry',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'period_end' => 'datetime',
            'days_before_expiry' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
