<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OwnerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_register_and_receives_a_tenant_with_its_first_outlet(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), [
            'owner_name' => 'Laundry Owner',
            'business_name' => 'Laundry Ceria',
            'email' => 'OWNER@EXAMPLE.COM',
            'phone' => '081234567890',
            'branch_name' => 'Outlet Pusat',
            'address' => 'Jl. Merdeka No. 10',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ]);

        $response
            ->assertRedirect('/app')
            ->assertSessionHas('status');

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $tenant = Tenant::query()->where('owner_email', 'owner@example.com')->firstOrFail();
        $branch = Branch::query()->where('tenant_id', $tenant->getKey())->firstOrFail();

        $this->assertAuthenticatedAs($owner);
        $this->assertNull($owner->email_verified_at);
        $this->assertNull($tenant->subscription_ends_at);
        $this->assertSame(0, Subscription::query()->count());
        Notification::assertSentTo($owner, VerifyEmail::class);

        $this->assertSame($tenant->getKey(), $owner->tenant_id);
        $this->assertSame('tenant_owner', $owner->role);
        $this->assertSame('Laundry Ceria', $tenant->name);
        $this->assertSame('Outlet Pusat', $branch->name);
        $this->assertSame('MAIN', $branch->code);
        $this->assertFalse($branch->is_workshop);
        $this->assertSame('081234567890', $owner->phone_whatsapp);
        $this->assertTrue($owner->branches()->whereKey($branch->getKey())->exists());
        $this->assertDatabaseHas('chart_of_accounts', [
            'tenant_id' => $tenant->getKey(),
            'code' => '1000',
        ]);
    }

    public function test_verifying_owner_email_starts_configured_trial_once(): void
    {
        Notification::fake();
        $this->travelTo(now()->startOfSecond());

        $this->post(route('register.store'), [
            'owner_name' => 'Laundry Owner',
            'business_name' => 'Laundry Ceria',
            'email' => 'trial@example.com',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertRedirect('/app');

        $owner = User::query()->where('email', 'trial@example.com')->firstOrFail();
        $tenant = $owner->tenant;

        $this->assertSame(0, Subscription::query()->count());

        $owner->markEmailAsVerified();
        event(new Verified($owner));

        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame('trialing', $subscription->status);
        $this->assertSame('starter', $subscription->plan->code);
        $this->assertTrue($subscription->trial_starts_at->equalTo(now()));
        $this->assertTrue($subscription->trial_ends_at->equalTo(now()->addDays(30)));
        $this->assertTrue($tenant->fresh()->subscription_ends_at->equalTo(now()->addDays(30)));

        event(new Verified($owner));

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_registration_creates_a_separate_tenant_for_each_owner(): void
    {
        Notification::fake();

        foreach ([
            ['owner_name' => 'First Owner', 'business_name' => 'Laundry Satu', 'email' => 'first@example.com'],
            ['owner_name' => 'Second Owner', 'business_name' => 'Laundry Dua', 'email' => 'second@example.com'],
        ] as $registration) {
            $this->post(route('register.store'), [
                ...$registration,
                'password' => 'StrongPassword123',
                'password_confirmation' => 'StrongPassword123',
            ])->assertRedirect('/app');
        }

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(2, Branch::query()->count());
        $this->assertSame(2, User::query()->where('role', 'tenant_owner')->count());

        $this->assertNotSame(
            Tenant::query()->where('owner_email', 'first@example.com')->value('slug'),
            Tenant::query()->where('owner_email', 'second@example.com')->value('slug'),
        );
    }

    public function test_registration_rejects_an_email_already_used_by_an_account_or_tenant(): void
    {
        $existingTenant = Tenant::factory()->create([
            'owner_email' => 'taken@example.com',
        ]);
        User::factory()->for($existingTenant)->create([
            'email' => 'taken@example.com',
        ]);

        $response = $this->from('/register')->post(route('register.store'), [
            'owner_name' => 'Duplicate Owner',
            'business_name' => 'Duplicate Laundry',
            'email' => 'TAKEN@example.com',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ]);

        $response->assertRedirect('/register')->assertSessionHasErrors('email');
        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Branch::query()->count());
    }

    public function test_registration_rejects_new_signups_when_platform_registration_is_disabled(): void
    {
        \App\Models\PlatformSetting::query()->updateOrCreate(
            ['key' => 'registration_enabled'],
            ['value' => false],
        );

        $this->from('/register')->post(route('register.store'), [
            'owner_name' => 'Closed Signup',
            'business_name' => 'Closed Laundry',
            'email' => 'closed@example.com',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertRedirect('/register')->assertSessionHasErrors('registration');

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_invalid_registration_does_not_create_partial_records(): void
    {
        $response = $this->from('/register')->post(route('register.store'), [
            'owner_name' => 'Incomplete Owner',
            'business_name' => 'Incomplete Laundry',
            'email' => 'incomplete@example.com',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'DifferentPassword123',
        ]);

        $response->assertRedirect('/register')->assertSessionHasErrors('password');
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Branch::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_registration_page_is_available_to_guests_and_explains_email_verification(): void
    {
        $this->withoutVite();

        $this->get('/register')
            ->assertOk()
            ->assertSee('Daftar pemilik laundry')
            ->assertSee('Masa trial dimulai setelah email Anda diverifikasi.')
            ->assertSee(route('filament.app.auth.login'));
    }
}
