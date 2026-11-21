<?php

namespace Tests\Feature;

use App\Actions\Attendance\GetTodayAttendanceSummary;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayAttendanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_sees_company_wide_unique_attendance_counts_for_today(): void
    {
        $this->travelTo('2026-11-22 12:00:00');

        $tenant = Tenant::factory()->create();
        $firstBranch = Branch::factory()->for($tenant)->create();
        $secondBranch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $workingEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $finishedEmployee = User::factory()->for($tenant)->withRole('operator')->create();

        Attendance::factory()->forUser($workingEmployee)->forBranch($firstBranch)->create([
            'clock_in' => now()->setTime(8, 0),
            'clock_out' => null,
        ]);
        Attendance::factory()->forUser($workingEmployee)->forBranch($firstBranch)->create([
            'clock_in' => now()->setTime(6, 0),
            'clock_out' => now()->setTime(7, 0),
        ]);
        Attendance::factory()->forUser($finishedEmployee)->forBranch($secondBranch)->create([
            'clock_in' => now()->setTime(8, 30),
            'clock_out' => now()->setTime(11, 0),
        ]);
        Attendance::factory()->forUser($finishedEmployee)->forBranch($secondBranch)->create([
            'clock_in' => now()->subDay()->setTime(8, 0),
            'clock_out' => now()->subDay()->setTime(17, 0),
        ]);

        $this->actingAs($owner);

        $summary = app(GetTodayAttendanceSummary::class)->handle($owner);

        $this->assertSame([
            'present' => 2,
            'on_duty' => 1,
            'clocked_out' => 1,
        ], $summary);
    }

    public function test_branch_manager_sees_only_attendance_from_assigned_active_branches(): void
    {
        $this->travelTo('2026-11-22 12:00:00');

        $tenant = Tenant::factory()->create();
        $managedBranch = Branch::factory()->for($tenant)->create();
        $unmanagedBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($managedBranch);

        $managedEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $unmanagedEmployee = User::factory()->for($tenant)->withRole('cashier')->create();

        Attendance::factory()->forUser($managedEmployee)->forBranch($managedBranch)->create([
            'clock_in' => now()->setTime(8, 0),
            'clock_out' => null,
        ]);
        Attendance::factory()->forUser($unmanagedEmployee)->forBranch($unmanagedBranch)->create([
            'clock_in' => now()->setTime(8, 0),
            'clock_out' => now()->setTime(9, 0),
        ]);

        $this->actingAs($manager);

        $summary = app(GetTodayAttendanceSummary::class)->handle($manager);

        $this->assertSame([
            'present' => 1,
            'on_duty' => 1,
            'clocked_out' => 0,
        ], $summary);
    }

    public function test_cashier_sees_only_their_own_attendance_summary(): void
    {
        $this->travelTo('2026-11-22 12:00:00');

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $otherEmployee = User::factory()->for($tenant)->withRole('operator')->create();

        Attendance::factory()->forUser($cashier)->forBranch($branch)->create([
            'clock_in' => now()->setTime(8, 0),
            'clock_out' => null,
        ]);
        Attendance::factory()->forUser($otherEmployee)->forBranch($branch)->create([
            'clock_in' => now()->setTime(8, 0),
            'clock_out' => now()->setTime(9, 0),
        ]);

        $this->actingAs($cashier);

        $summary = app(GetTodayAttendanceSummary::class)->handle($cashier);

        $this->assertSame([
            'present' => 1,
            'on_duty' => 1,
            'clocked_out' => 0,
        ], $summary);
    }
}
