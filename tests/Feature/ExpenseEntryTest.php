<?php

namespace Tests\\Feature;

use App\\Actions\\Accounting\\RecordExpense;
use App\\Models\\BankAccount;
use App\\Models\\Branch;
use App\\Models\\ChartOfAccount;
use App\\Models\\Expense;
use App\\Models\\Journal;
use App\\Models\\Tenant;
use DomainException;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class ExpenseEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_expense_creates_expense_and_balanced_journal(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();

        $expense = app(RecordExpense::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            expenseAccountId: $this->account($tenant->id, '5400')->id,
            paymentSourceType: Expense::SOURCE_CASH,
            bankAccountId: null,
            amount: '125000.00',
            description: 'Pembelian perlengkapan kebersihan',
            spentAt: now(),
        );

        $journal = Journal::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'expense')
            ->where('reference_id', $expense->id)
            ->with('entries.account')
            ->sole();

        $this->assertSame('outlet', $expense->location_type);
        $this->assertSame('cash', $expense->payment_source_type);
        $this->assertNull($expense->bank_account_id);
        $this->assertSame('125000.00', $expense->amount);
        $this->assertCount(2, $journal->entries);
        $this->assertSame('5400', $journal->entries->firstWhere('debit', '125000.00')->account->code);
        $this->assertSame('1000', $journal->entries->firstWhere('credit', '125000.00')->account->code);
    }

    public function test_bank_expense_credits_the_selected_tenant_bank_account(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $bankCoa = ChartOfAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'code' => '1011',
            'name' => 'Bank BCA',
            'account_type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);
        $bank = BankAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $bankCoa->id,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'holder_name' => 'Laundry',
            'is_active' => true,
        ]);

        $expense = app(RecordExpense::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            expenseAccountId: $this->account($tenant->id, '5400')->id,
            paymentSourceType: Expense::SOURCE_BANK,
            bankAccountId: $bank->id,
            amount: '75000',
            description: 'Pembayaran internet',
            spentAt: now(),
        );

        $this->assertSame($bank->id, $expense->bank_account_id);
        $this->assertDatabaseHas('journal_entries', [
            'tenant_id' => $tenant->id,
            'coa_id' => $bankCoa->id,
            'credit' => '75000.00',
        ]);
    }

    public function test_bank_expense_rejects_a_bank_account_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $otherBankCoa = $this->account($otherTenant->id, '1011', 'Bank Tenant Lain', 'asset');
        $otherBank = BankAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $otherTenant->id,
            'coa_id' => $otherBankCoa->id,
            'bank_name' => 'Bank Lain',
            'account_number' => '9876543210',
            'holder_name' => 'Tenant Lain',
            'is_active' => true,
        ]);

        $this->expectException(DomainException::class);

        app(RecordExpense::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            expenseAccountId: $this->account($tenant->id, '5400')->id,
            paymentSourceType: Expense::SOURCE_BANK,
            bankAccountId: $otherBank->id,
            amount: '10000',
            description: 'Percobaan lintas tenant',
            spentAt: now(),
        );
    }

    public function test_expense_account_must_be_an_active_expense_account(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $assetAccount = $this->account($tenant->id, '1011', 'Aset Bank', 'asset');

        $this->expectException(DomainException::class);

        app(RecordExpense::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            expenseAccountId: $assetAccount->id,
            paymentSourceType: Expense::SOURCE_CASH,
            bankAccountId: null,
            amount: '10000',
            description: 'Akun salah',
            spentAt: now(),
        );
    }

    private function account(int $tenantId, string $code, ?string $name = null, string $type = 'expense'): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => $code],
            [
                'name' => $name ?? 'Akun '.$code,
                'account_type' => $type,
                'is_system' => false,
                'is_active' => true,
            ],
        );
    }
}
