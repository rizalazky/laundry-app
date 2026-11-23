<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointLog extends Model
{
    use BelongsToTenant;

    public const TYPE_EARN = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id', 'customer_id', 'transaction_id', 'loyalty_point_rule_id',
        'type', 'points', 'description', 'expires_at', 'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'transaction_id');
    }

    public function loyaltyPointRule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPointRule::class);
    }
}
