<?php

namespace App\Actions\Accounting;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class EnsureDefaultChartOfAccounts
{
    /**
     * Starter accounts for a laundry business. Tenants may add accounts later;
     * existing account names and settings are never overwritten.
     *
     * @var list<array{code: string, name: string, account_type: string}>
     */
    private const DEFAULT_ACCOUNTS = [
        ['code' => '1000', 'name' => 'Kas', 'account_type' => 'asset'],
        ['code' => '1010', 'name' => 'Bank', 'account_type' => 'asset'],
        ['code' => '1020', 'name' => 'Saldo QRIS / E-Wallet', 'account_type' => 'asset'],\n        ['code' => '1030', 'name' => 'Piutang Settlement EDC', 'account_type' => 'asset'],
        ['code' => '1100', 'name' => 'Piutang Usaha', 'account_type' => 'asset'],
        ['code' => '1200', 'name' => 'Persediaan Bahan', 'account_type' => 'asset'],
        ['code' => '1500', 'name' => 'Peralatan', 'account_type' => 'asset'],
        ['code' => '2000', 'name' => 'Utang Usaha', 'account_type' => 'liability'],
        ['code' => '2100', 'name' => 'Utang Pajak', 'account_type' => 'liability'],
        ['code' => '3000', 'name' => 'Modal Pemilik', 'account_type' => 'equity'],
        ['code' => '3100', 'name' => 'Prive', 'account_type' => 'equity'],
        ['code' => '3200', 'name' => 'Saldo Laba', 'account_type' => 'equity'],
        ['code' => '4000', 'name' => 'Pendapatan Jasa Laundry', 'account_type' => 'revenue'],
        ['code' => '4100', 'name' => 'Pendapatan Lain-lain', 'account_type' => 'revenue'],
        ['code' => '5000', 'name' => 'Beban Bahan', 'account_type' => 'expense'],
        ['code' => '5100', 'name' => 'Beban Gaji', 'account_type' => 'expense'],
        ['code' => '5200', 'name' => 'Beban Sewa', 'account_type' => 'expense'],
        ['code' => '5300', 'name' => 'Beban Listrik dan Air', 'account_type' => 'expense'],
        ['code' => '5400', 'name' => 'Beban Operasional', 'account_type' => 'expense'],
        ['code' => '5500', 'name' => 'Beban MDR', 'account_type' => 'expense'],
    ];

    public function handle(Tenant|int $tenant): void
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
        $now = now();

        foreach (self::DEFAULT_ACCOUNTS as $account) {
            // Query Builder intentionally bypasses BelongsToTenant's saving hook:
            // this observer runs while a tenant is being created by a platform admin.
            DB::table('chart_of_accounts')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'code' => $account['code'],
                'name' => $account['name'],
                'account_type' => $account['account_type'],
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
