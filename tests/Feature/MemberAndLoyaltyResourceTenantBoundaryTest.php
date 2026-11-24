<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LoyaltyPointRule;
use App\Models\MemberTier;
use App\Models\PointReward;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberAndLoyaltyResourceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_tenant_member_and_loyalty_records_cannot_open_edit_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['code' => 'LOCAL']);
        $foreignBranch = Branch::factory()->for($foreignTenant)->create(['code' => 'FOREIGN']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $foreignTier = MemberTier::query()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Foreign Gold',
            'price' => 100000,
            'duration_days' => 30,
            'discount_percent' => 10,
            'is_active' => true,
        ]);

        $foreignReward = PointReward::query()->create([
            'tenant_id' => $foreignTenant->id,
            'reward_name' => 'Hadiah Tenant Lain',
            'points_required' => 100,
            'stock' => 5,
            'is_active' => true,
        ]);

        $foreignRule = LoyaltyPointRule::query()->create([
            'tenant_id' => $foreignTenant->id,
            'branch_id' => $foreignBranch->id,
            'name' => 'Program Poin Rahasia',
            'target_type' => LoyaltyPointRule::TARGET_ALL_CUSTOMERS,
            'amount_step' => 10000,
            'points_per_step' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/member-tiers/'.$foreignTier->id.'/edit')
            ->assertNotFound();

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/point-rewards/'.$foreignReward->id.'/edit')
            ->assertNotFound();

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/loyalty-point-rules/'.$foreignRule->id.'/edit')
            ->assertNotFound();
    }
}
