<?php

namespace Tests\Feature;

use App\Filament\App\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\App\Resources\AffiliateReferrals\AffiliateReferralResource;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Filament\App\Resources\EmployeeLoans\EmployeeLoanResource;
use App\Filament\App\Resources\Investors\InvestorResource;
use App\Filament\App\Resources\MassInvoices\MassInvoiceResource;
use App\Filament\App\Resources\MassInvoiceSchedules\MassInvoiceScheduleResource;
use App\Filament\App\Resources\ProfitSharings\ProfitSharingResource;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\EmployeeLoan;
use App\Models\Investor;
use App\Models\MassInvoice;
use App\Models\MassInvoiceSchedule;
use App\Models\Permission;
use App\Models\ProfitSharing;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceTenantBoundaryPenetrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_resource_is_scoped_to_tenant_and_managed_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $managedBranch = Branch::factory()->for($tenant)->create();
        $unmanagedBranch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();

        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($managedBranch);

        $managedEmployee = User::factory()->for($tenant)->withRole('operator')->create();
        $unmanagedEmployee = User::factory()->for($tenant)->withRole('operator')->create();
        $foreignEmployee = User::factory()->for($foreignTenant)->withRole('operator')->create();

        $managedAttendance = Attendance::factory()
            ->forUser($managedEmployee)
            ->forBranch($managedBranch)
            ->create();
        Attendance::factory()->forUser($unmanagedEmployee)->forBranch($unmanagedBranch)->create();
        Attendance::factory()->forUser($foreignEmployee)->forBranch($foreignBranch)->create();

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($managedBranch);

        $this->assertSame(
            [$managedAttendance->id],
            AttendanceResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_employee_loan_resource_excludes_unmanaged_and_foreign_employees(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $managedBranch = Branch::factory()->for($tenant)->create();
        $unmanagedBranch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();

        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($managedBranch);

        $managedEmployee = User::factory()->for($tenant)->withRole('operator')->create();
        $managedEmployee->branches()->attach($managedBranch);

        $sharedEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $sharedEmployee->branches()->attach([$managedBranch->id, $unmanagedBranch->id]);

        $unmanagedEmployee = User::factory()->for($tenant)->withRole('operator')->create();
        $unmanagedEmployee->branches()->attach($unmanagedBranch);

        $foreignEmployee = User::factory()->for($foreignTenant)->withRole('operator')->create();
        $foreignEmployee->branches()->attach($foreignBranch);

        $managedLoan = $this->createLoan($tenant, $managedEmployee, $manager);
        $this->createLoan($tenant, $sharedEmployee, $manager);
        $this->createLoan($tenant, $unmanagedEmployee, $manager);
        $this->createLoan($foreignTenant, $foreignEmployee, $foreignEmployee);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');
        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($managedBranch);

        $this->assertSame(
            [$managedLoan->id],
            EmployeeLoanResource::getEloquentQuery()->pluck('id')->all(),
        );

        $this->assertSame(
            [$managedEmployee->id => $managedEmployee->name],
            EmployeeLoanResource::manageableEmployeeOptions(),
        );
    }

    public function test_mass_invoice_schedule_and_invoice_resources_exclude_foreign_tenant_records(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $localSchedule = $this->createSchedule($tenant, 'Local Schedule');
        $foreignSchedule = $this->createSchedule($foreignTenant, 'Foreign Schedule');

        $localInvoice = $this->createMassInvoice($tenant, $localSchedule, 'LOCAL-MASS-001');
        $foreignInvoice = $this->createMassInvoice($foreignTenant, $foreignSchedule, 'FOREIGN-MASS-001');

        $this->actingAs($owner);

        $this->assertSame(
            [$localSchedule->id],
            MassInvoiceScheduleResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertFalse(MassInvoiceScheduleResource::canEdit($foreignSchedule));
        $this->assertFalse(MassInvoiceScheduleResource::canDelete($foreignSchedule));

        $this->assertSame(
            [$localInvoice->id],
            MassInvoiceResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_investor_and_profit_sharing_resources_exclude_foreign_tenant_records(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $localInvestor = $this->createInvestor($tenant, $branch, 'Local Investor');
        $foreignInvestor = $this->createInvestor($foreignTenant, $foreignBranch, 'Foreign Investor');

        $localSharing = $this->createProfitSharing($tenant, $localInvestor, 'LOCAL-2026-10');
        $foreignSharing = $this->createProfitSharing($foreignTenant, $foreignInvestor, 'FOREIGN-2026-10');

        $this->actingAs($owner);

        $this->assertSame(
            [$localInvestor->id],
            InvestorResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertSame(
            [$localSharing->id],
            ProfitSharingResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertNotContains($foreignSharing->id, ProfitSharingResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_activity_log_resource_does_not_disclose_foreign_tenant_subject_metadata(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();

        $localLog = ActivityLog::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'action' => 'order.updated',
            'subject_type' => User::class,
            'subject_id' => $owner->id,
            'properties' => ['status' => 'ready'],
        ]);
        $foreignLog = ActivityLog::query()->create([
            'tenant_id' => $foreignTenant->id,
            'user_id' => $foreignOwner->id,
            'action' => 'order.updated',
            'subject_type' => User::class,
            'subject_id' => $foreignOwner->id,
            'properties' => ['secret' => 'foreign-tenant-data'],
        ]);

        $this->actingAs($owner);

        $visibleIds = ActivityLogResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$localLog->id], $visibleIds);
        $this->assertNotContains($foreignLog->id, $visibleIds);
    }

    public function test_affiliate_referral_resource_is_scoped_by_referrer_tenant_not_referred_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignReferrer = Tenant::factory()->create();
        $localReferred = Tenant::factory()->create();
        $foreignReferred = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $localReferral = \App\Models\AffiliateReferral::query()->create([
            'referrer_tenant_id' => $tenant->id,
            'referred_tenant_id' => $localReferred->id,
            'commission_rate' => '10.00',
            'status' => 'pending',
            'referred_at' => now(),
        ]);
        $foreignReferral = \App\Models\AffiliateReferral::query()->create([
            'referrer_tenant_id' => $foreignReferrer->id,
            'referred_tenant_id' => $foreignReferred->id,
            'commission_rate' => '10.00',
            'status' => 'pending',
            'referred_at' => now(),
        ]);

        $this->actingAs($owner);

        $this->assertSame(
            [$localReferral->id],
            AffiliateReferralResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertNotContains($foreignReferral->id, AffiliateReferralResource::getEloquentQuery()->pluck('id')->all());
    }

    private function createLoan(Tenant $tenant, User $employee, User $creator): EmployeeLoan
    {
        return EmployeeLoan::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $employee->id,
            'created_by' => $creator->id,
            'amount' => '500000.00',
            'remaining_amount' => '500000.00',
            'status' => 'active',
            'issued_at' => now(),
        ]);
    }

    private function createSchedule(Tenant $tenant, string $name): MassInvoiceSchedule
    {
        return MassInvoiceSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'frequency' => 'monthly',
            'run_day' => 1,
            'next_run_at' => now()->addMonth(),
            'is_active' => true,
        ]);
    }

    private function createMassInvoice(Tenant $tenant, MassInvoiceSchedule $schedule, string $invoiceNo): MassInvoice
    {
        return MassInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'mass_invoice_schedule_id' => $schedule->id,
            'customer_id' => \App\Models\Customer::factory()->for($tenant)->create()->id,
            'invoice_no' => $invoiceNo,
            'billing_period_start' => now()->startOfMonth()->toDateString(),
            'billing_period_end' => now()->endOfMonth()->toDateString(),
            'total_amount' => '25000.00',
            'status' => 'issued',
            'trigger_type' => 'manual',
            'generated_at' => now(),
        ]);
    }

    private function createInvestor(Tenant $tenant, Branch $branch, string $name): Investor
    {
        return Investor::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => $name,
            'capital_amount' => '1000000.00',
            'share_percent' => '20.00',
            'is_active' => true,
        ]);
    }

    private function createProfitSharing(Tenant $tenant, Investor $investor, string $period): ProfitSharing
    {
        return ProfitSharing::query()->create([
            'tenant_id' => $tenant->id,
            'investor_id' => $investor->id,
            'period_month' => $period,
            'net_profit_base' => '100000.00',
            'share_percent_snapshot' => '20.00',
            'payout_amount' => '20000.00',
            'status' => ProfitSharing::STATUS_DRAFT,
        ]);
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Security'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
