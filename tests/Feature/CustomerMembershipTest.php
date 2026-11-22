<?php

namespace Tests\Feature;

use App\Actions\Memberships\RegisterCustomerMembership;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_membership_registration_uses_tier_duration_and_tenant_scope(): void
    {
        $tenant = $this->createTenant('membership-owner');
        $actor = $this->createOwner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Member']);
        $tier = $this->createTier($tenant, 'Gold', 30);

        $this->actingAs($actor);

        $startedAt = Carbon::parse('2026-11-22 09:00:00');
        $membership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $customer->id,
            $tier->id,
            $startedAt,
        );

        $this->assertSame($tenant->id, $membership->tenant_id);
        $this->assertSame($customer->id, $membership->customer_id);
        $this->assertSame($tier->id, $membership->member_tier_id);
        $this->assertSame(CustomerMembership::STATUS_ACTIVE, $membership->status);
        $this->assertTrue($membership->started_at->equalTo($startedAt));
        $this->assertTrue($membership->expired_at->equalTo($startedAt->copy()->addDays(30)));
        $this->assertDatabaseHas('customer_memberships', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'status' => CustomerMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_customer_cannot_receive_a_second_membership_while_one_is_active(): void
    {
        $tenant = $this->createTenant('membership-overlap');
        $actor = $this->createOwner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Aktif']);
        $tier = $this->createTier($tenant, 'Silver', 30);
        $this->actingAs($actor);

        app(RegisterCustomerMembership::class)->handle(
            $actor,
            $customer->id,
            $tier->id,
            Carbon::parse('2026-11-01 08:00:00'),
        );

        try {
            app(RegisterCustomerMembership::class)->handle(
                $actor,
                $customer->id,
                $tier->id,
                Carbon::parse('2026-11-22 08:00:00'),
            );
            $this->fail('Expected active membership validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $this->assertDatabaseCount('customer_memberships', 1);
    }

    public function test_expired_membership_is_marked_expired_before_a_new_registration(): void
    {
        $tenant = $this->createTenant('membership-renewal');
        $actor = $this->createOwner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Renewal']);
        $tier = $this->createTier($tenant, 'Basic', 7);
        $this->actingAs($actor);

        $oldMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $customer->id,
            $tier->id,
            Carbon::parse('2026-11-01 08:00:00'),
        );

        $newMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $customer->id,
            $tier->id,
            Carbon::parse('2026-11-22 08:00:00'),
        );

        $this->assertDatabaseHas('customer_memberships', [
            'id' => $oldMembership->id,
            'status' => CustomerMembership::STATUS_EXPIRED,
        ]);
        $this->assertSame(CustomerMembership::STATUS_ACTIVE, $newMembership->status);
        $this->assertDatabaseCount('customer_memberships', 2);
    }

    public function test_membership_registration_rejects_foreign_tenant_records_and_inactive_tiers(): void
    {
        $tenant = $this->createTenant('membership-tenant-a');
        $otherTenant = $this->createTenant('membership-tenant-b');
        $actor = $this->createOwner($tenant);
        $foreignCustomer = $otherTenant->customers()->create(['name' => 'Foreign Customer']);
        $foreignTier = $this->createTier($otherTenant, 'Foreign Tier', 30);
        $inactiveTier = $this->createTier($tenant, 'Inactive Tier', 30, false);
        $this->actingAs($actor);

        try {
            app(RegisterCustomerMembership::class)->handle($actor, $foreignCustomer->id, $foreignTier->id);
            $this->fail('Expected foreign customer validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $localCustomer = $tenant->customers()->create(['name' => 'Local Customer']);

        try {
            app(RegisterCustomerMembership::class)->handle($actor, $localCustomer->id, $inactiveTier->id);
            $this->fail('Expected inactive tier validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('member_tier_id', $exception->errors());
        }

        $this->assertDatabaseCount('customer_memberships', 0);
    }

    public function test_staff_without_customer_management_permission_cannot_register_memberships(): void
    {
        $tenant = $this->createTenant('membership-permission');
        $staff = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier-membership@example.test',
            'password' => 'password',
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan']);
        $tier = $this->createTier($tenant, 'Member', 30);
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);

        app(RegisterCustomerMembership::class)->handle($staff, $customer->id, $tier->id);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => $slug,
            'slug' => $slug,
            'owner_email' => $slug.'@example.test',
            'plan' => 'starter',
            'max_branches' => 3,
            'is_active' => true,
        ]);
    }

    private function createOwner(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'email' => 'owner-'.$tenant->slug.'@example.test',
            'password' => 'password',
            'role' => 'tenant_owner',
            'is_active' => true,
        ]);
    }

    private function createTier(Tenant $tenant, string $name, int $durationDays, bool $active = true): MemberTier
    {
        return MemberTier::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'price' => 50000,
            'duration_days' => $durationDays,
            'discount_percent' => 10,
            'rules' => ['minimum_transaction' => '50000'],
            'is_active' => $active,
        ]);
    }
}
