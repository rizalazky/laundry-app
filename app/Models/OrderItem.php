<?php

namespace App\Models;

use App\Enums\ServiceUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            $user = Auth::user();

            if ($user === null || $user->role === 'super_admin') {
                return;
            }

            if ($user->tenant_id === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->whereHas('order', fn (Builder $orders) => $orders->where('tenant_id', $user->tenant_id));
        });

        static::creating(function (OrderItem $orderItem): void {
            $user = Auth::user();

            if (
                $user !== null
                && $user->role !== 'super_admin'
                && ($user->tenant_id === null || ! Order::query()->whereKey($orderItem->order_id)->exists())
            ) {
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
