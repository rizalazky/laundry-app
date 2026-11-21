<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (Auth::hasUser() && Auth::user()->tenant_id !== null) {
                $builder->where(
                    $builder->qualifyColumn('tenant_id'),
                    Auth::user()->tenant_id
                );
            }
        });

        static::saving(function (Model $model) {
            if (Auth::hasUser() && Auth::user()->tenant_id !== null) {
                $model->tenant_id = Auth::user()->tenant_id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
