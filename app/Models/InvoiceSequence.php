<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class InvoiceSequence extends Model
{
    protected $fillable = [
        'branch_id',
        'sequence_date',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'sequence_date' => 'date',
            'last_number' => 'integer',
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

            $builder->whereHas('branch', fn (Builder $branches) => $branches->where('tenant_id', $user->tenant_id));
        });

        static::creating(function (InvoiceSequence $sequence): void {
            $user = Auth::user();

            if (
                $user !== null
                && $user->role !== 'super_admin'
                && ($user->tenant_id === null || ! Branch::query()->whereKey($sequence->branch_id)->exists())
            ) {
                throw (new ModelNotFoundException())->setModel(Branch::class, [$sequence->branch_id]);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
