<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RolePermissionService
{
    /**
     * Roles whose permissions may be configured by a tenant owner.
     * Tenant owners retain full access and cannot be restricted by this matrix.
     */
    public const CONFIGURABLE_ROLES = [
        'branch_manager',
        'cashier',
        'operator',
    ];

    /**
     * Replace the permission matrix for one role inside a tenant.
     *
     * @param  array<int, int|string>  $permissionIds
     */
    public function sync(Tenant $tenant, string $role, array $permissionIds): void
    {
        if (! in_array($role, self::CONFIGURABLE_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'Role ini tidak dapat dikonfigurasi melalui matriks izin tenant.',
            ]);
        }

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

        if (count($permissionIds) !== Permission::query()->whereKey($permissionIds)->count()) {
            throw ValidationException::withMessages([
                'permissions' => 'Satu atau lebih permission tidak valid.',
            ]);
        }

        DB::transaction(function () use ($tenant, $role, $permissionIds): void {
            RolePermission::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('role', $role)
                ->delete();

            if ($permissionIds === []) {
                return;
            }

            $now = now();

            RolePermission::query()->insert(
                array_map(
                    fn (int $permissionId): array => [
                        'tenant_id' => $tenant->getKey(),
                        'role' => $role,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $permissionIds,
                ),
            );
        });
    }

    /**
     * @return array<int, int>
     */
    public function permissionIdsFor(Tenant $tenant, string $role): array
    {
        return RolePermission::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('role', $role)
            ->orderBy('permission_id')
            ->pluck('permission_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
