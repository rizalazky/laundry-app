<?php

namespace App\Filament\App\Pages;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionInvoiceService;
use App\Services\SubscriptionPaymentService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class Billing extends Page
{
    protected string $view = 'filament.app.pages.billing';

    protected static ?string $slug = 'billing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';

    protected static ?int $navigationSort = 90;

    public string $billingInterval = 'monthly';

    public ?string $selectedPlanId = null;

    public static function canAccess(): bool
    {
        $user = static::authenticatedActor();

        return $user !== null
            && $user->is_active
            && $user->role === 'tenant_owner'
            && $user->tenant_id !== null
            && $user->tenant !== null;
    }

    private static function authenticatedActor(): ?User
    {
        $authenticatedUserId = Auth::id();

        return $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);
    }

    /**
     * Re-check page access for every subsequent Livewire request.
     */
    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $user = static::authenticatedActor();

        abort_unless($user !== null, 403);

        $subscription = Subscription::query()
            ->where('tenant_id', $user->tenant_id)
            ->first();

        $this->selectedPlanId = $subscription ? (string) $subscription->plan_id : null;
    }

    public function createInvoice(): void
    {
        $validated = $this->validate([
            'billingInterval' => ['required', 'in:monthly,yearly'],
        ]);

        $user = static::authenticatedActor();
        abort_unless($user !== null && static::canAccess(), 403);

        $subscription = Subscription::query()
            ->with('plan')
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        try {
            $service = app(SubscriptionInvoiceService::class);

            if ($subscription->current_period_start === null && $subscription->current_period_end === null) {
                $planData = $this->validate([
                    'selectedPlanId' => ['required', 'integer', 'exists:plans,id'],
                ]);

                $plan = Plan::query()
                    ->where('is_active', true)
                    ->findOrFail((int) $planData['selectedPlanId']);

                $invoice = $service->createInitialInvoice(
                    $user->tenant,
                    $plan,
                    $validated['billingInterval'],
                );
            } else {
                $invoice = $service->createRenewalInvoice(
                    $user->tenant,
                    $validated['billingInterval'],
                );
            }

            Notification::make()
                ->title($invoice->status === 'paid' ? 'Paket berhasil diaktifkan' : 'Invoice siap')
                ->body($invoice->status === 'paid'
                    ? 'Paket '.$invoice->plan_name_snapshot.' telah aktif.'
                    : 'Nomor invoice: '.$invoice->invoice_number)
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }


    public function purchasePlan(int $planId): void
    {
        $validated = validator(['planId' => $planId], [
            'planId' => ['required', 'integer', 'exists:plans,id'],
        ])->validate();

        $user = static::authenticatedActor();
        abort_unless($user !== null && static::canAccess(), 403);

        $plan = Plan::query()
            ->where('is_active', true)
            ->findOrFail((int) $validated['planId']);

        try {
            $invoice = app(SubscriptionInvoiceService::class)->createPlanPurchaseInvoice(
                $user->tenant,
                $plan,
                $this->billingInterval,
            );

            if ($invoice->status === 'paid') {
                $message = $invoice->period_start->isFuture()
                    ? 'Pembelian tercatat. Paket akan aktif pada '.$invoice->period_start->format('d M Y H:i').'.'
                    : ($invoice->type === 'upgrade'
                        ? 'Upgrade paket berhasil diaktifkan.'
                        : 'Paket berhasil diaktifkan.');
            } else {
                $message = 'Nomor invoice: '.$invoice->invoice_number;
            }

            Notification::make()
                ->title($invoice->status === 'paid' ? 'Pembelian paket diproses' : 'Invoice siap')
                ->body($message)
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    public function payInvoice(int $invoiceId)
    {
        $user = static::authenticatedActor();
        abort_unless($user !== null && static::canAccess(), 403);

        $invoice = SubscriptionInvoice::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($invoiceId);

        $payment = app(SubscriptionPaymentService::class)->startPayment($invoice);

        if (blank($payment->payment_url)) {
            Notification::make()
                ->title('Link pembayaran belum tersedia')
                ->danger()
                ->send();

            return null;
        }

        return redirect()->away($payment->payment_url);
    }

    protected function getViewData(): array
    {
        $user = static::authenticatedActor();

        abort_unless($user !== null, 403);

        $subscription = Subscription::query()
            ->with('plan.features')
            ->where('tenant_id', $user->tenant_id)
            ->first();

        $invoices = SubscriptionInvoice::query()
            ->with(['payments' => fn ($query) => $query->latest()])
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(30)
            ->get();

        $gatewayDriver = (string) config('services.payment_gateway.driver');

        return [
            'plans' => Plan::query()
                ->where('is_active', true)
                ->with('features')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'subscription' => $subscription,
            'invoices' => $invoices,
            'gatewayConfigured' => $gatewayDriver === 'midtrans'
                ? filled(config('services.midtrans.server_key'))
                : filled($gatewayDriver),
        ];
    }
}
