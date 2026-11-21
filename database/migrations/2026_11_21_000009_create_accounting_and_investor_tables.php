<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('account_type', 20);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id'], 'chart_of_accounts_id_tenant_unique');
            $table->index(['tenant_id', 'account_type']);
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('coa_id');
            $table->string('bank_name');
            $table->string('account_number', 100);
            $table->string('holder_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'account_number']);
            $table->unique(['id', 'tenant_id'], 'bank_accounts_id_tenant_unique');
            $table->index(['tenant_id', 'is_active']);
            $table->foreign(['coa_id', 'tenant_id'], 'bank_accounts_coa_tenant_foreign')->references(['id', 'tenant_id'])->on('chart_of_accounts')->restrictOnDelete();
        });

        Schema::create('edc_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->string('terminal_name');
            $table->decimal('mdr_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'is_active']);
            $table->foreign(['branch_id', 'tenant_id'], 'edc_machines_branch_tenant_foreign')->references(['id', 'tenant_id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['bank_account_id', 'tenant_id'], 'edc_machines_bank_tenant_foreign')->references(['id', 'tenant_id'])->on('bank_accounts')->restrictOnDelete();
        });

        Schema::create('cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('source_type', 30);
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('target_bank_id');
            $table->timestamp('settled_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'source_type', 'settled_at']);
            $table->foreign(['branch_id', 'tenant_id'], 'cash_settlements_branch_tenant_foreign')->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['target_bank_id', 'tenant_id'], 'cash_settlements_bank_tenant_foreign')->references(['id', 'tenant_id'])->on('bank_accounts')->restrictOnDelete();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('location_type', 20);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('coa_id');
            $table->decimal('amount', 15, 2);
            $table->text('description');
            $table->timestamp('spent_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'spent_at']);
            $table->index(['tenant_id', 'location_type', 'location_id']);
            $table->foreign(['coa_id', 'tenant_id'], 'expenses_coa_tenant_foreign')->references(['id', 'tenant_id'])->on('chart_of_accounts')->restrictOnDelete();
        });

        Schema::create('capital_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('coa_id');
            $table->string('type', 30);
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamp('transacted_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'transacted_at']);
            $table->foreign(['coa_id', 'tenant_id'], 'capital_movements_coa_tenant_foreign')->references(['id', 'tenant_id'])->on('chart_of_accounts')->restrictOnDelete();
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamp('journal_date');
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'journal_date'], 'journals_tenant_date_index');
            $table->index(['tenant_id', 'reference_type', 'reference_id'], 'journals_tenant_reference_index');
            $table->unique(['id', 'tenant_id'], 'journals_id_tenant_unique');
            $table->foreign(['branch_id', 'tenant_id'], 'journals_branch_tenant_foreign')->references(['id', 'tenant_id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('coa_id');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['journal_id', 'coa_id']);
            $table->foreign(['journal_id', 'tenant_id'], 'journal_entries_journal_tenant_foreign')->references(['id', 'tenant_id'])->on('journals')->cascadeOnDelete();
            $table->foreign(['coa_id', 'tenant_id'], 'journal_entries_coa_tenant_foreign')->references(['id', 'tenant_id'])->on('chart_of_accounts')->restrictOnDelete();
        });

        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->decimal('capital_amount', 15, 2);
            $table->decimal('share_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'is_active']);
            $table->unique(['id', 'tenant_id'], 'investors_id_tenant_unique');
            $table->foreign(['branch_id', 'tenant_id'], 'investors_branch_tenant_foreign')->references(['id', 'tenant_id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('profit_sharings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('investor_id');
            $table->string('period_month', 7);
            $table->decimal('net_profit_base', 15, 2);
            $table->decimal('payout_amount', 15, 2);
            $table->string('status', 20)->default('draft');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['investor_id', 'period_month']);
            $table->index(['tenant_id', 'period_month', 'status']);
            $table->foreign(['investor_id', 'tenant_id'], 'profit_sharings_investor_tenant_foreign')->references(['id', 'tenant_id'])->on('investors')->cascadeOnDelete();
        });

        Schema::create('tenant_reset_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reset_scope', 30);
            $table->unsignedInteger('records_purged_count')->default(0);
            $table->timestamp('executed_at');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_reset_logs');
        Schema::dropIfExists('profit_sharings');
        Schema::dropIfExists('investors');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('capital_movements');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('cash_settlements');
        Schema::dropIfExists('edc_machines');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('chart_of_accounts');
    }
};
