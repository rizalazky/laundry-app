<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
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
    }
};
