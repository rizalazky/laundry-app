<?php

namespace Tests\Feature;

use App\Actions\Affiliates\RegisterAffiliateReferral;
use App\Actions\Affiliates\UpdateAffiliatePayoutStatus;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AffiliateReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_payout_can_be_marked_paid_once(): void
    {
        $tenant = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);
        $referral = app(RegisterAffiliateReferral::class)->handle($tenant->id, $referred->id, 10);
        $referral->update(['status' => AffiliateReferral::STATUS_APPROVED]);
        $payout = $referral->payouts()->create([
            'tenant_id' => $tenant->id,
            'amount' => '125000.00',
            'payout_date' => now()->toDateString(),
            'status' => AffiliatePayout::STATUS_PENDING,
        ]);
        $updated = app(UpdateAffiliatePayoutStatus::class)->handle($owner, $payout->id, AffiliatePayout::STATUS_PAID);

        $this->assertSame(AffiliatePayout::STATUS_PAID, $updated->status);
        $this->assertDatabaseHas('affiliate_payouts', ['id' => $payout->id, 'status' => AffiliatePayout::STATUS_PAID]);

        $this->expectException(ValidationException::class);
        app(UpdateAffiliatePayoutStatus::class)->handle($owner, $payout->id, AffiliatePayout::STATUS_PAID);
    }

    public function test_payout_status_changes_are_scoped_to_the_referrer_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);
        $referral = app(RegisterAffiliateReferral::class)->handle($tenant->id, $referred->id, 10);
        $payout = $referral->payouts()->create([
            'tenant_id' => $tenant->id,
            'amount' => '50000.00',
            'payout_date' => now()->toDateString(),
            'status' => AffiliatePayout::STATUS_PENDING,
        ]);
        $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'tenant_owner', 'is_active' => true]);

        $this->actingAs($otherOwner);
        $this->expectException(ValidationException::class);
        app(UpdateAffiliatePayoutStatus::class)->handle($otherOwner, $payout->id, AffiliatePayout::STATUS_PAID);
    }

    public function test_cashier_cannot_forge_tenant_owner_role_to_update_affiliate_payout(): void
    {
        $tenant = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);
        $referral = app(RegisterAffiliateReferral::class)->handle($tenant->id, $referred->id, 10);
        $payout = $referral->payouts()->create([
            'tenant_id' => $tenant->id,
            'amount' => '50000.00',
            'payout_date' => now()->toDateString(),
            'status' => AffiliatePayout::STATUS_PENDING,
        ]);
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $this->actingAs($cashier);

        $forgedActor = clone $cashier;
        $forgedActor->setAttribute('role', 'tenant_owner');

        $this->expectException(ValidationException::class);

        app(UpdateAffiliatePayoutStatus::class)->handle(
            $forgedActor,
            $payout->id,
            AffiliatePayout::STATUS_PAID,
        );
    }

    public function test_read_only_tenant_cannot_update_an_existing_affiliate_referral(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $referral = app(RegisterAffiliateReferral::class)->handle(
            $tenant->id,
            Tenant::factory()->create()->id,
            10,
        );

        $plan = Plan::query()->create([
            'code' => 'affiliate-status-read-only',
            'name' => 'Read Only Plan',
            'monthly_price' => 99000,
            'is_active' => true,
        ]);
        Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->expectException(AuthorizationException::class);

        $referral->update(['status' => AffiliateReferral::STATUS_APPROVED]);
    }

    public function test_referral_registration_requires_the_authenticated_owner_of_the_referrer_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $otherOwner = User::factory()->for($otherTenant)->withRole('tenant_owner')->create();
        $this->actingAs($otherOwner);

        $this->expectException(AuthorizationException::class);

        app(RegisterAffiliateReferral::class)->handle(
            $tenant->id,
            $referred->id,
            10,
        );
    }

    public function test_read_only_tenant_cannot_register_an_affiliate_referral(): void
    {
        $tenant = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $plan = Plan::query()->create([
            'code' => 'affiliate-read-only',
            'name' => 'Read Only Plan',
            'monthly_price' => 99000,
            'is_active' => true,
        ]);
        Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);
        $this->actingAs($owner);

        $this->expectException(AuthorizationException::class);

        app(RegisterAffiliateReferral::class)->handle(
            $tenant->id,
            $referred->id,
            10,
        );
    }

    public function test_tenant_can_be_registered_as_an_affiliate_referral(): void
    {
        $referrer = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $owner = User::factory()->for($referrer)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $referral = app(RegisterAffiliateReferral::class)->handle(
            $referrer->id,
            $referred->id,
            12.5,
        );

        $this->assertSame($referrer->id, $referral->referrer_tenant_id);
        $this->assertSame($referred->id, $referral->referred_tenant_id);
        $this->assertSame('12.50', $referral->commission_rate);
        $this->assertSame(AffiliateReferral::STATUS_PENDING, $referral->status);
        $this->assertDatabaseHas('affiliate_referrals', [
            'referrer_tenant_id' => $referrer->id,
            'referred_tenant_id' => $referred->id,
        ]);
    }

    public function test_referral_rejects_self_referral_and_invalid_commission_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        try {
            app(RegisterAffiliateReferral::class)->handle($tenant->id, $tenant->id, 10);
            $this->fail('Expected self-referral validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('referred_tenant_id', $exception->errors());
        }

        try {
            app(RegisterAffiliateReferral::class)->handle($tenant->id, $other->id, 100.01);
            $this->fail('Expected commission rate validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('commission_rate', $exception->errors());
        }

        $this->assertDatabaseCount('affiliate_referrals', 0);
    }

    public function test_referred_tenant_can_only_be_attributed_once(): void
    {
        $firstReferrer = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $owner = User::factory()->for($firstReferrer)->withRole('tenant_owner')->create();
        $this->actingAs($owner);
        $action = app(RegisterAffiliateReferral::class);

        $action->handle($firstReferrer->id, $referred->id, 10);

        try {
            $action->handle($firstReferrer->id, $referred->id, 15);
            $this->fail('Expected duplicate referral validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('referred_tenant_id', $exception->errors());
        }

        $this->assertDatabaseCount('affiliate_referrals', 1);
    }
}
