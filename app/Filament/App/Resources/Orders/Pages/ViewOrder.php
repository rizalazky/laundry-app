<?php

namespace App\Filament\App\Resources\Orders\Pages;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Order;
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
            $this->recordPaymentAction(),
            $this->changeStatusAction(),
            $this->refundAction(),
        ];
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
                            && $status->canBeSetByRole(Auth::user()->role))
                        ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->native(false)
                    ->required(),
                Textarea::make('note')->label('Catatan')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, Order $record): void {
                $this->runLedgerAction(fn () => app(ChangeOrderStatus::class)->handle(
                    order: $record,
                    to: OrderStatus::from($data['status']),
                    user: Auth::user(),
                    note: $data['note'] ?? null,
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
