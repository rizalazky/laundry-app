<?php

namespace App\Filament\App\Resources\Orders\Pages;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->printReceiptAction(),
            $this->recordPaymentAction(),
            $this->changeStatusAction(),\n            $this->assignCourierTaskAction(),
            $this->refundAction(),
        ];
    }

    private function printReceiptAction(): Action
    {
        return Action::make('printReceipt')
            ->label('Cetak Nota')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->authorize('view')
            ->url(fn (Order $record): string => route('orders.receipt', $record))
            ->openUrlInNewTab();
    }


    private function assignCourierTaskAction(): Action
    {
        return Action::make('assignCourierTask')
            ->label('Tugaskan Kurir')
            ->icon('heroicon-o-truck')
            ->color('info')
            ->authorize('view')
            ->visible(fn (Order $record): bool => in_array($record->status, [OrderStatus::Received, OrderStatus::Ready], true)
                && Auth::user()?->hasPermissionTo('courier_tasks.manage') === true)
            ->modalHeading('Buat Tugas Pickup / Delivery')
            ->schema(fn (Order $record): array => [
                Select::make('task_type')
                    ->label('Jenis Tugas')
                    ->options($record->status === OrderStatus::Ready
                        ? [CourierTaskType::Delivery->value => CourierTaskType::Delivery->label()]
                        : [CourierTaskType::Pickup->value => CourierTaskType::Pickup->label()])
                    ->default($record->status === OrderStatus::Ready ? CourierTaskType::Delivery->value : CourierTaskType::Pickup->value)
                    ->required()
                    ->native(false),
                Select::make('courier_id')
                    ->label('Kurir')
                    ->options(fn (Order $record): array => User::query()
                        ->where('tenant_id', $record->tenant_id)
                        ->whereIn('role', ['cashier', 'operator'])
                        ->where('is_active', true)
                        ->whereHas('branches', fn ($query) => $query
                            ->whereKey($record->branch_id)
                            ->where('branches.tenant_id', $record->tenant_id)
                            ->where('branches.is_active', true))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false),
                Textarea::make('address')
                    ->label('Alamat Pickup / Delivery')
                    ->default(fn (Order $record): ?string => $record->customer?->address)
                    ->rows(3)
                    ->required()
                    ->maxLength(2000),
            ])
            ->action(function (array $data, Order $record): void {
                try {
                    app(CreateCourierTask::class)->handle(
                        order: $record,
                        type: CourierTaskType::from($data['task_type']),
                        address: $data['address'],
                        courierId: (int) $data['courier_id'],
                        user: Auth::user(),
                    );

                    Notification::make()->title('Tugas kurir berhasil dibuat.')->success()->send();
                    $this->record->refresh();
                } catch (DomainException $exception) {
                    Notification::make()->title('Gagal membuat tugas kurir.')->body($exception->getMessage())->danger()->send();
                }
            });
    }

    private function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Catat Pembayaran')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->authorize('recordPayment')
            ->visible(fn (Order $record): bool => ! $record->status->isFinal()
                && BigDecimal::of($record->balanceDue())->isGreaterThan(0))
            ->modalHeading('Catat Pembayaran')
            ->fillForm(fn (Order $record): array => [
                'amount' => $record->balanceDue(),
                'method' => PaymentMethod::Cash->value,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->schema(fn (Order $record): array => [
                TextInput::make('amount')
                    ->label('Nominal')
                    ->helperText('Sisa tagihan: Rp '.number_format((float) $record->balanceDue(), 0, ',', '.'))
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue((float) $record->balanceDue())
                    ->prefix('Rp')
                    ->required(),
                Select::make('method')
                    ->label('Metode')
                    ->options(PaymentMethod::options())
                    ->default(PaymentMethod::Cash->value)
                    ->native(false)
                    ->required(),
                TextInput::make('reference')->label('No. Referensi')->maxLength(255),
                Textarea::make('notes')->label('Catatan')->rows(2)->maxLength(500),
                Hidden::make('idempotency_key'),
            ])
            ->action(function (array $data, Order $record): void {
                $this->runLedgerAction(fn () => app(RecordPayment::class)->handle(
                    order: $record,
                    amount: $data['amount'],
                    method: PaymentMethod::from($data['method']),
                    user: Auth::user(),
                    reference: $data['reference'] ?? null,
                    notes: $data['notes'] ?? null,
                    idempotencyKey: $data['idempotency_key'] ?? null,
                ), 'Pembayaran dicatat');
            });
    }

    private function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize('refund')
            ->visible(fn (Order $record): bool => BigDecimal::of($record->paid_amount)->isGreaterThan(0))
            ->requiresConfirmation()
            ->modalHeading('Catat Refund')
            ->fillForm(fn (): array => [
                'method' => PaymentMethod::Cash->value,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->schema(fn (Order $record): array => [
                TextInput::make('amount')
                    ->label('Nominal Refund')
                    ->helperText('Sudah dibayar: Rp '.number_format((float) $record->paid_amount, 0, ',', '.'))
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue((float) $record->paid_amount)
                    ->prefix('Rp')
                    ->required(),
                Select::make('method')
                    ->label('Dikembalikan Lewat')
                    ->options(PaymentMethod::options())
                    ->default(PaymentMethod::Cash->value)
                    ->native(false)
                    ->required(),
                Textarea::make('notes')->label('Alasan')->rows(2)->required()->maxLength(500),
                Hidden::make('idempotency_key'),
            ])
            ->action(function (array $data, Order $record): void {
                $this->runLedgerAction(fn () => app(RecordPayment::class)->handle(
                    order: $record,
                    amount: $data['amount'],
                    method: PaymentMethod::from($data['method']),
                    user: Auth::user(),
                    type: PaymentType::Refund,
                    notes: $data['notes'],
                    idempotencyKey: $data['idempotency_key'] ?? null,
                ), 'Refund dicatat');
            });
    }

    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Ubah Status')
            ->icon('heroicon-o-arrow-path')
            ->authorize('changeStatus')
            ->visible(fn (Order $record): bool => ! $record->status->isFinal())
            ->modalHeading('Ubah Status Order')
            ->schema(fn (Order $record): array => [
                Select::make('status')
                    ->label('Status Baru')
                    ->options(fn (): array => collect(OrderStatus::cases())
                        ->filter(fn (OrderStatus $status): bool => $record->status->canMoveTo($status)
                            && $status->canBeSetByRole(Auth::user()->role)
                            && ($status !== OrderStatus::Cancelled || Auth::user()->can('cancel', $record)))
                        ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->native(false)
                    ->required(),
                Select::make('worker_id')
                    ->label('Petugas Produksi')
                    ->helperText('Opsional. Pilih jika petugas yang mengerjakan diketahui.')
                    ->options(fn (Order $record): array => User::query()
                        ->where('tenant_id', $record->tenant_id)
                        ->where('role', 'operator')
                        ->where('is_active', true)
                        ->whereHas('branches', fn ($query) => $query->whereKey($record->branch_id))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->native(false),
                TextInput::make('machine_code')
                    ->label('Kode Mesin')
                    ->placeholder('Contoh: WASH-02')
                    ->maxLength(50),
                Textarea::make('note')->label('Catatan')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, Order $record): void {
                $targetStatus = OrderStatus::from($data['status']);

                if ($targetStatus === OrderStatus::Cancelled) {
                    abort_unless(Auth::user()->can('cancel', $record), 403);
                }

                $this->runLedgerAction(fn () => app(ChangeOrderStatus::class)->handle(
                    order: $record,
                    to: $targetStatus,
                    user: Auth::user(),
                    note: $data['note'] ?? null,
                    workerId: isset($data['worker_id']) ? (int) $data['worker_id'] : null,
                    machineCode: $data['machine_code'] ?? null,
                ), 'Status diperbarui');
            });
    }

    private function runLedgerAction(callable $callback, string $successTitle): void
    {
        try {
            $callback();
        } catch (DomainException $exception) {
            Notification::make()->danger()->title('Gagal')->body($exception->getMessage())->send();

            return;
        }

        $this->record->refresh();

        Notification::make()->success()->title($successTitle)->send();
    }
}
