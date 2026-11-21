<?php

namespace App\Actions\Accounting;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Actions\Accounting\TenantScopedIdempotency;

class RecordBankTransfer
{
    /**
     * Move funds between two bank accounts and post the corresponding journal.
     *
     * Both bank accounts must map to different active COA accounts so the
     * transfer remains visible in the general ledger.
     *
     * @throws DomainException
     */
    public function handle(
        int $tenantId,
        int $sourceBankId,
        int $destinationBankId,
        string|int $amount,
        CarbonInterface $transferredAt,
        ?int $branchId = null,
        ?string $referenceNumber = null,
        ?int $createdBy = null,
        ?string $idempotencyKey = null,
    ): BankTransfer {
        $authenticatedTenantId = Auth::user()?->tenant_id;

        if ($authenticatedTenantId !== null && (int) $authenticatedTenantId !== $tenantId) {
            throw new DomainException('Tenant transfer tidak sesuai dengan tenant pengguna.');
        }

        if ($sourceBankId === $destinationBankId) {
            throw new DomainException('Rekening sumber dan tujuan harus berbeda.');
        }

        try {
            $transferAmount = BigDecimal::of((string) $amount)->toScale(2);
        } catch (NumberFormatException|RoundingNecessaryException) {
            throw new DomainException('Nominal transfer harus berupa angka dengan maksimal 2 desimal.');
        }

        if ($transferAmount->isLessThanOrEqualTo(0)) {
            throw new DomainException('Nominal transfer harus lebih besar dari nol.');
        }

        $referenceNumber = $referenceNumber !== null ? trim($referenceNumber) : null;
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
        $referenceNumber = $referenceNumber === '' ? null : $referenceNumber;
        $idempotencyKey = $idempotencyKey === '' ? null : $idempotencyKey;

        if ($referenceNumber !== null && strlen($referenceNumber) > 100) {
            throw new DomainException('Reference number maksimal 100 karakter.');
        }

        if ($idempotencyKey !== null && strlen($idempotencyKey) > 128) {
            throw new DomainException('Idempotency key maksimal 128 karakter.');
        }

        if ($referenceNumber === null && $idempotencyKey === null) {
            throw new DomainException('Reference number atau idempotency key wajib diisi untuk mencegah pencatatan ganda.');
        }

        $payloadHash = hash('sha256', json_encode([
            'branch_id' => $branchId,
            'source_bank_id' => $sourceBankId,
            'destination_bank_id' => $destinationBankId,
            'amount' => (string) $transferAmount,
            'transferred_at' => $transferredAt->format('Y-m-d H:i:s'),
            'reference_number' => $referenceNumber,
        ], JSON_THROW_ON_ERROR));

        return app(TenantScopedIdempotency::class)->execute(
            modelClass: BankTransfer::class,
            tenantId: $tenantId,
            referenceNumber: $referenceNumber,
            idempotencyKey: $idempotencyKey,
            payloadHash: $payloadHash,
            callback: function () use (
            $tenantId,
            $sourceBankId,
            $destinationBankId,
            $transferAmount,
            $transferredAt,
            $branchId,
            $referenceNumber,
            $createdBy,
            $idempotencyKey,
            $payloadHash,
        ): BankTransfer {
            if ($branchId !== null && ! Branch::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)->whereKey($branchId)->exists()) {
                throw new DomainException('Cabang tidak ditemukan pada tenant ini.');
            }

            $banks = BankAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('id', [$sourceBankId, $destinationBankId])
                ->get()
                ->keyBy('id');

            if ($banks->count() !== 2) {
                throw new DomainException('Kedua rekening harus aktif dan berasal dari tenant yang sama.');
            }

            $accounts = ChartOfAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('id', [
                    $banks->get($sourceBankId)->coa_id,
                    $banks->get($destinationBankId)->coa_id,
                ])
                ->get()
                ->keyBy('id');

            if ($accounts->count() !== 2) {
                throw new DomainException('Akun COA rekening sumber atau tujuan tidak aktif atau tidak ditemukan.');
            }

            $sourceAccount = $accounts->get($banks->get($sourceBankId)->coa_id);
            $destinationAccount = $accounts->get($banks->get($destinationBankId)->coa_id);

            if ($sourceAccount->account_type !== 'asset' || $destinationAccount->account_type !== 'asset') {
                throw new DomainException('Akun COA rekening sumber dan tujuan harus bertipe aset.');
            }

            if ($sourceAccount->getKey() === $destinationAccount->getKey()) {
                throw new DomainException('Rekening sumber dan tujuan harus menggunakan akun COA yang berbeda.');
            }

            $transfer = BankTransfer::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'source_bank_id' => $sourceBankId,
                'destination_bank_id' => $destinationBankId,
                'amount' => (string) $transferAmount,
                'reference_number' => $referenceNumber,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'transferred_at' => $transferredAt,
                'created_by' => $createdBy ?? Auth::id(),
            ]);

            app(CreateJournal::class)->handle(
                tenantId: $tenantId,
                branchId: $branchId,
                journalDate: $transferredAt,
                referenceType: 'bank_transfer',
                referenceId: $transfer->getKey(),
                description: 'Transfer antar rekening bank',
                entries: [
                    [
                        'account_code' => $destinationAccount->code,
                        'debit' => (string) $transferAmount,
                        'credit' => '0.00',
                    ],
                    [
                        'account_code' => $sourceAccount->code,
                        'debit' => '0.00',
                        'credit' => (string) $transferAmount,
                    ],
                ],
                createdBy: $createdBy ?? Auth::id(),
            );

            return $transfer;
        })->load(['branch', 'sourceBank', 'destinationBank']);
    }
}
