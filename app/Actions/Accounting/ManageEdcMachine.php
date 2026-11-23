<?php

namespace App\Actions\Accounting;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\EdcMachine;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageEdcMachine
{
    public function __construct(
        private FinanceActionAuthorization $authorization,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): EdcMachine
    {
        $branch = $this->authorizedOutlet($user, (int) ($data['branch_id'] ?? 0));
        $bank = $this->authorizedBankAccount($user, (int) ($data['bank_account_id'] ?? 0));

        return DB::transaction(fn (): EdcMachine => EdcMachine::query()->create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branch->getKey(),
            'bank_account_id' => $bank->getKey(),
            'terminal_name' => $data['terminal_name'],
            'mdr_percent' => $data['mdr_percent'] ?? '0.00',
            'is_active' => $data['is_active'] ?? true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, EdcMachine $machine, array $data): EdcMachine
    {
        if ((int) $machine->tenant_id !== (int) $user->tenant_id) {
            throw ValidationException::withMessages([
                'data.branch_id' => 'Mesin EDC tidak ditemukan pada tenant ini.',
            ]);
        }

        // Authorize both the existing and requested branch. This prevents a
        // forged record ID or branch_id from moving a machine across branches.
        $this->authorizedOutlet($user, (int) $machine->branch_id);
        $branch = $this->authorizedOutlet($user, (int) ($data['branch_id'] ?? 0));
        $bank = $this->authorizedBankAccount($user, (int) ($data['bank_account_id'] ?? 0));

        if ($machine->settlements()->exists()) {
            if (
                (int) $branch->getKey() !== (int) $machine->branch_id
                || (int) $bank->getKey() !== (int) $machine->bank_account_id
            ) {
                throw ValidationException::withMessages([
                    'data.branch_id' => 'Cabang dan rekening tidak dapat diubah setelah mesin EDC memiliki settlement.',
                ]);
            }
        }

        return DB::transaction(function () use ($machine, $branch, $bank, $data): EdcMachine {
            $machine->update([
                'branch_id' => $branch->getKey(),
                'bank_account_id' => $bank->getKey(),
                'terminal_name' => $data['terminal_name'],
                'mdr_percent' => $data['mdr_percent'] ?? '0.00',
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $machine->refresh();
        });
    }

    private function authorizedOutlet(User $user, int $branchId): Branch
    {
        try {
            $branch = $this->authorization->authorizeBranch((int) $user->tenant_id, $branchId);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'data.branch_id' => $exception->getMessage(),
            ]);
        }

        if ($branch->is_workshop) {
            throw ValidationException::withMessages([
                'data.branch_id' => 'Mesin EDC hanya dapat ditempatkan pada outlet, bukan workshop.',
            ]);
        }

        return $branch;
    }

    private function authorizedBankAccount(User $user, int $bankAccountId): BankAccount
    {
        $bank = BankAccount::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereKey($bankAccountId)
            ->first();

        if ($bank === null) {
            throw ValidationException::withMessages([
                'data.bank_account_id' => 'Pilih rekening bank aktif dari tenant ini.',
            ]);
        }

        return $bank;
    }
}
