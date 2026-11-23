<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('manual_discount', 12, 2)->default(0)->after('discount');
            $table->foreignId('customer_membership_id')->nullable()->after('customer_id')
                ->constrained('customer_memberships')->nullOnDelete();
            $table->foreignId('member_tier_id')->nullable()->after('customer_membership_id')
                ->constrained('member_tiers')->nullOnDelete();
            $table->string('member_tier_name_snapshot')->nullable()->after('member_tier_id');
            $table->decimal('membership_discount_percent_snapshot', 5, 2)->default(0)->after('member_tier_name_snapshot');
            $table->decimal('membership_discount', 12, 2)->default(0)->after('membership_discount_percent_snapshot');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_financial_snapshot_update');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_financial_snapshot_update
                BEFORE UPDATE ON orders
                WHEN OLD.invoice_no IS NOT NEW.invoice_no
                  OR OLD.subtotal IS NOT NEW.subtotal
                  OR OLD.discount IS NOT NEW.discount
                  OR OLD.manual_discount IS NOT NEW.manual_discount
                  OR OLD.customer_membership_id IS NOT NEW.customer_membership_id
                  OR OLD.member_tier_id IS NOT NEW.member_tier_id
                  OR OLD.member_tier_name_snapshot IS NOT NEW.member_tier_name_snapshot
                  OR OLD.membership_discount_percent_snapshot IS NOT NEW.membership_discount_percent_snapshot
                  OR OLD.membership_discount IS NOT NEW.membership_discount
                  OR OLD.promo_discount IS NOT NEW.promo_discount
                  OR OLD.total IS NOT NEW.total
                BEGIN
                    SELECT RAISE(ABORT, 'Order financial snapshots cannot be modified.');
                END;
            SQL);
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_financial_snapshot_update');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_financial_snapshot_update
                BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    IF NOT (OLD.invoice_no <=> NEW.invoice_no)
                        OR NOT (OLD.subtotal <=> NEW.subtotal)
                        OR NOT (OLD.discount <=> NEW.discount)
                        OR NOT (OLD.manual_discount <=> NEW.manual_discount)
                        OR NOT (OLD.customer_membership_id <=> NEW.customer_membership_id)
                        OR NOT (OLD.member_tier_id <=> NEW.member_tier_id)
                        OR NOT (OLD.member_tier_name_snapshot <=> NEW.member_tier_name_snapshot)
                        OR NOT (OLD.membership_discount_percent_snapshot <=> NEW.membership_discount_percent_snapshot)
                        OR NOT (OLD.membership_discount <=> NEW.membership_discount)
                        OR NOT (OLD.promo_discount <=> NEW.promo_discount)
                        OR NOT (OLD.total <=> NEW.total)
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Order financial snapshots cannot be modified.';
                    END IF;
                END
            SQL);
        } else {
            throw new RuntimeException("Order snapshot guards are not implemented for database driver [{$driver}].");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_financial_snapshot_update');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['member_tier_id']);
            $table->dropForeign(['customer_membership_id']);
            $table->dropColumn([
                'membership_discount',
                'membership_discount_percent_snapshot',
                'member_tier_name_snapshot',
                'member_tier_id',
                'customer_membership_id',
                'manual_discount',
            ]);
        });

        // Recreate the original guard after rolling back the membership snapshot columns.
        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_financial_snapshot_update
                BEFORE UPDATE ON orders
                WHEN OLD.invoice_no IS NOT NEW.invoice_no
                  OR OLD.subtotal IS NOT NEW.subtotal
                  OR OLD.discount IS NOT NEW.discount
                  OR OLD.total IS NOT NEW.total
                BEGIN
                    SELECT RAISE(ABORT, 'Order financial snapshots cannot be modified.');
                END;
            SQL);
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_financial_snapshot_update
                BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    IF NOT (OLD.invoice_no <=> NEW.invoice_no)
                        OR NOT (OLD.subtotal <=> NEW.subtotal)
                        OR NOT (OLD.discount <=> NEW.discount)
                        OR NOT (OLD.total <=> NEW.total)
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Order financial snapshots cannot be modified.';
                    END IF;
                END
            SQL);
        }
    }
};
