<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_branch_user_and_permission_schema_is_available(): void
    {
        foreach (['tenants', 'branches', 'users', 'branch_user', 'permissions', 'role_permissions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} table to exist.");
        }

        $expectedColumns = [
            'tenants' => ['id', 'name', 'slug', 'owner_email', 'plan', 'max_branches', 'is_active'],
            'branches' => ['id', 'tenant_id', 'code', 'name', 'is_workshop', 'is_active'],
            'users' => ['id', 'tenant_id', 'role', 'is_active'],
            'branch_user' => ['branch_id', 'user_id'],
            'permissions' => ['id', 'name', 'slug', 'group'],
            'role_permissions' => ['id', 'tenant_id', 'role', 'permission_id'],
        ];

        foreach ($expectedColumns as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Expected {$table}.{$column} column to exist.",
                );
            }
        }
    }
}
