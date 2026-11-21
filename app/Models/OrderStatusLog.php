<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class OrderStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'user_id',
        'worker_id',
        'machine_code',
        'from_status',
        'to_status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
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

        static::creating(function (OrderStatusLog $statusLog): void {
            $user = Auth::user();

            if (
                $user !== null
                && $user->role !== 'super_admin'
                && ($user->tenant_id === null || ! Order::query()->whereKey($statusLog->order_id)->exists())
            ) {
                throw (new ModelNotFoundException())->setModel(Order::class, [$statusLog->order_id]);
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }
}
