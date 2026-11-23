<?php

namespace Tests\Feature;

use App\Actions\Affiliates\RegisterAffiliateReferral;
use App\Models\AffiliateReferral;
use App\Models\Tenant;
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
        $referral = app(RegisterAffiliateReferral::class)->handle($tenant->id, $referred->id, 10);
        $referral->update(['status' => AffiliateReferral::STATUS_APPROVED]);
        $payout = $referral->payouts()->create([
            'tenant_id' => $tenant->id,
            'amount' => '125000.00',
            'payout_date' => now()->toDateString(),
            'status' => AffiliatePayout::STATUS_PENDING,
        ]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_owner', 'is_active' => true]);

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
        $referral = app(RegisterAffiliateReferral::class)->handle($tenant->id, Tenant::factory()->create()->id, 10);
        $payout = $referral->payouts()->create([
            'tenant_id' => $tenant->id,
            'amount' => '50000.00',
            'payout_date' => now()->toDateString(),
            'status' => AffiliatePayout::STATUS_PENDING,
        ]);
        $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'tenant_owner', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        app(UpdateAffiliatePayoutStatus::class)->handle($otherOwner, $payout->id, AffiliatePayout::STATUS_PAID);
    }

    public function test_tenant_can_be_registered_as_an_affiliate_referral(): void
    {
        $referrer = Tenant::factory()->create();
        $referred = Tenant::factory()->create();

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
        $secondReferrer = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $action = app(RegisterAffiliateReferral::class);

        $action->handle($firstReferrer->id, $referred->id, 10);

        try {
            $action->handle($secondReferrer->id, $referred->id, 15);
            $this->fail('Expected duplicate referral validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('referred_tenant_id', $exception->errors());
        }

        $this->assertDatabaseCount('affiliate_referrals', 1);
    }
}
