<?php

namespace App\Filament\App\Resources\LoyaltyPointRules\Tables;

use App\Filament\App\Resources\LoyaltyPointRules\LoyaltyPointRuleResource;
use App\Models\LoyaltyPointRule;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoyaltyPointRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Program')->searchable()->sortable(),
            TextColumn::make('branch.name')->label('Outlet')->sortable(),
            TextColumn::make('target_type')->label('Target')->formatStateUsing(fn (string $state, LoyaltyPointRule $record): string => match ($state) {
                LoyaltyPointRule::TARGET_ALL_CUSTOMERS => 'Semua Konsumen',
                LoyaltyPointRule::TARGET_ALL_MEMBERS => 'Semua Member',
                LoyaltyPointRule::TARGET_MEMBER_TIER => 'Member '.$record->memberTier?->name,
                default => $state,
            }),
            TextColumn::make('amount_step')->label('Kelipatan')->money('IDR'),
            TextColumn::make('points_per_step')->label('Poin')->suffix(' poin'),
            TextColumn::make('starts_at')->label('Mulai')->dateTime('d/m/Y H:i'),
            TextColumn::make('ends_at')->label('Berakhir')->dateTime('d/m/Y H:i'),
            IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->recordActions([
            EditAction::make()->visible(fn (): bool => LoyaltyPointRuleResource::canCreate()),
            DeleteAction::make()->visible(fn (LoyaltyPointRule $record): bool => LoyaltyPointRuleResource::canDelete($record)),
        ])->defaultSort('starts_at', 'desc');
    }
}
