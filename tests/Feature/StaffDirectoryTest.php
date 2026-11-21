<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Users\Pages\ListUsers;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_filter_staff_by_role_and_account_status(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create([
            'name' => 'Active Cashier',
            'is_active' => true,
        ]);
        $operator = User::factory()->for($tenant)->withRole('operator')->create([
            'name' => 'Inactive Operator',
            'is_active' => false,
        ]);
        $foreignStaff = User::factory()->withRole('cashier')->create([
            'name' => 'Foreign Tenant Staff',
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$cashier, $operator])
            ->assertCanNotSeeTableRecords([$foreignStaff])
            ->filterTable('role', 'cashier')
            ->assertCanSeeTableRecords([$cashier])
            ->assertCanNotSeeTableRecords([$operator]);

        Livewire::test(ListUsers::class)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$cashier])
            ->assertCanNotSeeTableRecords([$operator]);
    }
}
