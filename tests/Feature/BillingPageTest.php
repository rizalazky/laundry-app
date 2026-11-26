<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Billing;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_can_open_billing_page_and_create_initial_invoice(): void
    {
        [$tenant, $owner, $plan] = $this->makeTenantWithSubscription();

        Livewire::actingAs($owner)
            ->test(Billing::class)
            ->assertOk()
            ->set('billingInterval', 'monthly')
            ->call('createInvoice')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('subscription_invoices', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'type' => 'initial',
            'billing_interval' => 'monthly',
            'status' => 'issued',
        ]);
    }

    public function test_only_tenant_owner_can_access_billing_page(): void
    {
        [$tenant, $owner] = $this->makeTenantWithSubscription();
        $staff = User::factory()->for($tenant)->withRole('cashier')->create();

        $this->actingAs($owner);
        $this->assertTrue(Billing::canAccess());

        $this->actingAs($staff);
        $this->assertFalse(Billing::canAccess());
    }

    public function test_billing_page_only_displays_invoices_belonging_to_current_tenant(): void
    {
        [$tenantA, $ownerA, $planA] = $this->makeTenantWithSubscription();
        [$tenantB, $ownerB, $planB] = $this->makeTenantWithSubscription();

        $this->actingAs($ownerA);
        $invoiceA = app(SubscriptionInvoiceService::class)->createInitialInvoice($tenantA, $planA);

        $this->actingAs($ownerB);
        $invoiceB = app(SubscriptionInvoiceService::class)->createInitialInvoice($tenantB, $planB);

        Livewire::actingAs($ownerA)
            ->test(Billing::class)
            ->assertSee($invoiceA->invoice_number)
            ->assertDontSee($invoiceB->invoice_number);
    }


    public function test_billing_page_displays_active_pricing_plans_with_prices_and_features(): void
    {
        [, $owner, $plan] = $this->makeTenantWithSubscription();

        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'max_outlets',
            'value' => 3,
        ]);

        Plan::query()->create([
            'code' => 'hidden-plan',
            'name' => 'Hidden Plan',
            'monthly_price' => 999000,
            'currency' => 'IDR',
            'is_active' => false,
        ]);

        Livewire::actingAs($owner)
            ->test(Billing::class)
            ->assertOk()
            ->assertSee('Pricing & Paket')
            ->assertSee('Professional')
            ->assertSee('IDR 250.000')
            ->assertSee('Maksimal Outlet')
            ->assertSee('3')
            ->assertSee('Paket saat ini')
            ->assertDontSee('Hidden Plan');
    }

    private function makeTenantWithSubscription(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $plan = Plan::query()->create([
            'code' => 'plan-'.Str::lower(Str::random(8)),
            'name' => 'Professional',
            'monthly_price' => '250000.00',
            'yearly_price' => '2500000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(30),
        ]);

        return [$tenant, $owner, $plan];
    }
}
