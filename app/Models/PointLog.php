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

    protected $fillable = [
        'tenant_id', 'customer_id', 'transaction_id', 'type', 'points', 'description',
    ];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'transaction_id');
    }
}
