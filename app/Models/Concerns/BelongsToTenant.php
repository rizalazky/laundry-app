<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

            if ($model->exists) {
                $persistedTenantId = $model->getRawOriginal('tenant_id');

                if ($persistedTenantId === null && $model->getKey() !== null) {
                    $persistedTenantId = DB::table($model->getTable())
                        ->where($model->getKeyName(), $model->getKey())
                        ->value('tenant_id');
                }

                if ($persistedTenantId === null || (int) $persistedTenantId !== (int) $user->tenant_id) {
                    throw new AuthorizationException('Anda tidak memiliki akses untuk mengubah data tenant lain.');
                }
            }

            // New records inherit the authenticated tenant. Existing records
            // must already belong to that tenant before any attributes are changed.
            $model->tenant_id = $user->tenant_id;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
