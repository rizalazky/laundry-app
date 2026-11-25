<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monetary columns use DECIMAL(15,2) on MySQL/MariaDB. SQLite does not
     * enforce decimal precision, so skip table rebuilds there to preserve
     * the append-only and financial-snapshot triggers used by the test suite.
     */
    private const MONEY_COLUMNS = [
        ['customers', 'deposit_balance'],
        ['services', 'price'],
        ['services', 'commission_value'],
        ['branch_service_prices', 'price'],
        ['orders', 'subtotal'],
        ['orders', 'discount'],
        ['orders', 'promo_discount'],
        ['orders', 'total'],
        ['orders', 'paid_amount'],
        ['order_items', 'unit_price'],
        ['order_items', 'subtotal'],
        ['order_items', 'commission_snapshot'],
        ['payments', 'amount'],
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->decimal('change_amount', 15, 2)->default(0);
        });

        $this->resizeMoneyColumns(15);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('change_amount');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });

        $this->resizeMoneyColumns(12);
    }

    private function resizeMoneyColumns(int $precision): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::MONEY_COLUMNS as [$table, $column]) {
            $definition = match ($table.'.'.$column) {
                'customers.deposit_balance' => 'NOT NULL DEFAULT 0',
                'services.commission_value' => 'NOT NULL DEFAULT 0',
                'orders.discount', 'orders.paid_amount' => 'NOT NULL DEFAULT 0',
                'orders.promo_discount' => 'NOT NULL DEFAULT 0',
                'order_items.commission_snapshot' => 'NOT NULL DEFAULT 0',
                default => 'NOT NULL',
            };

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` DECIMAL(%d,2) %s',
                $table,
                $column,
                $precision,
                $definition,
            ));
        }
    }
};
