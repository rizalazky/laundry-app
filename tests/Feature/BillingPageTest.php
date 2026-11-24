<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Billing;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
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
            ->assertHasNoErrors()
            ->assertSee('Invoice siap');

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
        [$tenantB, , $planB] = $this->makeTenantWithSubscription();

        $invoiceA = SubscriptionInvoice::factory()->create([
            'tenant_id' => $tenantA->id,
            'subscription_id' => $tenantA->subscription->id,
            'plan_id' => $planA->id,
        ]);
        $invoiceB = SubscriptionInvoice::factory()->create([
            'tenant_id' => $tenantB->id,
            'subscription_id' => $tenantB->subscription->id,
            'plan_id' => $planB->id,
        ]);

        Livewire::actingAs($ownerA)
            ->test(Billing::class)
            ->assertSee($invoiceA->invoice_number)
            ->assertDontSee($invoiceB->invoice_number);
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
