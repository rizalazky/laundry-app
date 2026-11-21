<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
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

            $builder->where(
                $builder->qualifyColumn('tenant_id'),
                $user->tenant_id,
            );
        });

        static::saving(function (Model $model): void {
            $user = Auth::user();

            if ($user === null || $user->role === 'super_admin') {
                return;
            }

            if ($user->tenant_id === null) {
                throw new AuthorizationException('Akun tidak terhubung ke tenant.');
            }

            $model->tenant_id = $user->tenant_id;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
