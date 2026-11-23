<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasFactory, Notifiable, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone_whatsapp',
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

    public function hasPermissionTo(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (in_array($this->role, ['super_admin', 'tenant_owner'], true)) {
            return true;
        }

        if ($this->tenant_id === null) {
            return false;
        }

        return RolePermission::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('role', $this->role)
            ->whereHas('permission', fn ($query) => $query->where('slug', $permission))
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->role === 'super_admin',
            'app' => in_array($this->role, ['tenant_owner', 'branch_manager', 'cashier', 'operator', 'courier'], true)
                && $this->tenant_id !== null,
            default => false,
        };
    }

    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->role === 'tenant_owner') {
            return Branch::query()
                ->where('tenant_id', $this->tenant_id)
                ->where('is_active', true)
                ->get();
        }

        return $this->branches()
            ->where('branches.tenant_id', $this->tenant_id)
            ->where('branches.is_active', true)
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (
            ! $this->is_active
            || $this->tenant_id === null
            || (int) $tenant->tenant_id !== (int) $this->tenant_id
        ) {
            return false;
        }

        if ($this->role === 'tenant_owner') {
            return (bool) $tenant->is_active;
        }

        return $this->branches()
            ->whereKey($tenant->getKey())
            ->where('branches.tenant_id', $this->tenant_id)
            ->where('branches.is_active', true)
            ->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->getTenants($panel)->first();
    }
}
