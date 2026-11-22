<?php

namespace App\Filament\App\Resources\Vouchers\Tables;

use App\Models\Voucher;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Kode Voucher')->searchable()->sortable()->copyable(),
            TextColumn::make('voucher_type')->label('Jenis')->badge()
                ->formatStateUsing(fn (string $state): string => $state === Voucher::TYPE_REFERRAL ? 'Referral' : 'Umum'),
            TextColumn::make('promo.name')->label('Promo')->placeholder('—'),
            TextColumn::make('used_count')->label('Terpakai')->numeric(),
            TextColumn::make('quota')->label('Kuota')->placeholder('Tanpa batas'),
            TextColumn::make('starts_at')->label('Mulai')->dateTime('d M Y')->placeholder('—'),
            TextColumn::make('expires_at')->label('Berakhir')->dateTime('d M Y')->placeholder('—'),
            IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            SelectFilter::make('voucher_type')->label('Jenis')->options([
                Voucher::TYPE_GENERAL => 'Umum',
                Voucher::TYPE_REFERRAL => 'Referral',
            ]),
        ])->recordActions([
            EditAction::make()->visible(fn (Voucher $record): bool => (int) $record->used_count === 0),
            DeleteAction::make()->visible(fn (Voucher $record): bool => (int) $record->used_count === 0),
        ]);
    }
}
