<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_financial_snapshot_update');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_delete');

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_replace');
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->unique(['id', 'tenant_id'], 'vouchers_id_tenant_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('promo_id')->nullable()->after('discount');
            $table->unsignedBigInteger('voucher_id')->nullable()->after('promo_id');
            $table->string('promo_name_snapshot')->nullable()->after('voucher_id');
            $table->string('voucher_code_snapshot', 60)->nullable()->after('promo_name_snapshot');
            $table->decimal('promo_discount', 12, 2)->default(0)->after('voucher_code_snapshot');

            $table->foreign(['promo_id', 'tenant_id'], 'orders_promo_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('promos')->restrictOnDelete();
            $table->foreign(['voucher_id', 'tenant_id'], 'orders_voucher_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('vouchers')->restrictOnDelete();
            $table->index(['tenant_id', 'promo_id']);
            $table->index(['tenant_id', 'voucher_id']);
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique('vouchers_id_tenant_unique');
        });

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

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_replace
                BEFORE INSERT ON orders
                WHEN EXISTS (SELECT 1 FROM orders WHERE id = NEW.id)
                  OR EXISTS (
                    SELECT 1 FROM orders
                    WHERE tenant_id = NEW.tenant_id AND invoice_no = NEW.invoice_no
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'Order financial snapshots cannot be replaced.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_delete
                BEFORE DELETE ON orders
                BEGIN
                    SELECT RAISE(ABORT, 'Orders with historical snapshots cannot be deleted.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_promotion_snapshot_update
                BEFORE UPDATE ON orders
                WHEN OLD.promo_id IS NOT NEW.promo_id
                  OR OLD.voucher_id IS NOT NEW.voucher_id
                  OR OLD.promo_name_snapshot IS NOT NEW.promo_name_snapshot
                  OR OLD.voucher_code_snapshot IS NOT NEW.voucher_code_snapshot
                  OR OLD.promo_discount IS NOT NEW.promo_discount
                BEGIN
                    SELECT RAISE(ABORT, 'Order promotion snapshots cannot be modified.');
                END;
            SQL);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
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

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_delete
                BEFORE DELETE ON orders
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Orders with historical snapshots cannot be deleted.'
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_promotion_snapshot_update
                BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    IF NOT (OLD.promo_id <=> NEW.promo_id)
                        OR NOT (OLD.voucher_id <=> NEW.voucher_id)
                        OR NOT (OLD.promo_name_snapshot <=> NEW.promo_name_snapshot)
                        OR NOT (OLD.voucher_code_snapshot <=> NEW.voucher_code_snapshot)
                        OR NOT (OLD.promo_discount <=> NEW.promo_discount)
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Order promotion snapshots cannot be modified.';
                    END IF;
                END
            SQL);

            return;
        }

        throw new RuntimeException("Order promotion snapshot guards are not implemented for database driver [{$driver}].");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_financial_snapshot_update');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_promotion_snapshot_update');

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_prevent_replace');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_promo_tenant_foreign');
            $table->dropForeign('orders_voucher_tenant_foreign');
            $table->dropIndex(['tenant_id', 'promo_id']);
            $table->dropIndex(['tenant_id', 'voucher_id']);
            $table->dropColumn([
                'promo_id',
                'voucher_id',
                'promo_name_snapshot',
                'voucher_code_snapshot',
                'promo_discount',
            ]);
        });

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

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_replace
                BEFORE INSERT ON orders
                WHEN EXISTS (SELECT 1 FROM orders WHERE id = NEW.id)
                  OR EXISTS (
                    SELECT 1 FROM orders
                    WHERE tenant_id = NEW.tenant_id AND invoice_no = NEW.invoice_no
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'Order financial snapshots cannot be replaced.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_delete
                BEFORE DELETE ON orders
                BEGIN
                    SELECT RAISE(ABORT, 'Orders with historical snapshots cannot be deleted.');
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

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_prevent_delete
                BEFORE DELETE ON orders
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Orders with historical snapshots cannot be deleted.'
            SQL);
        }
    }
};
