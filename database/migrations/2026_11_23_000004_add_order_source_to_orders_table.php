<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_source', 30)->default('pos')->after('invoice_no');
            $table->index(['tenant_id', 'order_source', 'order_date'], 'orders_tenant_source_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_tenant_source_date_index');
            $table->dropColumn('order_source');
        });
    }
};
