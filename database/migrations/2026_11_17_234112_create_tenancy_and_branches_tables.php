<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('owner_email');
            $table->string('phone', 25)->nullable();
            $table->enum('plan', ['starter', 'pro', 'enterprise'])->default('starter');
            $table->unsignedInteger('max_branches')->default(1);
            $table->timestamp('subscription_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20); // Contoh: PUSAT, DGO, RIU
            $table->string('name');
            $table->string('phone', 25)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_workshop')->default(false);
            $table->text('receipt_footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->enum('role', ['super_admin', 'tenant_owner', 'branch_manager', 'cashier', 'operator'])
                ->default('super_admin')
                ->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['role', 'is_active']);
        });
        Schema::dropIfExists('branches');
        Schema::dropIfExists('tenants');
    }
};