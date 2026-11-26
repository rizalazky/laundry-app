<?php

namespace Tests\Feature;

use App\Actions\Attendance\ClockIn;
use App\Actions\Attendance\ClockOut;
use App\Filament\App\Pages\RolePermissions;
use App\Filament\App\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Journal;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Enums\StockMovementType;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
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

    public function test_staff_creation_records_safe_account_details_without_password(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Kasir Baru',
                'email' => 'kasir@example.test',
                'phone_whatsapp' => '628123456789',
                'password' => 'secret-password',
                'role' => 'cashier',
                'is_active' => true,
                'branches' => [$branch->id],
            ])
            ->call('create')
            ->assertHasNoErrors();

        $staff = User::query()->where('email', 'kasir@example.test')->sole();
        $log = ActivityLog::query()->where('action', 'staff.created')->sole();

        $this->assertSame($staff->id, $log->subject_id);
        $this->assertSame('Kasir Baru', $log->properties['name']);
        $this->assertArrayNotHasKey('password', $log->properties);
    }

    public function test_role_permission_matrix_changes_are_audited(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $permission = Permission::query()->create([
            'name' => 'Lihat Pesanan',
            'slug' => 'orders.view',
            'group' => 'Pesanan',
        ]);
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(RolePermissions::class)
            ->set('role', 'cashier')
            ->set('selectedPermissions', [(string) $permission->id])
            ->call('save')
            ->assertHasNoErrors();

        $log = ActivityLog::query()->where('action', 'staff.permissions.updated')->sole();

        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame(Tenant::class, $log->subject_type);
        $this->assertSame($tenant->id, $log->subject_id);
        $this->assertSame('cashier', $log->properties['role']);
        $this->assertSame([$permission->id], $log->properties['permission_ids']);
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

        $assignedOrder = Order::factory()->for($assignedBranch)->create();
        $visibleOrderLog = $logger->record($manager, 'order.status_changed', $assignedOrder);
        $visiblePayment = Payment::factory()->for($assignedOrder)->create();
        $visiblePaymentLog = $logger->record($manager, 'payment.recorded', $visiblePayment);

        $outsideOrder = Order::factory()->for($unassignedBranch)->create();
        $outsideOrderLog = $logger->record($manager, 'order.status_changed', $outsideOrder);

        [, $foreignOwner, $foreignEmployee] = $this->foreignContext();
        $this->actingAs($foreignOwner);
        $foreignLog = app(ActivityLogger::class)->record($foreignOwner, 'staff.updated', $foreignEmployee);
        $this->actingAs($manager);

        $this->assertTrue(ActivityLogResource::canAccess());
        $visibleIds = ActivityLogResource::getEloquentQuery()->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$visibleLog->id, $visibleOrderLog->id, $visiblePaymentLog->id],
            $visibleIds,
        );
        $this->assertNotContains($outsideLog->id, $visibleIds);
        $this->assertNotContains($outsideOrderLog->id, $visibleIds);
        $this->assertNotContains($foreignLog->id, $visibleIds);
    }

    public function test_stock_and_journal_activity_is_recorded_and_branch_manager_visibility_is_scoped(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create();
        $outsideBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);
        $this->grantPermission($tenant, 'branch_manager', 'staff.view');

        $unit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'decimal_places' => 2,
            'is_active' => true,
        ]);
        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'AUDIT-001',
            'name' => 'Bahan Audit',
            'unit' => 'Kilogram',
            'base_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'purchase_unit_conversion_factor' => 1,
            'item_category' => 'raw_material',
            'min_stock' => 0,
        ]);

        $assignedMovement = StockMovement::query()->withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $assignedBranch->id,
            'inventory_item_id' => $item->id,
            'movement_type' => StockMovementType::In,
            'quantity' => '5.0000',
            'stock_before' => '0.0000',
            'stock_after' => '5.0000',
            'unit_cost' => '0.00',
            'created_by' => $manager->id,
            'created_at' => now(),
        ]);

        $outsideMovement = StockMovement::query()->withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $outsideBranch->id,
            'inventory_item_id' => $item->id,
            'movement_type' => StockMovementType::In,
            'quantity' => '3.0000',
            'stock_before' => '0.0000',
            'stock_after' => '3.0000',
            'unit_cost' => '0.00',
            'created_by' => $manager->id,
            'created_at' => now(),
        ]);

        $assignedJournal = Journal::query()->withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $assignedBranch->id,
            'journal_date' => now(),
            'reference_type' => 'manual',
            'reference_id' => 901,
            'description' => 'Jurnal cabang terkelola',
            'created_by' => $manager->id,
        ]);

        $outsideJournal = Journal::query()->withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $outsideBranch->id,
            'journal_date' => now(),
            'reference_type' => 'manual',
            'reference_id' => 902,
            'description' => 'Jurnal cabang lain',
            'created_by' => $manager->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'inventory.stock_moved',
            'subject_type' => StockMovement::class,
            'subject_id' => $assignedMovement->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'accounting.journal_created',
            'subject_type' => Journal::class,
            'subject_id' => $assignedJournal->id,
        ]);

        $this->actingAs($manager);
        $visibleLogs = ActivityLogResource::getEloquentQuery()->get();

        $this->assertTrue($visibleLogs->contains(
            fn (ActivityLog $log): bool => $log->subject_type === StockMovement::class && (int) $log->subject_id === $assignedMovement->id,
        ));
        $this->assertTrue($visibleLogs->contains(
            fn (ActivityLog $log): bool => $log->subject_type === Journal::class && (int) $log->subject_id === $assignedJournal->id,
        ));
        $this->assertFalse($visibleLogs->contains(
            fn (ActivityLog $log): bool => $log->subject_type === StockMovement::class && (int) $log->subject_id === $outsideMovement->id,
        ));
        $this->assertFalse($visibleLogs->contains(
            fn (ActivityLog $log): bool => $log->subject_type === Journal::class && (int) $log->subject_id === $outsideJournal->id,
        ));
    }

    public function test_cashier_cannot_open_activity_log_resource(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();

        $this->actingAs($cashier);

        $this->assertFalse(ActivityLogResource::canAccess());
    }


    public function test_authorized_action_can_record_the_explicit_actor_when_auth_context_differs(): void
    {
        $tenant = Tenant::factory()->create();
        $authenticatedOwner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $cashierActor = User::factory()->for($tenant)->withRole('cashier')->create();

        $this->actingAs($authenticatedOwner);

        $log = app(ActivityLogger::class)->recordForAuthorizedActor(
            $cashierActor,
            'order.created',
            $cashierActor,
            ['source' => 'authorized_action'],
        );

        $this->assertSame($cashierActor->id, $log->user_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($cashierActor->id, $log->subject_id);
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
        $authenticatedUser = Auth::user();
        Auth::logout();

        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $employee = User::factory()->for($tenant)->withRole('cashier')->create();

        if ($authenticatedUser !== null) {
            Auth::login($authenticatedUser);
        }

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
