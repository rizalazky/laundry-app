<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTenantOwnerEmailIsVerified;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_registration_creates_tenant_and_branch_and_sends_verification_email(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), [
            'owner_name' => 'Laundry Owner',
            'business_name' => 'Laundry Example',
            'email' => 'OWNER@EXAMPLE.COM',
            'phone' => '081234567890',
            'branch_name' => 'Outlet Pertama',
            'address' => 'Jl. Contoh No. 1',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('/email/verify');

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($owner);
        $this->assertNull($owner->email_verified_at);
        $this->assertTrue($owner->email_verification_required);
        $this->assertSame('tenant_owner', $owner->role);

        $this->assertDatabaseHas('tenants', [
            'id' => $owner->tenant_id,
            'name' => 'Laundry Example',
            'owner_email' => 'owner@example.com',
        ]);

        $branch = Branch::query()
            ->where('tenant_id', $owner->tenant_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->assertSame('Outlet Pertama', $branch->name);
        $this->assertTrue($owner->branches()->whereKey($branch->getKey())->exists());

        Notification::assertSentTo($owner, VerifyEmail::class);
    }

    public function test_verification_link_marks_owner_verified_and_starts_tenant_trial(): void
    {
        $owner = $this->makeUnverifiedOwner();
        $this->createDefaultPlan();

        $response = $this->actingAs($owner)->get($this->verificationUrl($owner));

        $response->assertRedirect('/app');
        $this->assertNotNull($owner->fresh()->email_verified_at);
        $this->assertFalse($owner->fresh()->email_verification_required);

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $owner->tenant_id,
            'status' => 'trialing',
        ]);

        $this->assertNotNull($owner->tenant->fresh()->subscription_ends_at);
    }

    public function test_verification_link_with_invalid_signature_is_rejected(): void
    {
        $owner = $this->makeUnverifiedOwner();
        $url = $this->verificationUrl($owner).'&tampered=1';

        $this->actingAs($owner)
            ->get($url)
            ->assertForbidden();

        $this->assertNull($owner->fresh()->email_verified_at);
        $this->assertDatabaseMissing('subscriptions', [
            'tenant_id' => $owner->tenant_id,
        ]);
    }

    public function test_verification_link_for_another_email_hash_is_rejected(): void
    {
        $owner = $this->makeUnverifiedOwner();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $owner->getKey(),
                'hash' => sha1('someone-else@example.com'),
            ],
        );

        $this->actingAs($owner)
            ->get($url)
            ->assertForbidden();

        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_unverified_owner_can_resend_verification_email(): void
    {
        Notification::fake();

        $owner = $this->makeUnverifiedOwner();

        $this->actingAs($owner)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($owner, VerifyEmail::class);
    }

    public function test_unverified_owner_is_redirected_away_from_protected_app_routes(): void
    {
        $owner = $this->makeUnverifiedOwner();

        Route::middleware(['web', 'auth', EnsureTenantOwnerEmailIsVerified::class])
            ->get('/_test/verified-area', fn () => response('ok'));

        $this->actingAs($owner)
            ->get('/_test/verified-area')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_registration_is_rejected_when_platform_registration_is_disabled(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'registration_enabled'],
            ['value' => false],
        );

        $this->post(route('register.store'), [
            'owner_name' => 'Laundry Owner',
            'business_name' => 'Laundry Example',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('registration');

        $this->assertDatabaseMissing('users', [
            'email' => 'owner@example.com',
        ]);
        $this->assertDatabaseMissing('tenants', [
            'owner_email' => 'owner@example.com',
        ]);
    }

    private function makeUnverifiedOwner(): User
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();

        $owner = User::factory()
            ->for($tenant)
            ->withRole('tenant_owner')
            ->unverified()
            ->create([
                'email_verification_required' => true,
            ]);

        $owner->branches()->attach($branch);

        return $owner;
    }

    private function createDefaultPlan(): Plan
    {
        return Plan::query()->firstOrCreate([
            'code' => 'starter',
            'name' => 'Starter',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
    }

    private function verificationUrl(User $owner): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $owner->getKey(),
                'hash' => sha1($owner->getEmailForVerification()),
            ],
        );
    }
}
