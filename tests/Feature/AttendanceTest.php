<?php

namespace Tests\Feature;

use App\Actions\Attendance\ClockIn;
use App\Actions\Attendance\ClockOut;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_clock_in_and_clock_out_at_an_assigned_branch(): void
    {
        [$tenant, $branch, $user] = $this->context();
        $this->actingAs($user);
        $this->travelTo('2026-11-22 08:00:00');

        $attendance = app(ClockIn::class)->handle($user, $branch);

        $this->assertSame($tenant->id, $attendance->tenant_id);
        $this->assertSame($branch->id, $attendance->branch_id);
        $this->assertSame($user->id, $attendance->user_id);
        $this->assertSame('present', $attendance->status);
        $this->assertNull($attendance->clock_out);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'clock_in' => '2026-11-22 08:00:00',
        ]);

        $this->travel(8)->hours();

        $closedAttendance = app(ClockOut::class)->handle($user, $branch);

        $this->assertTrue($closedAttendance->clock_out->greaterThan($closedAttendance->clock_in));
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'clock_out' => '2026-11-22 16:00:00',
        ]);
    }

    public function test_staff_cannot_open_a_second_attendance_while_another_is_active(): void
    {
        [, $branch, $user] = $this->context();
        $this->actingAs($user);

        app(ClockIn::class)->handle($user, $branch);

        try {
            app(ClockIn::class)->handle($user, $branch);
            $this->fail('A staff member cannot have two open attendance records.');
        } catch (DomainException) {
            $this->assertSame(1, Attendance::query()->where('user_id', $user->id)->count());
        }
    }

    public function test_staff_cannot_clock_in_to_a_foreign_or_unassigned_branch(): void
    {
        [$tenant, , $user] = $this->context();
        $unassignedBranch = Branch::factory()->for($tenant)->create();
        $foreignTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $this->actingAs($user);

        foreach ([$unassignedBranch, $foreignBranch] as $branch) {
            try {
                app(ClockIn::class)->handle($user, $branch);
                $this->fail('An unassigned or foreign branch must be rejected.');
            } catch (DomainException) {
                $this->assertDatabaseCount('attendances', 0);
            }
        }
    }

    public function test_clock_out_requires_an_open_attendance_and_cannot_precede_clock_in(): void
    {
        [, $branch, $user] = $this->context();
        $this->actingAs($user);
        $this->travelTo('2026-11-22 08:00:00');

        try {
            app(ClockOut::class)->handle($user, $branch);
            $this->fail('Clock-out without an active attendance must be rejected.');
        } catch (DomainException) {
            $this->assertDatabaseCount('attendances', 0);
        }

        app(ClockIn::class)->handle($user, $branch);

        try {
            app(ClockOut::class)->handle($user, $branch, now()->subMinute());
            $this->fail('Clock-out cannot precede clock-in.');
        } catch (DomainException) {
            $this->assertNull(Attendance::query()->sole()->clock_out);
        }
    }

    public function test_staff_only_sees_own_attendance_while_branch_manager_sees_assigned_branch_records(): void
    {
        [$tenant, $branch, $cashier] = $this->context();
        $otherCashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $otherCashier->branches()->attach($branch);

        Attendance::factory()->forUser($cashier)->forBranch($branch)->create();
        Attendance::factory()->forUser($otherCashier)->forBranch($branch)->create();

        $this->actingAs($cashier);

        $this->assertSame(
            [$cashier->id],
            AttendanceResource::getEloquentQuery()->pluck('user_id')->all(),
        );

        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);
        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->actingAs($manager);

        $this->assertCount(2, AttendanceResource::getEloquentQuery()->get());
    }

    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->withRole('cashier')->create();
        $user->branches()->attach($branch);

        return [$tenant, $branch, $user];
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
