<?php

namespace Tests\Feature;

use App\Actions\Accounting\RecordFinancialSettlement;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\EdcMachine;
use App\Models\Journal;
use App\Models\Tenant;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_edc_settlement_records_gross_mdr_net_and_posts_balanced_journal(): void
    {
        [$tenant, $branch, $bank, $machine] = $this->makeEdcSetup();

        $settlement = app(RecordFinancialSettlement::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            sourceType: CashSettlement::SOURCE_EDC,
            targetBankId: $bank->id,
            grossAmount: '100000.00',
            mdrAmount: '1500.00',
            netAmount: '98500.00',
            settledAt: now(),
            edcMachineId: $machine->id,
            referenceNumber: 'EDC-SETTLE-001',
        );

        $this->assertSame('100000.00', $settlement->gross_amount);
        $this->assertSame('1500.00', $settlement->mdr_amount);
        $this->assertSame('98500.00', $settlement->net_amount);
        $this->assertSame('100000.00', $settlement->amount);
        $this->assertSame($machine->id, $settlement->edc_machine_id);

        $journal = Journal::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('reference_type', 'cash_settlement')
            ->where('reference_id', $settlement->id)
            ->with('entries.account')
            ->sole();

        $this->assertCount(3, $journal->entries);
        $this->assertSame(
            ['1010' => ['debit' => '98500.00', 'credit' => '0.00'], '5500' => ['debit' => '1500.00', 'credit' => '0.00'], '1030' => ['debit' => '0.00', 'credit' => '100000.00']],
            $journal->entries->mapWithKeys(fn ($entry) => [
                $entry->account->code => ['debit' => $entry->debit, 'credit' => $entry->credit],
            ])->all(),
        );
    }

    public function test_cash_deposit_moves_cash_to_bank_without_mdr(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $bank = $this->makeBankAccount($tenant);

        $settlement = app(RecordFinancialSettlement::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            sourceType: CashSettlement::SOURCE_OUTLET_CASH,
            targetBankId: $bank->id,
            grossAmount: '250000.00',
            mdrAmount: '0.00',
            netAmount: '250000.00',
            settledAt: now(),
        );

        $journal = Journal::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('reference_id', $settlement->id)
            ->with('entries.account')
            ->sole();

        $this->assertCount(2, $journal->entries);
        $this->assertDatabaseHas('journal_entries', [
            'tenant_id' => $tenant->id,
            'journal_id' => $journal->id,
            'debit' => '250000.00',
            'credit' => '0.00',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'tenant_id' => $tenant->id,
            'journal_id' => $journal->id,
            'debit' => '0.00',
            'credit' => '250000.00',
        ]);
    }

    public function test_settlement_rejects_amounts_that_do_not_reconcile(): void
    {
        [$tenant, $branch, $bank, $machine] = $this->makeEdcSetup();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Gross harus sama dengan MDR ditambah net aktual yang diterima bank.');

        app(RecordFinancialSettlement::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            sourceType: CashSettlement::SOURCE_EDC,
            targetBankId: $bank->id,
            grossAmount: '100000.00',
            mdrAmount: '1500.00',
            netAmount: '98000.00',
            settledAt: now(),
            edcMachineId: $machine->id,
        );
    }

    public function test_edc_machine_must_belong_to_the_selected_branch_and_bank(): void
    {
        [$tenant, $branch, $bank, $machine] = $this->makeEdcSetup();
        $otherBranch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Mesin EDC harus aktif, terdaftar pada cabang ini, dan terhubung ke rekening tujuan.');

        app(RecordFinancialSettlement::class)->handle(
            tenantId: $tenant->id,
            branchId: $otherBranch->id,
            sourceType: CashSettlement::SOURCE_EDC,
            targetBankId: $bank->id,
            grossAmount: '100000.00',
            mdrAmount: '1500.00',
            netAmount: '98500.00',
            settledAt: now(),
            edcMachineId: $machine->id,
        );
    }

    public function test_estimated_mdr_uses_two_decimal_half_up_rounding(): void
    {
        $this->assertSame('1500.00', app(RecordFinancialSettlement::class)->estimateMdr('100000', '1.50'));
        $this->assertSame('1.01', app(RecordFinancialSettlement::class)->estimateMdr('67', '1.50'));
    }

    /**
     * @return array{Tenant, Branch, BankAccount, EdcMachine}
     */
    private function makeEdcSetup(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $bank = $this->makeBankAccount($tenant);
        $machine = EdcMachine::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'bank_account_id' => $bank->id,
            'terminal_name' => 'EDC BCA 01',
            'mdr_percent' => '1.50',
            'is_active' => true,
        ]);

        return [$tenant, $branch, $bank, $machine];
    }

    private function makeBankAccount(Tenant $tenant): BankAccount
    {
        $bankCoa = $tenant->id
            ? \App\Models\ChartOfAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('code', '1010')
                ->firstOrFail()
            : null;

        return BankAccount::query()->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $bankCoa->id,
            'bank_name' => 'Bank Test',
            'account_number' => '1234567890',
            'holder_name' => 'Laundry Test',
            'is_active' => true,
        ]);
    }
}
