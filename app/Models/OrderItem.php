<?php

namespace App\Models;

use App\Enums\ServiceUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'service_id',
        'service_name',
        'unit',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit' => ServiceUnit::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = Auth::user()?->tenant_id;

            if ($tenantId !== null) {
                $builder->whereHas('order', fn (Builder $orders) => $orders->where('tenant_id', $tenantId));
            }
        });

        static::creating(function (OrderItem $orderItem): void {
            $tenantId = Auth::user()?->tenant_id;

            if ($tenantId !== null && ! Order::query()->whereKey($orderItem->order_id)->exists()) {
                throw (new ModelNotFoundException())->setModel(Order::class, [$orderItem->order_id]);
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }
}
