<?php

namespace App\Actions\Accounting;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecordExpense
{
    /**
     * Record an expense and its balanced journal in one transaction.
     *
     * @throws DomainException
     */
    public function handle(
        int $tenantId,
        int $branchId,
        int $expenseAccountId,
        string $paymentSourceType,
        ?int $bankAccountId,
        string|int $amount,
        string $description,
        CarbonInterface $spentAt,
        ?int $createdBy = null,
    ): Expense {
        $authenticatedTenantId = Auth::user()?->tenant_id;

        if ($authenticatedTenantId !== null && (int) $authenticatedTenantId !== $tenantId) {
            throw new DomainException('Tenant pengeluaran tidak sesuai dengan tenant pengguna.');
        }

        if (! in_array($paymentSourceType, [Expense::SOURCE_CASH, Expense::SOURCE_BANK], true)) {
            throw new DomainException('Sumber pembayaran pengeluaran tidak valid.');
        }

        try {
            $expenseAmount = BigDecimal::of((string) $amount)->toScale(2);
        } catch (NumberFormatException|RoundingNecessaryException) {
            throw new DomainException('Nominal pengeluaran harus berupa angka dengan maksimal 2 desimal.');
        }

        if ($expenseAmount->isLessThanOrEqualTo(0)) {
            throw new DomainException('Nominal pengeluaran harus lebih besar dari nol.');
        }

        $description = trim($description);

        if ($description === '' || mb_strlen($description) > 2000) {
            throw new DomainException('Deskripsi pengeluaran wajib diisi dan maksimal 2.000 karakter.');
        }

        if (($paymentSourceType === Expense::SOURCE_BANK) !== ($bankAccountId !== null)) {
            throw new DomainException('Pilih rekening bank hanya untuk sumber pembayaran bank.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $branchId,
            $expenseAccountId,
            $paymentSourceType,
            $bankAccountId,
            $expenseAmount,
            $description,
            $spentAt,
            $createdBy,
        ): Expense {
            $branch = app(FinanceActionAuthorization::class)->authorizeBranch($tenantId, $branchId);

            $expenseAccount = ChartOfAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('account_type', 'expense')
                ->whereKey($expenseAccountId)
                ->first();

            if ($expenseAccount === null) {
                throw new DomainException('Akun beban tidak ditemukan, nonaktif, atau bukan akun beban tenant ini.');
            }

            $sourceAccount = null;
            $bankAccount = null;

            if ($paymentSourceType === Expense::SOURCE_CASH) {
                $sourceAccount = ChartOfAccount::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('code', '1000')
                    ->where('account_type', 'asset')
                    ->where('is_active', true)
                    ->first();

                if ($sourceAccount === null) {
                    throw new DomainException('Akun Kas (1000) harus tersedia, aktif, dan bertipe aset.');
                }
            } else {
                $bankAccount = BankAccount::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereKey($bankAccountId)
                    ->first();

                if ($bankAccount === null) {
                    throw new DomainException('Rekening bank tidak ditemukan, nonaktif, atau bukan milik tenant ini.');
                }

                $sourceAccount = ChartOfAccount::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->where('account_type', 'asset')
                    ->whereKey($bankAccount->coa_id)
                    ->first();

                if ($sourceAccount === null || $sourceAccount->code === '1000') {
                    throw new DomainException('Akun COA rekening bank harus aktif dan bertipe aset.');
                }
            }

            $expense = Expense::query()->create([
                'tenant_id' => $tenantId,
                'location_type' => $branch->is_workshop ? 'workshop' : 'outlet',
                'location_id' => $branch->getKey(),
                'coa_id' => $expenseAccount->getKey(),
                'payment_source_type' => $paymentSourceType,
                'bank_account_id' => $bankAccount?->getKey(),
                'amount' => (string) $expenseAmount,
                'description' => $description,
                'spent_at' => $spentAt,
                'created_by' => $createdBy ?? Auth::id(),
            ]);

            app(CreateJournal::class)->handle(
                tenantId: $tenantId,
                branchId: (int) $branch->getKey(),
                journalDate: $spentAt,
                referenceType: 'expense',
                referenceId: (int) $expense->getKey(),
                description: 'Pengeluaran: '.$description,
                entries: [
                    [
                        'account_code' => $expenseAccount->code,
                        'debit' => (string) $expenseAmount,
                        'credit' => '0.00',
                    ],
                    [
                        'account_code' => $sourceAccount->code,
                        'debit' => '0.00',
                        'credit' => (string) $expenseAmount,
                    ],
                ],
                createdBy: $createdBy ?? Auth::id(),
            );

            return $expense->load(['branch', 'expenseAccount', 'bankAccount', 'creator']);
        });
    }
}
