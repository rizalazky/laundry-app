<?php

namespace App\Providers;

use App\Listeners\StartTenantTrialAfterEmailVerification;
use App\Models\Journal;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\JournalObserver;
use App\Observers\StockMovementObserver;
use App\Observers\TenantObserver;
use App\Services\EntitlementService;
use App\Services\PaymentGateways\MidtransPaymentGateway;
use App\Services\PaymentGateways\PaymentGatewayInterface;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use App\Services\TenantWriteGuard;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function (Application $app): PaymentGatewayInterface {
            return match (config('services.payment_gateway.driver')) {
                'midtrans' => $app->make(MidtransPaymentGateway::class),
                default => throw new InvalidArgumentException('Payment gateway provider tidak didukung.'),
            };
        });
    }

    public function boot(): void
    {
        Tenant::observe(TenantObserver::class);
        StockMovement::observe(StockMovementObserver::class);
        Journal::observe(JournalObserver::class);

        Event::listen(Verified::class, StartTenantTrialAfterEmailVerification::class);

        $guardTenantWrite = function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Model) {
                app(TenantWriteGuard::class)->assertModelWriteAllowed($model);
            }
        };

        Event::listen('eloquent.saving: *', $guardTenantWrite);
        Event::listen('eloquent.deleting: *', $guardTenantWrite);
        Event::listen('eloquent.forceDeleting: *', $guardTenantWrite);
        Event::listen('eloquent.restoring: *', $guardTenantWrite);

        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $writeAbilities = [
                'create',
                'createAny',
                'update',
                'delete',
                'deleteAny',
                'restore',
                'forceDelete',
                'replicate',
                'reorder',
            ];

            if (! in_array($ability, $writeAbilities, true) || $user->tenant_id === null) {
                return null;
            }

            $subject = $arguments[0] ?? null;
            $subjectClass = is_string($subject)
                ? ltrim($subject, '\\')
                : (is_object($subject) ? $subject::class : null);

            // Billing records must remain writable so an expired owner can renew.
            if ($subjectClass !== null && str_starts_with($subjectClass, 'App\\Models\\Subscription')) {
                return null;
            }

            $tenant = $user->tenant;

            return $tenant !== null && app(EntitlementService::class)->canWrite($tenant)
                ? null
                : false;
        });
    }
}
