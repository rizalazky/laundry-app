<?php

namespace App\Filament\App\Resources\EmployeeLoans\Pages;

use App\Actions\EmployeeLoans\CreateEmployeeLoan;
use App\Filament\App\Resources\EmployeeLoans\EmployeeLoanResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListEmployeeLoans extends ListRecords
{
    protected static string $resource = EmployeeLoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grantLoan')
                ->label('Buat Kasbon')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => EmployeeLoanResource::canManage())
                ->schema([
                    Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn (): array => EmployeeLoanResource::manageableEmployeeOptions())
                        ->searchable()
                        ->required(),

                    TextInput::make('amount')
                        ->label('Nominal Kasbon')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(9999999999999.99)
                        ->prefix('Rp')
                        ->required(),

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CreateEmployeeLoan::class)->handle(
                            Auth::user(),
                            (int) $data['user_id'],
                            $data['amount'],
                            $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title('Kasbon karyawan berhasil dibuat.')
                            ->success()
                            ->send();
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Kasbon gagal dibuat.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
