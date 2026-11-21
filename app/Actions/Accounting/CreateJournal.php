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
                    ->with('entries')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $actualEntries = $existing->entries
                        ->mapWithKeys(fn (JournalEntry $entry): array => [
                            $entry->coa_id => [
                                'debit' => $entry->debit,
                                'credit' => $entry->credit,
                            ],
                        ])
                        ->sortKeys()
                        ->all();

                    $sameHeader = $existing->branch_id === $branchId
                        && $existing->journal_date->equalTo($journalDate)
                        && $existing->description === $description;

                    if ($sameHeader && $actualEntries === $expectedEntries) {
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
    }
}
