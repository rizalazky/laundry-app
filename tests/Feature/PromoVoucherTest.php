<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Promos\Pages\CreatePromo;
use App\Filament\App\Resources\Vouchers\Pages\CreateVoucher;
use App\Models\Permission;
use App\Models\Promo;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromoVoucherTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_promo_and_voucher_from_filament_forms(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreatePromo::class)
            ->fillForm([
                'name' => 'Promo Laundry Hemat',
                'category' => Promo::CATEGORY_BASIC,
                'reward_type' => Promo::REWARD_PERCENT,
                'reward_value' => 15,
                'is_active' => true,
                'rules' => ['minimum_order' => '50000'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promo = Promo::query()->where('name', 'Promo Laundry Hemat')->firstOrFail();

        Livewire::test(CreateVoucher::class)
            ->fillForm([
                'code' => 'HEMAT15',
                'voucher_type' => Voucher::TYPE_GENERAL,
                'promo_id' => $promo->id,
                'quota' => 100,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('promos', [
            'tenant_id' => $tenant->id,
            'name' => 'Promo Laundry Hemat',
            'reward_value' => 15,
        ]);
        $this->assertDatabaseHas('vouchers', [
            'tenant_id' => $tenant->id,
            'promo_id' => $promo->id,
            'code' => 'HEMAT15',
            'used_count' => 0,
        ]);
    }

    public function test_promo_and_voucher_lists_are_isolated_by_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        Promo::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Promo Lokal',
            'category' => Promo::CATEGORY_GENERAL,
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => 10000,
        ]);
        Promo::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Promo Tenant Lain',
            'category' => Promo::CATEGORY_GENERAL,
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => 10000,
        ]);

        Voucher::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'LOKAL10',
            'voucher_type' => Voucher::TYPE_GENERAL,
        ]);
        Voucher::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'RAHASIA10',
            'voucher_type' => Voucher::TYPE_GENERAL,
        ]);

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/promos')
            ->assertOk()
            ->assertSee('Promo Lokal')
            ->assertDontSee('Promo Tenant Lain');

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/vouchers')
            ->assertOk()
            ->assertSee('LOKAL10')
            ->assertDontSee('RAHASIA10');
    }

    public function test_branch_manager_needs_promotion_permissions_to_access_promotion_resources(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        $this->actingAs($manager)
            ->get('/app/'.$branch->code.'/promos')
            ->assertForbidden();

        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'promotions.view'],
            ['name' => 'Lihat Promo dan Voucher', 'group' => 'Promosi'],
        );
        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'branch_manager',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($manager)
            ->get('/app/'.$branch->code.'/promos')
            ->assertOk();
    }

    public function test_voucher_remaining_quota_is_never_negative_and_unlimited_quota_is_null(): void
    {
        $tenant = Tenant::factory()->create();
        $limited = Voucher::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'LIMITED',
            'voucher_type' => Voucher::TYPE_GENERAL,
            'quota' => 5,
            'used_count' => 7,
        ]);
        $unlimited = Voucher::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'UNLIMITED',
            'voucher_type' => Voucher::TYPE_REFERRAL,
        ]);

        $this->assertSame(0, $limited->remainingQuota());
        $this->assertNull($unlimited->remainingQuota());
    }
}
