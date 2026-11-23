<?php

namespace App\Filament\App\Resources\Payrolls\Pages;

use App\Actions\Payroll\CreatePayroll;
use App\Filament\App\Resources\Payrolls\PayrollResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListPayrolls extends ListRecords
{
    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createPayroll')
                ->label('Buat Payroll')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => PayrollResource::canManage())
                ->schema([
                    Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn (): array => PayrollResource::manageableEmployeeOptions())
                        ->searchable()
                        ->required(),
                    TextInput::make('period_month')
                        ->label('Periode (YYYY-MM)')
                        ->placeholder(now()->format('Y-m'))
                        ->maxLength(7)
                        ->required(),
                    TextInput::make('loan_deduction')
                        ->label('Potongan Kasbon')
                        ->numeric()
                        ->minValue(0)
                        ->default('0.00')
                        ->prefix('Rp')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CreatePayroll::class)->handle(
                            Auth::user(),
                            (int) $data['user_id'],
                            $data['period_month'],
                            $data['loan_deduction'],
                        );

                        Notification::make()->title('Payroll berhasil dibuat sebagai draft.')->success()->send();
                    } catch (DomainException $exception) {
                        Notification::make()->title('Payroll gagal dibuat.')->body($exception->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
