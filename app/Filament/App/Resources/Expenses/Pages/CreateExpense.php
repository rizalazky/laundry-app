<?php

namespace App\\Filament\\App\\Resources\\Expenses\\Pages;

use App\\Actions\\Accounting\\RecordExpense;
use App\\Filament\\App\\Resources\\Expenses\\ExpenseResource;
use App\\Models\\Branch;
use App\\Models\\Expense;
use App\\Models\\User;
use DomainException;
use Filament\\Facades\\Filament;
use Filament\\Notifications\\Notification;
use Filament\\Resources\\Pages\\CreateRecord;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Support\\Carbon;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Validation\\ValidationException;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();
        $branch = Branch::query()
            ->where('tenant_id', $user?->tenant_id)
            ->where('is_active', true)
            ->find($data['branch_id'] ?? null);

        if (! $user instanceof User || ! $branch instanceof Branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'Lokasi atau pengguna aktif tidak valid.',
            ]);
        }

        if ($user->role !== 'tenant_owner' && (int) $branch->getKey() !== (int) Filament::getTenant()?->getKey()) {
            throw ValidationException::withMessages([
                'branch_id' => 'Anda tidak dapat mencatat pengeluaran untuk cabang lain.',
            ]);
        }

        try {
            $expense = app(RecordExpense::class)->handle(
                tenantId: (int) $user->tenant_id,
                branchId: (int) $branch->getKey(),
                expenseAccountId: (int) $data['coa_id'],
                paymentSourceType: (string) $data['payment_source_type'],
                bankAccountId: ($data['payment_source_type'] ?? null) === Expense::SOURCE_BANK
                    ? (int) $data['bank_account_id']
                    : null,
                amount: (string) $data['amount'],
                description: (string) $data['description'],
                spentAt: Carbon::parse($data['spent_at']),
                createdBy: (int) $user->getKey(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }

        Notification::make()
            ->title('Pengeluaran berhasil dicatat')
            ->body('Jurnal akuntansi berpasangan telah dibuat.')
            ->success()
            ->send();

        return $expense;
    }
}
