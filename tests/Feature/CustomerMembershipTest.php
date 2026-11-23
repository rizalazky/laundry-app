<?php

namespace Tests\Feature;

use App\Actions\Memberships\RegisterCustomerMembership;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CustomerMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_activation_records_duration_actor_and_authorized_outlet(): void
    {
        $tenant = $this->createTenant('membership-owner');
        $actor = $this->createOwner($tenant);
        $branch = $this->createBranch($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Member']);
        $tier = $this->createTier($tenant, 'Gold', 30);
        $this->actingAs($actor);

        $startedAt = Carbon::parse('2026-11-22 09:00:00');
        $membership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $branch,
            $customer->id,
            $tier->id,
            $startedAt,
            paymentConfirmed: true,
        );

        $this->assertSame($tenant->id, $membership->tenant_id);
        $this->assertSame($customer->id, $membership->customer_id);
        $this->assertSame($tier->id, $membership->member_tier_id);
        $this->assertSame(CustomerMembership::STATUS_ACTIVE, $membership->status);
        $this->assertTrue($membership->started_at->equalTo($startedAt));
        $this->assertTrue($membership->expired_at->equalTo($startedAt->copy()->addDays(30)));
        $this->assertSame($actor->id, $membership->activated_by);
        $this->assertSame($branch->id, $membership->activated_at_branch_id);
        $this->assertNotNull($membership->activated_at);
        $this->assertDatabaseHas('customer_memberships', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'status' => CustomerMembership::STATUS_ACTIVE,
            'activated_by' => $actor->id,
            'activated_at_branch_id' => $branch->id,
        ]);
    }

    public function test_membership_activation_requires_external_payment_confirmation(): void
    {
        $tenant = $this->createTenant('membership-payment-confirmation');
        $actor = $this->createOwner($tenant);
        $branch = $this->createBranch($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan']);
        $tier = $this->createTier($tenant, 'Silver', 30);
        $this->actingAs($actor);

        try {
            app(RegisterCustomerMembership::class)->handle($actor, $branch, $customer->id, $tier->id);
            $this->fail('Activation without payment confirmation must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_confirmed', $exception->errors());
        }

        $this->assertDatabaseCount('customer_memberships', 0);
    }

    public function test_active_membership_requires_explicit_replacement_confirmation(): void
    {
        $tenant = $this->createTenant('membership-replacement-confirm');
        $actor = $this->createOwner($tenant);
        $branch = $this->createBranch($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Aktif']);
        $silver = $this->createTier($tenant, 'Silver', 30);
        $gold = $this->createTier($tenant, 'Gold', 60);
        $this->actingAs($actor);

        $oldMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $branch,
            $customer->id,
            $silver->id,
            Carbon::parse('2026-11-22 08:00:00'),
            paymentConfirmed: true,
        );

        try {
            app(RegisterCustomerMembership::class)->handle(
                $actor,
                $branch,
                $customer->id,
                $gold->id,
                Carbon::parse('2026-11-23 08:00:00'),
                paymentConfirmed: true,
            );
            $this->fail('Replacement must require explicit confirmation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Silver', collect($exception->errors())->flatten()->implode(' '));
            $this->assertStringContainsString('Gold', collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertDatabaseCount('customer_memberships', 1);
        $this->assertSame(CustomerMembership::STATUS_ACTIVE, $oldMembership->fresh()->status);
        $this->assertNull($oldMembership->fresh()->replaced_by_membership_id);
    }

    public function test_confirmed_tier_replacement_preserves_old_membership_history(): void
    {
        $tenant = $this->createTenant('membership-replacement');
        $actor = $this->createOwner($tenant);
        $branch = $this->createBranch($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Upgrade']);
        $silver = $this->createTier($tenant, 'Silver', 30);
        $gold = $this->createTier($tenant, 'Gold', 60);
        $this->actingAs($actor);

        $oldMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $branch,
            $customer->id,
            $silver->id,
            Carbon::parse('2026-11-22 08:00:00'),
            paymentConfirmed: true,
        );

        $newMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $branch,
            $customer->id,
            $gold->id,
            Carbon::parse('2026-11-23 08:00:00'),
            paymentConfirmed: true,
            confirmReplacement: true,
        );

        $this->assertSame(CustomerMembership::STATUS_REPLACED, $oldMembership->fresh()->status);
        $this->assertSame($newMembership->id, $oldMembership->fresh()->replaced_by_membership_id);
        $this->assertSame(CustomerMembership::STATUS_ACTIVE, $newMembership->status);
        $this->assertSame($gold->id, $newMembership->member_tier_id);
        $this->assertSame($actor->id, $newMembership->activated_by);
        $this->assertDatabaseCount('customer_memberships', 2);
    }

    public function test_failed_new_activation_rolls_back_replacement_of_existing_membership(): void
    {
        $tenant = $this->createTenant('membership-replacement-rollback');
        $actor = $this->createOwner($tenant);
        $branch = $this->createBranch($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Rollback']);
        $silver = $this->createTier($tenant, 'Silver', 30);
        $gold = $this->createTier($tenant, 'Gold', 60);
        $this->actingAs($actor);

        $oldMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $branch,
            $customer->id,
            $silver->id,
            Carbon::parse('2026-11-22 08:00:00'),
            paymentConfirmed: true,
        );

        CustomerMembership::updating(function (CustomerMembership $membership): void {
            if ($membership->status === CustomerMembership::STATUS_REPLACED) {
                throw new RuntimeException('Simulated replacement write failure.');
            }
        });

        try {
            app(RegisterCustomerMembership::class)->handle(
                $actor,
                $branch,
                $customer->id,
                $gold->id,
                Carbon::parse('2026-11-23 08:00:00'),
                paymentConfirmed: true,
                confirmReplacement: true,
            );
            $this->fail('Expected replacement transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated replacement write failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.updating: '.CustomerMembership::class);
        }

        $this->assertDatabaseCount('customer_memberships', 1);
        $this->assertSame(CustomerMembership::STATUS_ACTIVE, $oldMembership->fresh()->status);
        $this->assertNull($oldMembership->fresh()->replaced_by_membership_id);
    }

    public function test_expired_membership_is_marked_expired_before_new_activation(): void
    {
        $tenant = $this->createTenant('membership-renewal');
        $actor = $this->createOwner($tenant);
        $branch = $this->createBranch($tenant);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Renewal']);
        $tier = $this->createTier($tenant, 'Basic', 7);
        $this->actingAs($actor);

        $oldMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $branch,
            $customer->id,
            $tier->id,
            Carbon::parse('2026-11-01 08:00:00'),
            paymentConfirmed: true,
        );

        $newMembership = app(RegisterCustomerMembership::class)->handle(
            $actor,
            $branch,
            $customer->id,
            $tier->id,
            Carbon::parse('2026-11-22 08:00:00'),
            paymentConfirmed: true,
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
        $branch = $this->createBranch($tenant);
        $foreignCustomer = $otherTenant->customers()->create(['name' => 'Foreign Customer']);
        $foreignTier = $this->createTier($otherTenant, 'Foreign Tier', 30);
        $inactiveTier = $this->createTier($tenant, 'Inactive Tier', 30, false);
        $this->actingAs($actor);

        try {
            app(RegisterCustomerMembership::class)->handle($actor, $branch, $foreignCustomer->id, $foreignTier->id, paymentConfirmed: true);
            $this->fail('Expected foreign customer validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $localCustomer = $tenant->customers()->create(['name' => 'Local Customer']);

        try {
            app(RegisterCustomerMembership::class)->handle($actor, $branch, $localCustomer->id, $inactiveTier->id, paymentConfirmed: true);
            $this->fail('Expected inactive tier validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('member_tier_id', $exception->errors());
        }

        $this->assertDatabaseCount('customer_memberships', 0);
    }

    public function test_cashier_with_membership_permission_can_activate_for_tenant_customer_at_assigned_branch(): void
    {
        $tenant = $this->createTenant('membership-cashier');
        $branch = $this->createBranch($tenant);
        $cashier = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier-membership@example.test',
            'password' => 'password',
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $cashier->branches()->attach($branch);
        $this->grantMembershipPermission($tenant, 'cashier');
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Tenant']);
        $tier = $this->createTier($tenant, 'Gold', 30);
        $this->actingAs($cashier);

        $membership = app(RegisterCustomerMembership::class)->handle(
            $cashier,
            $branch,
            $customer->id,
            $tier->id,
            paymentConfirmed: true,
        );

        $this->assertSame($cashier->id, $membership->activated_by);
        $this->assertSame($branch->id, $membership->activated_at_branch_id);
    }

    public function test_cashier_cannot_activate_membership_at_an_unassigned_branch(): void
    {
        $tenant = $this->createTenant('membership-cashier-branch');
        $assignedBranch = $this->createBranch($tenant);
        $otherBranch = $this->createBranch($tenant);
        $cashier = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier-branch-membership@example.test',
            'password' => 'password',
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $cashier->branches()->attach($assignedBranch);
        $this->grantMembershipPermission($tenant, 'cashier');
        $customer = $tenant->customers()->create(['name' => 'Pelanggan']);
        $tier = $this->createTier($tenant, 'Gold', 30);
        $this->actingAs($cashier);

        $this->expectException(DomainException::class);

        app(RegisterCustomerMembership::class)->handle(
            $cashier,
            $otherBranch,
            $customer->id,
            $tier->id,
            paymentConfirmed: true,
        );
    }

    public function test_staff_without_customer_management_permission_cannot_activate_memberships(): void
    {
        $tenant = $this->createTenant('membership-permission');
        $branch = $this->createBranch($tenant);
        $staff = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier-no-permission@example.test',
            'password' => 'password',
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $staff->branches()->attach($branch);
        $customer = $tenant->customers()->create(['name' => 'Pelanggan']);
        $tier = $this->createTier($tenant, 'Member', 30);
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);

        app(RegisterCustomerMembership::class)->handle(
            $staff,
            $branch,
            $customer->id,
            $tier->id,
            paymentConfirmed: true,
        );
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

    private function createBranch(Tenant $tenant): Branch
    {
        return Branch::factory()->for($tenant)->create();
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

    private function grantMembershipPermission(Tenant $tenant, string $role): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'memberships.manage'],
            ['name' => 'Kelola Membership Pelanggan', 'group' => 'Pelanggan'],
        );

        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
