<?php

namespace Tests\Feature;

use App\Actions\Attendance\ClockIn;
use App\Actions\Attendance\ClockOut;
use App\Filament\App\Resources\ActivityLogs\ActivityLogResource;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_actions_record_clock_in_and_clock_out_activity(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $employee = User::factory()->for($tenant)->withRole('cashier')->create();
        $employee->branches()->attach($branch);
        $this->actingAs($employee);

        $attendance = app(ClockIn::class)->handle($employee, $branch);
        app(ClockOut::class)->handle($employee, $branch);

        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $tenant->id,
            'user_id' => $employee->id,
            'action' => 'attendance.clocked_in',
            'subject_type' => \App\Models\Attendance::class,
            'subject_id' => $attendance->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $tenant->id,
            'user_id' => $employee->id,
            'action' => 'attendance.clocked_out',
            'subject_type' => \App\Models\Attendance::class,
            'subject_id' => $attendance->id,
        ]);
    }

    public function test_activity_log_resource_is_tenant_and_branch_scoped(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create();
        $unassignedBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);

        $assignedEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $assignedEmployee->branches()->attach($assignedBranch);

        $outsideEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $outsideEmployee->branches()->attach($unassignedBranch);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->actingAs($manager);

        $logger = app(ActivityLogger::class);
        $visibleLog = $logger->record($manager, 'staff.updated', $assignedEmployee, ['role' => 'cashier']);
        $outsideLog = $logger->record($manager, 'staff.updated', $outsideEmployee, ['role' => 'cashier']);

        [, $foreignOwner, $foreignEmployee] = $this->foreignContext();
        $this->actingAs($foreignOwner);
        $foreignLog = app(ActivityLogger::class)->record($foreignOwner, 'staff.updated', $foreignEmployee);
        $this->actingAs($manager);

        $this->assertTrue(ActivityLogResource::canAccess());
        $this->assertSame(
            [$visibleLog->id],
            ActivityLogResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertNotContains($outsideLog->id, ActivityLogResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($foreignLog->id, ActivityLogResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_cashier_cannot_open_activity_log_resource(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();

        $this->actingAs($cashier);

        $this->assertFalse(ActivityLogResource::canAccess());
    }

    public function test_activity_logger_rejects_a_subject_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        [, , $foreignEmployee] = $this->foreignContext();

        $this->actingAs($actor);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Aktivitas lintas tenant tidak diizinkan.');

        app(ActivityLogger::class)->record($actor, 'staff.updated', $foreignEmployee);
    }

    private function foreignContext(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $employee = User::factory()->for($tenant)->withRole('cashier')->create();

        return [$tenant, $owner, $employee];
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Karyawan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
