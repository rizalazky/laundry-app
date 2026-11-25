<?php

namespace App\Actions\Accounting;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Auth;

class RecordBankTransfer
{
    /**
     * Move funds between two bank accounts and post the corresponding journal.
     *
     * Both bank accounts must map to different active COA accounts so the
     * transfer remains visible in the general ledger.
     *
     * Authenticated requests require finance authorization. Unauthenticated
     * calls remain available to trusted internal jobs and system workflows.
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
        $actor = Auth::user();

        if ($actor !== null) {
            if (
                ! $actor instanceof User
                || ! $actor->is_active
                || $actor->tenant_id === null
                || (int) $actor->tenant_id !== $tenantId
                || ! in_array($actor->role, ['tenant_owner', 'branch_manager'], true)
                || ! $actor->hasPermissionTo('finance.manage')
            ) {
                throw new DomainException('Anda tidak memiliki izin untuk mencatat transfer bank pada tenant ini.');
            }

            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null || ! $tenant->hasActiveSubscription()) {
                throw new DomainException('Tenant tidak aktif atau masa berlangganan telah berakhir.');
            }

            if ($branchId !== null) {
                app(FinanceActionAuthorization::class)->authorizeBranch($tenantId, $branchId);
            } elseif ($actor->role !== 'tenant_owner') {
                throw new DomainException('Transfer bank untuk pengguna cabang wajib dikaitkan dengan cabang yang berwenang.');
            }

            if ($createdBy !== null && $createdBy !== (int) $actor->getKey()) {
                throw new DomainException('Pengguna pencatat transfer harus sesuai dengan akun yang sedang login.');
            }

            // Never accept an audit actor ID supplied by an authenticated caller.
            $createdBy = (int) $actor->getKey();
        } elseif ($createdBy !== null && ! User::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($createdBy)
            ->exists()) {
            throw new DomainException('Pengguna pencatat transfer tidak ditemukan pada tenant ini.');
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
            'idempotency_key' => $idempotencyKey,
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
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereKey($branchId)
                    ->exists()) {
                    throw new DomainException('Cabang tidak ditemukan, nonaktif, atau bukan milik tenant ini.');
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
                    'created_by' => $createdBy,
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
                    createdBy: $createdBy,
                );

                return $transfer;
            },
        )->load(['branch', 'sourceBank', 'destinationBank']);
    }
}
