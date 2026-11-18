<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasTenants, HasDefaultTenant
{
    use Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->role === 'super_admin',
            'app'   => in_array($this->role, ['tenant_owner', 'branch_manager', 'cashier', 'operator'])
                && $this->tenant_id !== null,
            default => false,
        };
    }

    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->role === 'tenant_owner') {
            return Branch::where('tenant_id', $this->tenant_id)
                ->where('is_active', true)
                ->get();
        }

        return $this->branches()->where('is_active', true)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->role === 'tenant_owner') {
            return (int) $tenant->tenant_id === (int) $this->tenant_id;
        }

        return $this->branches()->whereKey($tenant->getKey())->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->getTenants($panel)->first();
    }
}