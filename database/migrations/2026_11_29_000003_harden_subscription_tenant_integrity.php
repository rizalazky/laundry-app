<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $invalidInvoices = DB::table('subscription_invoices as invoices')
            ->leftJoin('subscriptions', 'subscriptions.id', '=', 'invoices.subscription_id')
            ->where(function ($query): void {
                $query->whereNull('subscriptions.id')
                    ->orWhereColumn('subscriptions.tenant_id', '!=', 'invoices.tenant_id');
            })->exists();

        $invalidPayments = DB::table('subscription_payments as payments')
            ->leftJoin('subscription_invoices as invoices', 'invoices.id', '=', 'payments.subscription_invoice_id')
            ->where(function ($query): void {
                $query->whereNull('invoices.id')
                    ->orWhereColumn('invoices.tenant_id', '!=', 'payments.tenant_id');
            })->exists();

        $invalidEvents = DB::table('subscription_events as events')
            ->leftJoin('subscriptions', 'subscriptions.id', '=', 'events.subscription_id')
            ->leftJoin('subscription_invoices as invoices', 'invoices.id', '=', 'events.invoice_id')
            ->leftJoin('subscription_payments as payments', 'payments.id', '=', 'events.payment_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'events.actor_id')
            ->where(function ($query): void {
                $query->whereNull('subscriptions.id')
                    ->orWhereColumn('subscriptions.tenant_id', '!=', 'events.tenant_id')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('events.invoice_id')
                            ->where(function ($query): void {
                                $query->whereNull('invoices.id')
                                    ->orWhereColumn('invoices.tenant_id', '!=', 'events.tenant_id');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('events.payment_id')
                            ->where(function ($query): void {
                                $query->whereNull('payments.id')
                                    ->orWhereColumn('payments.tenant_id', '!=', 'events.tenant_id');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('events.actor_id')
                            ->where(function ($query): void {
                                $query->whereNull('actors.id')
                                    ->orWhereColumn('actors.tenant_id', '!=', 'events.tenant_id');
                            });
                    });
            })->exists();

        $invalidReminders = DB::table('subscription_renewal_reminders as reminders')
            ->leftJoin('subscriptions', 'subscriptions.id', '=', 'reminders.subscription_id')
            ->where(function ($query): void {
                $query->whereNull('subscriptions.id')
                    ->orWhereColumn('subscriptions.tenant_id', '!=', 'reminders.tenant_id');
            })->exists();

        if ($invalidInvoices || $invalidPayments || $invalidEvents || $invalidReminders) {
            throw new RuntimeException(
                'Cannot enforce subscription tenant integrity: existing records contain missing or cross-tenant references.'
            );
        }

        // Parent keys are deliberately added only where no matching composite key exists.
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'subscriptions_id_tenant_unique');
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'subscription_invoices_id_tenant_unique');
        });

        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'subscription_payments_id_tenant_unique');
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropForeign(['subscription_id']);

            $table->foreign(['subscription_id', 'tenant_id'], 'subscription_invoices_subscription_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('subscriptions')->cascadeOnDelete();
        });

        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->dropForeign(['subscription_invoice_id']);

            $table->foreign(['subscription_invoice_id', 'tenant_id'], 'subscription_payments_invoice_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('subscription_invoices')->cascadeOnDelete();
        });

        Schema::table('subscription_events', function (Blueprint $table): void {
            $table->dropForeign(['subscription_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['actor_id']);

            $table->foreign(['subscription_id', 'tenant_id'], 'subscription_events_subscription_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('subscriptions')->cascadeOnDelete();
            $table->foreign(['invoice_id', 'tenant_id'], 'subscription_events_invoice_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('subscription_invoices')->restrictOnDelete();
            $table->foreign(['payment_id', 'tenant_id'], 'subscription_events_payment_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('subscription_payments')->restrictOnDelete();
            $table->foreign(['actor_id', 'tenant_id'], 'subscription_events_actor_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::table('subscription_renewal_reminders', function (Blueprint $table): void {
            $table->dropForeign(['subscription_id']);

            $table->foreign(['subscription_id', 'tenant_id'], 'subscription_renewal_reminders_subscription_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('subscriptions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_renewal_reminders', function (Blueprint $table): void {
            $table->dropForeign('subscription_renewal_reminders_subscription_tenant_foreign');

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
        });

        Schema::table('subscription_events', function (Blueprint $table): void {
            $table->dropForeign('subscription_events_subscription_tenant_foreign');
            $table->dropForeign('subscription_events_invoice_tenant_foreign');
            $table->dropForeign('subscription_events_payment_tenant_foreign');
            $table->dropForeign('subscription_events_actor_tenant_foreign');

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('subscription_invoices')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('subscription_payments')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->dropForeign('subscription_payments_invoice_tenant_foreign');

            $table->foreign('subscription_invoice_id')->references('id')->on('subscription_invoices')->cascadeOnDelete();
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropForeign('subscription_invoices_subscription_tenant_foreign');

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
        });

        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->dropUnique('subscription_payments_id_tenant_unique');
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropUnique('subscription_invoices_id_tenant_unique');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropUnique('subscriptions_id_tenant_unique');
        });
    }
};
