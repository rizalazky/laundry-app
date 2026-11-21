<?php

namespace App\Actions\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\JournalEntry;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateJournal
{
    /**
     * @param  list<array{account_code: string, debit: string|int, credit: string|int}>  $entries
     *
     * @throws DomainException
     */
    public function handle(
        int $tenantId,
        ?int $branchId,
        CarbonInterface $journalDate,
        ?string $referenceType,
        ?int $referenceId,
        string $description,
        array $entries,
        ?int $createdBy = null,
    ): Journal {
        if (($referenceType === null) !== ($referenceId === null)) {
            throw new DomainException('Tipe dan ID referensi jurnal harus diisi bersamaan.');
        }

        if ($referenceType !== null && (blank($referenceType) || $referenceId < 1)) {
            throw new DomainException('Referensi jurnal tidak valid.');
        }

        if (blank($description)) {
            throw new DomainException('Deskripsi jurnal wajib diisi.');
        }

        if (count($entries) < 2) {
            throw new DomainException('Jurnal berpasangan minimal memiliki dua baris.');
        }

        $authenticatedTenantId = Auth::user()?->tenant_id;

        if ($authenticatedTenantId !== null && (int) $authenticatedTenantId !== $tenantId) {
            throw new DomainException('Tenant jurnal tidak sesuai dengan tenant pengguna.');
        }

        $normalizedEntries = [];
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();

        foreach ($entries as $entry) {
            if (! isset($entry['account_code'])) {
                throw new DomainException('Setiap baris jurnal wajib memiliki kode akun.');
            }

            try {
                $debit = BigDecimal::of((string) $entry['debit'])->toScale(2);
                $credit = BigDecimal::of((string) $entry['credit'])->toScale(2);
            } catch (NumberFormatException|RoundingNecessaryException) {
                throw new DomainException('Nominal debit dan kredit harus berupa angka dengan maksimal 2 desimal.');
            }

            if ($debit->isLessThan(0) || $credit->isLessThan(0)) {
                throw new DomainException('Nominal debit dan kredit tidak boleh negatif.');
            }

            if ($debit->isZero() === $credit->isZero()) {
                throw new DomainException('Setiap baris harus memiliki tepat satu sisi: debit atau kredit.');
            }

            $accountCode = trim($entry['account_code']);

            if ($accountCode === '' || isset($normalizedEntries[$accountCode])) {
                throw new DomainException('Kode akun kosong atau digunakan lebih dari sekali dalam satu jurnal.');
            }

            $normalizedEntries[$accountCode] = [
                'debit' => (string) $debit,
                'credit' => (string) $credit,
            ];

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
        }

        if ($totalDebit->isZero() || ! $totalDebit->isEqualTo($totalCredit)) {
            throw new DomainException('Total debit dan kredit harus sama dan lebih besar dari nol.');
        }

        try {
            return DB::transaction(function () use (
                $tenantId,
                $branchId,
                $journalDate,
                $referenceType,
                $referenceId,
                $description,
                $normalizedEntries,
                $createdBy,
            ): Journal {
                $accounts = ChartOfAccount::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereIn('code', array_keys($normalizedEntries))
                    ->get()
                    ->keyBy('code');

                if ($accounts->count() !== count($normalizedEntries)) {
                    throw new DomainException('Satu atau lebih akun tidak ditemukan, nonaktif, atau bukan milik tenant ini.');
                }

                $expectedEntries = [];

                foreach ($normalizedEntries as $code => $amounts) {
                    $expectedEntries[$accounts->get($code)->getKey()] = $amounts;
                }

                ksort($expectedEntries);

                if ($referenceType !== null) {
                    $existing = Journal::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenantId)
                        ->where('reference_type', $referenceType)
                        ->where('reference_id', $referenceId)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $this->loadEntriesForCurrentRead($existing);

                        if ($this->matchesExistingJournal(
                            $existing,
                            $branchId,
                            $journalDate,
                            $description,
                            $expectedEntries,
                        )) {
                            return $existing;
                        }

                        throw new DomainException('Referensi jurnal sudah digunakan dengan isi yang berbeda.');
                    }
                }

                $journal = Journal::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'journal_date' => $journalDate,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'description' => $description,
                    'created_by' => $createdBy,
                ]);

                foreach ($normalizedEntries as $code => $amounts) {
                    JournalEntry::query()->create([
                        'tenant_id' => $tenantId,
                        'journal_id' => $journal->getKey(),
                        'coa_id' => $accounts->get($code)->getKey(),
                        'debit' => $amounts['debit'],
                        'credit' => $amounts['credit'],
                    ]);
                }

                return $journal->load('entries');
            });
        } catch (QueryException $exception) {
            // A concurrent request may insert the same source reference after our
            // initial lookup. The unique constraint wins; reload its committed row
            // and treat the conflict as an idempotent retry only for an identical
            // payload. If no matching row exists, preserve the original DB error.
            if ($referenceType === null || $referenceId === null) {
                throw $exception;
            }

            $existing = Journal::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            // A locking read bypasses the caller transaction's REPEATABLE READ
            // snapshot. Load entries the same way: eager loading would otherwise
            // use a snapshot read and could miss the winner's committed entries.
            $this->loadEntriesForCurrentRead($existing);

            $accounts = ChartOfAccount::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereIn('code', array_keys($normalizedEntries))
                ->get()
                ->keyBy('code');

            if ($accounts->count() !== count($normalizedEntries)) {
                throw $exception;
            }

            $expectedEntries = [];

            foreach ($normalizedEntries as $code => $amounts) {
                $expectedEntries[$accounts->get($code)->getKey()] = $amounts;
            }

            ksort($expectedEntries);

            if ($this->matchesExistingJournal(
                $existing,
                $branchId,
                $journalDate,
                $description,
                $expectedEntries,
            )) {
                return $existing;
            }

            throw new DomainException(
                'Referensi jurnal sudah digunakan dengan isi yang berbeda.',
                previous: $exception,
            );
        }
    }

    private function loadEntriesForCurrentRead(Journal $journal): void
    {
        $entries = $journal->entries()
            ->lockForUpdate()
            ->get();

        $journal->setRelation('entries', $entries);
    }

    /**
     * @param  array<int, array{debit: string, credit: string}>  $expectedEntries
     */
    private function matchesExistingJournal(
        Journal $existing,
        ?int $branchId,
        CarbonInterface $journalDate,
        string $description,
        array $expectedEntries,
    ): bool {
        $actualEntries = $existing->entries
            ->mapWithKeys(fn (JournalEntry $entry): array => [
                $entry->coa_id => [
                    'debit' => $entry->debit,
                    'credit' => $entry->credit,
                ],
            ])
            ->sortKeys()
            ->all();

        return $existing->branch_id === $branchId
            && $existing->journal_date->format('Y-m-d H:i:s') === $journalDate->format('Y-m-d H:i:s')
            && $existing->description === $description
            && $actualEntries === $expectedEntries;
    }
}
