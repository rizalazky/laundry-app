<?php

namespace Tests\Feature;

use App\Filament\Pages\SubscriptionPaymentReviews;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionPaymentReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_admin_can_access_payment_review_page(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $admin = User::factory()->withRole('super_admin')->create();

        $this->actingAs($owner);
        $this->assertFalse(SubscriptionPaymentReviews::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(SubscriptionPaymentReviews::canAccess());
    }

    public function test_review_queue_contains_only_unreviewed_late_successful_payments(): void
    {
        [$subscription, $invoice, $payment] = $this->makeLatePayment();

        $lateEvent = $this->recordLatePaymentEvent($subscription, $invoice, $payment);
        $regularEvent = SubscriptionEvent::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'event_type' => 'payment_received',
            'metadata' => ['amount' => '250000.00'],
            'occurred_at' => now(),
        ]);

        $page = app(SubscriptionPaymentReviews::class);

        $this->assertSame([$lateEvent->id], $page->getPendingReviewEvents()->modelKeys());

        SubscriptionEvent::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'event_type' => 'late_payment_reviewed',
            'occurred_at' => now(),
        ]);

        $this->assertCount(0, $page->getPendingReviewEvents());
        $this->assertNotSame($lateEvent->id, $regularEvent->id);
    }

    public function test_admin_can_acknowledge_late_payment_without_changing_financial_records(): void
    {
        [$subscription, $invoice, $payment] = $this->makeLatePayment();
        $event = $this->recordLatePaymentEvent($subscription, $invoice, $payment);
        $admin = User::factory()->withRole('super_admin')->create();

        $this->actingAs($admin);
        $page = app(SubscriptionPaymentReviews::class);
        $page->markReviewed($event->id);

        $reviewEvent = SubscriptionEvent::query()
            ->where('event_type', 'late_payment_reviewed')
            ->sole();

        $this->assertSame($event->id, $reviewEvent->metadata['reviewed_event_id']);
        $this->assertSame($admin->id, $reviewEvent->actor_id);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('expired', $invoice->fresh()->status);
        $this->assertSame('trialing', $subscription->fresh()->status);
        $this->assertCount(0, $page->getPendingReviewEvents());

        $page->markReviewed($event->id);
        $this->assertSame(1, SubscriptionEvent::query()->where('event_type', 'late_payment_reviewed')->count());
    }

    public function test_non_admin_cannot_acknowledge_a_late_payment(): void
    {
        [$subscription, $invoice, $payment] = $this->makeLatePayment();
        $event = $this->recordLatePaymentEvent($subscription, $invoice, $payment);
        $owner = User::factory()->for($subscription->tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        $this->expectException(HttpException::class);

        app(SubscriptionPaymentReviews::class)->markReviewed($event->id);
    }

    private function makeLatePayment(): array
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'code' => 'review-'.Str::lower(Str::random(8)),
            'name' => 'Review Plan',
            'monthly_price' => '250000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
        ]);
        $invoice = SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'type' => 'initial',
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
            'billing_interval' => 'monthly',
            'unit_price_snapshot' => '250000.00',
            'subtotal' => '250000.00',
            'discount_amount' => '0.00',
            'total_amount' => '250000.00',
            'currency' => 'IDR',
            'status' => 'expired',
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'due_at' => now()->subHour(),
        ]);
        $payment = SubscriptionPayment::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_invoice_id' => $invoice->id,
            'provider' => 'midtrans',
            'provider_reference' => 'SUBPAY-'.Str::random(12),
            'amount' => '250000.00',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        return [$subscription, $invoice, $payment];
    }

    private function recordLatePaymentEvent(
        Subscription $subscription,
        SubscriptionInvoice $invoice,
        SubscriptionPayment $payment,
    ): SubscriptionEvent {
        return SubscriptionEvent::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'event_type' => 'payment_received',
            'metadata' => [
                'provider' => 'midtrans',
                'provider_reference' => $payment->provider_reference,
                'amount' => '250000.00',
                'currency' => 'IDR',
                'late_payment' => true,
                'requires_manual_review' => true,
                'invoice_due_at' => $invoice->due_at->toIso8601String(),
            ],
            'occurred_at' => now(),
        ]);
    }
}
