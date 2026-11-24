<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAdjustment extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPE_REFUND = 'refund';
    public const TYPE_COMPENSATION = 'compensation';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id', 'branch_id', 'order_id', 'type', 'status',
        'requested_amount', 'approved_amount', 'reason', 'notes',
        'requested_by', 'approved_by', 'paid_by', 'requested_at',
        'approved_at', 'paid_at', 'rejected_at', 'rejection_reason',
        'payment_id', 'expense_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
}
