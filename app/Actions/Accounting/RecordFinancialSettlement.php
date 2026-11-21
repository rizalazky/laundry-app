<?php

namespace App\Actions\Accounting;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\ChartOfAccount;
use App\Models\EdcMachine;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecordFinancialSettlement
{
    /**
     * Record a cash deposit or EDC settlement and its balanced journal.
     *
     * For EDC, gross is the cardholder amount, MDR is the fee actually withheld,
     * and net is the amount actually credited by the bank. The configured MDR
     * percentage is informational and is not used to overwrite bank-statement
     * values.
     *
     * @throws DomainException
     */
    public function handle(
        int $tenantId,
        int $branchId,
        string $sourceType,
        int $targetBankId,
        string|int $grossAmount,
        string|int $mdrAmount,
        string|int $netAmount,
        CarbonInterface $settledAt,
        ?int $edcMachineId = null,
        ?string $referenceNumber = null,
        ?int $createdBy = null,
    ): CashSettlement {
        $authenticatedTenantId = Auth::user()?->tenant_id;

        if ($authenticatedTenantId !== null && (int) $authenticatedTenantId !== $tenantId) {
            throw new DomainException('Tenant settlement tidak sesuai dengan tenant pengguna.');
        }

        if (! in_array($sourceType, [
            CashSettlement::SOURCE_OUTLET_CASH,
            CashSettlement::SOURCE_WORKSHOP_CASH,
            CashSettlement::SOURCE_EDC,
        ], true)) {
            throw new DomainException('Jenis sumber settlement tidak valid.');
        }

        try {
            $gross = BigDecimal::of((string) $grossAmount)->toScale(2);
            $mdr = BigDecimal::of((string) $mdrAmount)->toScale(2);
            $net = BigDecimal::of((string) $netAmount)->toScale(2);
        } catch (NumberFormatException|RoundingNecessaryException) {
            throw new DomainException('Nominal settlement harus berupa angka dengan maksimal 2 desimal.');
        }

        if ($gross->isLessThanOrEqualTo(0) || $mdr->isLessThan(0) || $net->isLessThanOrEqualTo(0)) {
            throw new DomainException('Gross dan net harus lebih besar dari nol; MDR tidak boleh negatif.');
        }

        if (! $gross->isEqualTo($mdr->plus($net))) {
            throw new DomainException('Gross harus sama dengan MDR ditambah net aktual yang diterima bank.');
        }

        if ($sourceType === CashSettlement::SOURCE_EDC && $edcMachineId === null) {
            throw new DomainException('Mesin EDC wajib dipilih untuk settlement EDC.');
        }

        if ($sourceType !== CashSettlement::SOURCE_EDC && ($edcMachineId !== null || ! $mdr->isZero())) {
            throw new DomainException('Setoran kas tidak boleh menggunakan mesin EDC atau biaya MDR.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $branchId,
            $sourceType,
            $targetBankId,
            $gross,
            $mdr,
            $net,
            $settledAt,
            $edcMachineId,
            $referenceNumber,
            $createdBy,
        ): CashSettlement {
            $branch = Branch::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereKey($branchId)
                ->first();

            if ($branch === null) {
                throw new DomainException('Cabang tidak ditemukan pada tenant ini.');
            }

            $expectedWorkshop = $sourceType === CashSettlement::SOURCE_WORKSHOP_CASH;

            if ($sourceType !== CashSettlement::SOURCE_EDC && (bool) $branch->is_workshop !== $expectedWorkshop) {
                throw new DomainException('Jenis setoran kas tidak sesuai dengan tipe cabang.');
            }

            if ($sourceType === CashSettlement::SOURCE_EDC && $branch->is_workshop) {
                throw new DomainException('Settlement EDC hanya dapat dicatat untuk cabang outlet.');
            }

            $bank = BankAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereKey($targetBankId)
                ->first();

            if ($bank === null) {
                throw new DomainException('Rekening bank tidak ditemukan, nonaktif, atau bukan milik tenant ini.');
            }

            $machine = null;

            if ($sourceType === CashSettlement::SOURCE_EDC) {
                $machine = EdcMachine::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $branchId)
                    ->where('bank_account_id', $targetBankId)
                    ->where('is_active', true)
                    ->whereKey($edcMachineId)
                    ->first();

                if ($machine === null) {
                    throw new DomainException('Mesin EDC harus aktif, terdaftar pada cabang ini, dan terhubung ke rekening tujuan.');
                }
            }

            $accounts = ChartOfAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('code', [
                    $sourceType === CashSettlement::SOURCE_EDC ? '1030' : '1000',
                    '5500',
                ])
                ->get()
                ->keyBy('code');

            $bankAccount = ChartOfAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereKey($bank->coa_id)
                ->first();

            $clearingCode = $sourceType === CashSettlement::SOURCE_EDC ? '1030' : '1000';

            $clearingAccount = $accounts->get($clearingCode);
            $mdrAccount = $accounts->get('5500');

            if ($clearingAccount === null || $bankAccount === null
                || $bankAccount->code === $clearingCode || $bankAccount->code === '5500'
                || $clearingAccount->account_type !== 'asset'
                || $bankAccount->account_type !== 'asset') {
                throw new DomainException('Akun Kas/EDC Clearing dan rekening bank harus tersedia, aktif, serta bertipe aset.');
            }

            if ($sourceType === CashSettlement::SOURCE_EDC
                && (! $clearingAccount->is_system || $clearingAccount->name !== 'Piutang Settlement EDC')) {
                throw new DomainException('Kode akun 1030 sudah digunakan oleh akun lain. Perbaiki konflik kode akun EDC Clearing sebelum mencatat settlement.');
            }

            if (! $mdr->isZero() && ($mdrAccount === null || $mdrAccount->account_type !== 'expense')) {
                throw new DomainException('Akun Beban MDR (5500) harus tersedia, aktif, dan bertipe beban.');
            }

            $settlement = CashSettlement::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'source_type' => $sourceType,
                'amount' => (string) $gross,
                'gross_amount' => (string) $gross,
                'mdr_amount' => (string) $mdr,
                'net_amount' => (string) $net,
                'edc_machine_id' => $machine?->getKey(),
                'target_bank_id' => $bank->getKey(),
                'reference_number' => $referenceNumber,
                'settled_at' => $settledAt,
                'created_by' => $createdBy ?? Auth::id(),
            ]);

            $entries = [
                [
                    'account_code' => $bankAccount->code,
                    'debit' => (string) $net,
                    'credit' => '0.00',
                ],
            ];

            if ($sourceType === CashSettlement::SOURCE_EDC && ! $mdr->isZero()) {
                $entries[] = [
                    'account_code' => '5500',
                    'debit' => (string) $mdr,
                    'credit' => '0.00',
                ];
            }

            $entries[] = [
                'account_code' => $clearingCode,
                'debit' => '0.00',
                'credit' => (string) $gross,
            ];

            app(CreateJournal::class)->handle(
                tenantId: $tenantId,
                branchId: $branchId,
                journalDate: $settledAt,
                referenceType: 'cash_settlement',
                referenceId: $settlement->getKey(),
                description: $sourceType === CashSettlement::SOURCE_EDC
                    ? 'Settlement EDC '.($machine?->terminal_name ?? '')
                    : 'Setoran kas '.($expectedWorkshop ? 'workshop' : 'outlet'),
                entries: $entries,
                createdBy: $createdBy ?? Auth::id(),
            );

            return $settlement->load(['branch', 'edcMachine', 'targetBank']);
        });
    }

    /**
     * Calculate the configured MDR estimate for display in a form. This is not
     * a substitute for the actual MDR/net amounts on the bank settlement slip.
     */
    public function estimateMdr(string|int $grossAmount, string|int $mdrPercent): string
    {
        try {
            return BigDecimal::of((string) $grossAmount)
                ->multipliedBy(BigDecimal::of((string) $mdrPercent))
                ->dividedBy('100', 2, RoundingMode::HalfUp)
                ->toScale(2)
                ->__toString();
        } catch (NumberFormatException|RoundingNecessaryException) {
            throw new DomainException('Nominal gross atau persentase MDR tidak valid.');
        }
    }
}
