<?php

namespace App\Filament\App\Resources\ProfitSharings\Pages;

use App\Actions\Investors\GenerateProfitSharings;
use App\Filament\App\Resources\ProfitSharings\ProfitSharingResource;
use App\Models\Branch;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListProfitSharings extends ListRecords
{
    protected static string $resource = ProfitSharingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate Bagi Hasil')
                ->icon('heroicon-o-calculator')
                ->visible(fn (): bool => Auth::user()?->role === 'tenant_owner'
                    && (Auth::user()?->hasPermissionTo('investors.manage') ?? false))
                ->form([
                    Select::make('branch_id')
                        ->label('Cabang')
                        ->options(fn (): array => Branch::query()
                            ->where('tenant_id', Auth::user()?->tenant_id)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('period_month')
                        ->label('Periode (YYYY-MM)')
                        ->placeholder('2026-11')
                        ->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $created = app(GenerateProfitSharings::class)->handle(
                        (int) Auth::user()->tenant_id,
                        (int) $data['branch_id'],
                        $data['period_month'],
                    );

                    \Filament\Notifications\Notification::make()
                        ->title('Bagi hasil berhasil dibuat')
                        ->body(count($created).' data investor dibuat sebagai draft.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
