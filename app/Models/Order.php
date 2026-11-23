<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Concerns\BelongsToTenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Status and payment aggregates are intentionally not mass assignable:
     * they are changed only through the order actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'workshop_id',
        'customer_id',
        'created_by',
        'invoice_no',
        'order_source',
        'order_date',
        'due_at',
        'subtotal',
        'discount',
        'promo_id',
        'voucher_id',
        'promo_name_snapshot',
        'voucher_code_snapshot',
        'promo_discount',
        'total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'order_source' => OrderSource::class,
            'payment_status' => PaymentStatus::class,
            'order_date' => 'datetime',
            'due_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'promo_discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'workshop_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function productionLogs(): HasMany
    {
        return $this->hasMany(ProductionLog::class);
    }

    public function commissionLogs(): HasMany
    {
        return $this->hasMany(CommissionLog::class);
    }

    public function balanceDue(): string
    {
        return (string) BigDecimal::of($this->total)
            ->minus($this->paid_amount)
            ->toScale(2);
    }

    /**
     * Rebuild the cached paid amount and payment status from the payment
     * ledger. The ledger stays the source of truth; call inside a transaction
     * that holds a lock on the order row.
     */
    public function recalculatePayment(): void
    {
        $netPaid = $this->payments()
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN -amount ELSE amount END), 0) as net', [PaymentType::Refund->value])
            ->value('net');

        // SQLite returns SUM() as float and BigDecimal::of() drops the fraction of floats.
        $netPaid = BigDecimal::of(number_format((float) $netPaid, 2, '.', ''));

        $this->forceFill([
            'paid_amount' => (string) $netPaid,
            'payment_status' => PaymentStatus::fromAmounts($this->total, $netPaid),
        ])->save();
    }
}
