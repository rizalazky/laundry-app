<?php

namespace App\Filament\App\Resources\CashSettlements\Pages;

use App\Actions\Accounting\RecordFinancialSettlement;
use App\Filament\App\Resources\CashSettlements\CashSettlementResource;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateCashSettlement extends CreateRecord
{
    protected static string $resource = CashSettlementResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();
        $branch = Branch::query()
            ->where('tenant_id', $user?->tenant_id)
            ->find($data['branch_id'] ?? null);

        if (! $user instanceof User || ! $branch instanceof Branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'Lokasi atau pengguna aktif tidak valid.',
            ]);
        }

        if ($user->role !== 'tenant_owner' && (int) $branch->getKey() !== (int) Filament::getTenant()?->getKey()) {
            throw ValidationException::withMessages([
                'branch_id' => 'Anda tidak dapat mencatat settlement untuk cabang lain.',
            ]);
        }

        $sourceType = (string) $data['source_type'];
        $grossAmount = (string) $data['gross_amount'];
        $isEdc = $sourceType === CashSettlement::SOURCE_EDC;

        try {
            $settlement = app(RecordFinancialSettlement::class)->handle(
                tenantId: (int) $user->tenant_id,
                branchId: (int) $branch->getKey(),
                sourceType: $sourceType,
                targetBankId: (int) $data['target_bank_id'],
                grossAmount: $grossAmount,
                mdrAmount: $isEdc ? (string) $data['mdr_amount'] : '0.00',
                netAmount: $isEdc ? (string) $data['net_amount'] : $grossAmount,
                settledAt: \Illuminate\Support\Carbon::parse($data['settled_at']),
                edcMachineId: $isEdc ? (int) $data['edc_machine_id'] : null,
                referenceNumber: (string) $data['reference_number'],
                createdBy: (int) $user->getKey(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'gross_amount' => $exception->getMessage(),
            ]);
        }

        Notification::make()
            ->title('Settlement berhasil dicatat')
            ->body('Jurnal akuntansi berpasangan telah dibuat.')
            ->success()
            ->send();

        return $settlement;
    }
}
