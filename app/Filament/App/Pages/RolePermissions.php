<?php

namespace App\Filament\App\Pages;

use App\Models\Permission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\RolePermissionService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use BackedEnum;
use UnitEnum;

class RolePermissions extends Page
{
    protected string $view = 'filament.app.pages.role-permissions';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Matriks Hak Akses';

    protected static string|UnitEnum|null $navigationGroup = 'Karyawan & SDM';
    protected static ?int $navigationSort = 50;


    public string $role = 'cashier';

    /** @var array<int, int|string> */
    public array $selectedPermissions = [];

    public static function canAccess(): bool
    {
        $user = static::authenticatedActor();

        return $user !== null
            && $user->is_active
            && $user->role === 'tenant_owner'
            && $user->tenant_id !== null;
    }

    private static function authenticatedActor(): ?User
    {
        $authenticatedUserId = Auth::id();

        return $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);
    }

    /**
     * Re-check access on every Livewire request. A page mounted by an owner
     * must not remain usable after that account is deactivated or demoted.
     */
    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(RolePermissionService $permissionService): void
    {
        abort_unless(static::canAccess(), 403);

        $this->loadRolePermissions($permissionService);
    }

    public function updatedRole(RolePermissionService $permissionService): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($this->role, RolePermissionService::CONFIGURABLE_ROLES, true)) {
            $this->role = 'cashier';
        }

        $this->loadRolePermissions($permissionService);
    }

    public function getPermissionGroups(): array
    {
        abort_unless(static::canAccess(), 403);

        return Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy('group')
            ->all();
    }

    public function save(RolePermissionService $permissionService, ActivityLogger $activityLogger): void
    {
        $user = static::authenticatedActor();

        abort_unless(
            $user !== null
                && $user->role === 'tenant_owner'
                && $user->is_active
                && $user->tenant_id !== null,
            403,
        );

        if (! in_array($this->role, RolePermissionService::CONFIGURABLE_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'Role yang dipilih tidak dapat dikonfigurasi.',
            ]);
        }

        $permissionService->sync($user->tenant, $this->role, $this->selectedPermissions);

        $activityLogger->record($user, 'staff.permissions.updated', $user->tenant, [
            'role' => $this->role,
            'permission_ids' => array_values(array_unique(array_map('intval', $this->selectedPermissions))),
        ]);

        Notification::make()
            ->title('Matriks hak akses berhasil disimpan')
            ->success()
            ->send();
    }

    protected function loadRolePermissions(RolePermissionService $permissionService): void
    {
        abort_unless(static::canAccess(), 403);

        $user = static::authenticatedActor();

        abort_unless($user?->tenant_id !== null, 403);

        $this->selectedPermissions = $permissionService->permissionIdsFor(
            $user->tenant,
            $this->role,
        );
    }
}
