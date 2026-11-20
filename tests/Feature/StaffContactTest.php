<?php

namespace Tests\\Feature;

use App\\Filament\\App\\Resources\\Users\\Pages\\CreateUser;
use App\\Models\\Branch;
use App\\Models\\Tenant;
use App\\Models\\User;
use Filament\\Facades\\Filament;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Livewire\\Livewire;
use Tests\\TestCase;

class StaffContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_a_staff_whatsapp_number(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Staff WhatsApp',
                'email' => 'staff-whatsapp@example.test',
                'phone_whatsapp' => '628123456789',
                'password' => 'strong-password',
                'role' => 'cashier',
                'is_active' => true,
                'branches' => [$branch->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->id,
            'email' => 'staff-whatsapp@example.test',
            'phone_whatsapp' => '628123456789',
        ]);
    }
}
