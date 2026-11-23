<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Promo;
use App\Models\Raffle;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionResourceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_tenant_promo_voucher_and_raffle_ids_cannot_open_edit_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['code' => 'LOCAL']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $foreignPromo = Promo::query()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Promo Rahasia',
            'category' => Promo::CATEGORY_GENERAL,
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => 5000,
        ]);

        $foreignVoucher = Voucher::query()->create([
            'tenant_id' => $foreignTenant->id,
            'promo_id' => $foreignPromo->id,
            'code' => 'FOREIGN10',
            'voucher_type' => Voucher::TYPE_GENERAL,
        ]);

        $foreignRaffle = Raffle::query()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Undian Rahasia',
            'status' => Raffle::STATUS_DRAFT,
        ]);

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/promos/'.$foreignPromo->id.'/edit')
            ->assertNotFound();

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/vouchers/'.$foreignVoucher->id.'/edit')
            ->assertNotFound();

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/raffles/'.$foreignRaffle->id.'/edit')
            ->assertNotFound();
    }
}
