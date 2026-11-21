<?php

namespace App\Filament\App\Resources\StockOpnames\Tables;

use App\Actions\Inventory\ManageStockOpname;
use App\Models\StockOpname;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class StockOpnamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opname_date', 'desc')
            ->columns([
                TextColumn::make('id')->label('No. Opname')->sortable(),
                TextColumn::make('branch.name')->label('Lokasi')->placeholder('Lokasi tidak tersedia'),
                TextColumn::make('location_type')->label('Jenis Lokasi')
                    ->formatStateUsing(fn (string $state): string => $state === 'workshop' ? 'Workshop' : 'Outlet'),
                TextColumn::make('opname_date')->label('Tanggal')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Jumlah Barang'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => $state === 'completed' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => $state === 'completed' ? 'Selesai' : 'Draft'),
                TextColumn::make('createdBy.name')->label('Dibuat Oleh'),
                TextColumn::make('finalized_at')->label('Difinalisasi')->dateTime('d M Y H:i')->placeholder('Belum'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['draft' => 'Draft', 'completed' => 'Selesai']),
                SelectFilter::make('location_type')->options(['outlet' => 'Outlet', 'workshop' => 'Workshop']),
            ])
            ->recordActions([
                Action::make('count')
                    ->label('Input Hitungan')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (StockOpname $record): bool => $record->status === 'draft')
                    ->modalHeading('Input Hasil Hitung Fisik')
                    ->modalWidth('5xl')
                    ->schema([
                        Repeater::make('items')
                            ->label('Daftar Barang')
                            ->schema([
                                Hidden::make('id')->required(),
                                TextInput::make('item_name_snapshot')
                                    ->label('Barang')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('item_sku_snapshot')
                                    ->label('SKU')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('unit_snapshot')
                                    ->label('Satuan')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('system_qty')
                                    ->label('Stok Sistem')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('physical_qty')
                                    ->label('Stok Fisik')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('notes')
                                    ->label('Catatan')
                                    ->maxLength(255),
                            ])
                            ->columns(6)
                            ->reorderable(false)
                            ->addable(false)
                            ->deletable(false)
                            ->defaultItems(0),
                    ])
                    ->fillForm(fn (StockOpname $record): array => [
                        'items' => $record->items()->orderBy('id')->get()->map(fn ($line): array => [
                            'id' => $line->id,
                            'item_name_snapshot' => $line->item_name_snapshot,
                            'item_sku_snapshot' => $line->item_sku_snapshot,
                            'unit_snapshot' => $line->unit_snapshot,
                            'system_qty' => $line->system_qty,
                            'physical_qty' => $line->physical_qty,
                            'notes' => $line->notes,
                        ])->all(),
                    ])
                    ->action(function (StockOpname $record, array $data): void {
                        $counts = collect($data['items'] ?? [])
                            ->mapWithKeys(fn (array $line): array => [
                                $line['id'] => [
                                    'physical_qty' => $line['physical_qty'] ?? '',
                                    'notes' => $line['notes'] ?? null,
                                ],
                            ])->all();

                        app(ManageStockOpname::class)->recordCounts(Auth::user(), $record, $counts);
                    }),

                Action::make('finalize')
                    ->label('Finalisasi')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Stok cabang akan disesuaikan dengan hasil hitung fisik. Tindakan ini tidak dapat dibatalkan.')
                    ->visible(fn (StockOpname $record): bool => $record->status === 'draft')
                    ->action(fn (StockOpname $record) => app(ManageStockOpname::class)->finalize(Auth::user(), $record)),

                Action::make('view')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (StockOpname $record): string => 'Detail Stok Opname #'.$record->id)
                    ->modalWidth('5xl')
                    ->schema([
                        Repeater::make('items')
                            ->label('Hasil Opname')
                            ->schema([
                                TextInput::make('item_name_snapshot')->label('Barang')->disabled()->dehydrated(false),
                                TextInput::make('unit_snapshot')->label('Satuan')->disabled()->dehydrated(false),
                                TextInput::make('system_qty')->label('Stok Sistem')->disabled()->dehydrated(false),
                                TextInput::make('physical_qty')->label('Stok Fisik')->disabled()->dehydrated(false),
                                TextInput::make('difference_qty')->label('Selisih')->disabled()->dehydrated(false),
                                TextInput::make('notes')->label('Catatan')->disabled()->dehydrated(false),
                            ])
                            ->columns(6)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ])
                    ->fillForm(fn (StockOpname $record): array => [
                        'items' => $record->items()->orderBy('id')->get()->map(fn ($line): array => [
                            'item_name_snapshot' => $line->item_name_snapshot,
                            'unit_snapshot' => $line->unit_snapshot,
                            'system_qty' => $line->system_qty,
                            'physical_qty' => $line->physical_qty ?? 'Belum dihitung',
                            'difference_qty' => $line->difference_qty ?? '—',
                            'notes' => $line->notes,
                        ])->all(),
                    ]),
            ]);
    }
}
