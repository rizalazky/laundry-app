<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('source_bank_id');
            $table->unsignedBigInteger('destination_bank_id');
            $table->decimal('amount', 15, 2);
            $table->string('reference_number', 100)->nullable();
            $table->timestamp('transferred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'transferred_at']);
            $table->foreign(['branch_id', 'tenant_id'], 'bank_transfers_branch_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['source_bank_id', 'tenant_id'], 'bank_transfers_source_bank_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['destination_bank_id', 'tenant_id'], 'bank_transfers_destination_bank_tenant_foreign')
                ->references(['id', 'tenant_id'])->on('bank_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};
