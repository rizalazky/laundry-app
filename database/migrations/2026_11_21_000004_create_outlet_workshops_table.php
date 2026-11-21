<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_workshops', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('workshop_id');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['outlet_id', 'workshop_id']);
            $table->index(['workshop_id', 'tenant_id'], 'outlet_workshops_workshop_tenant_index');

            $table->foreign(['outlet_id', 'tenant_id'], 'outlet_workshops_outlet_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign(['workshop_id', 'tenant_id'], 'outlet_workshops_workshop_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_workshops');
    }
};
