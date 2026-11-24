<?php

namespace App\Filament\App\Resources\MassInvoices;

use App\Models\MassInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MassInvoiceResource extends Resource
{
    protected static ?string $model = MassInvoice::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 36;
    protected static ?string $navigationLabel = 'Riwayat Faktur Massal';
    protected static ?string $modelLabel = 'Faktur Massal';
    protected static ?string $pluralModelLabel = 'Riwayat Faktur Massal';

    public static function canAccess(): bool
    {
        return Auth::user()?->is_active === true
            && Auth::user()?->role === 'tenant_owner'
            && Auth::user()?->tenant_id !== null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with(['customer', 'schedule'])
            ->withCount('items');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('generated_at', 'desc')
            ->columns([
                TextColumn::make('invoice_no')->label('No. Faktur')->searchable()->copyable(),
                TextColumn::make('customer.name')->label('Pelanggan')->searchable(),
                TextColumn::make('total_amount')->label('Total Tagihan')->money('IDR', locale: 'id')->sortable(),
                TextColumn::make('items_count')->label('Jumlah Nota')->badge(),
                TextColumn::make('billing_period_start')->label('Periode')
                    ->formatStateUsing(fn ($state, MassInvoice $record): string => $record->billing_period_start->format('d/m/Y').' - '.$record->billing_period_end->format('d/m/Y')),
                TextColumn::make('trigger_type')->label('Pemicu')
                    ->formatStateUsing(fn (string $state): string => $state === 'scheduled' ? 'Otomatis' : 'Manual')
                    ->badge(),
                TextColumn::make('generated_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('trigger_type')->label('Pemicu')->options([
                    'manual' => 'Manual',
                    'scheduled' => 'Otomatis',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\App\Resources\MassInvoices\Pages\ListMassInvoices::route('/'),
        ];
    }
}
