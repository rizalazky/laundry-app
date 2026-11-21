<?php

namespace App\Filament\App\Pages;

use App\Models\Permission;
use App\Services\RolePermissionService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RolePermissions extends Page
{
    protected static string $view = 'filament.app.pages.role-permissions';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Matriks Hak Akses';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 90;

    public string $role = 'cashier';

    /** @var array<int, int|string> */
    public array $selectedPermissions = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->role === 'tenant_owner'
            && Auth::user()?->is_active
            && Auth::user()?->tenant_id !== null;
    }

    public function mount(RolePermissionService $permissionService): void
    {
        $this->loadRolePermissions($permissionService);
    }

    public function updatedRole(RolePermissionService $permissionService): void
    {
        if (! in_array($this->role, RolePermissionService::CONFIGURABLE_ROLES, true)) {
            $this->role = 'cashier';
        }

        $this->loadRolePermissions($permissionService);
    }

    public function getPermissionGroups(): array
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy('group')
            ->all();
    }

    public function save(RolePermissionService $permissionService): void
    {
        $user = Auth::user();

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

        Notification::make()
            ->title('Matriks hak akses berhasil disimpan')
            ->success()
            ->send();
    }

    protected function loadRolePermissions(RolePermissionService $permissionService): void
    {
        $user = Auth::user();

        abort_unless($user?->tenant_id !== null, 403);

        $this->selectedPermissions = $permissionService->permissionIdsFor(
            $user->tenant,
            $this->role,
        );
    }
}
