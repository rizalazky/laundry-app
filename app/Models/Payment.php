<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only ledger entry. A correction is recorded as a new Refund entry,
 * never by editing or deleting an existing row.
 */
class Payment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'order_id',
        'received_by',
        'type',
        'method',
        'amount',
        'paid_at',
        'reference',
        'notes',
        'idempotency_key',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Payment ledger entries cannot be modified.');
        });

        static::deleting(function (): never {
            throw new LogicException('Payment ledger entries cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
