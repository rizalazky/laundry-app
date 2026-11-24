<?php

namespace App\Filament\App\Resources\MassInvoiceSchedules;

use App\Filament\App\Resources\MassInvoiceSchedules\Pages\ListMassInvoiceSchedules;
use App\Models\Customer;
use App\Models\MassInvoiceSchedule;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MassInvoiceScheduleResource extends Resource
{
    protected static ?string $model = MassInvoiceSchedule::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 35;
    protected static ?string $navigationLabel = 'Faktur Massal';
    protected static ?string $modelLabel = 'Jadwal Faktur Massal';
    protected static ?string $pluralModelLabel = 'Faktur Massal';

    public static function canAccess(): bool
    {
        return Auth::user()?->is_active === true
            && Auth::user()?->role === 'tenant_owner'
            && Auth::user()?->tenant_id !== null;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess() && (int) $record->tenant_id === (int) Auth::user()->tenant_id;
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->withCount('customers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama Jadwal')
                ->required()
                ->maxLength(120),
            Select::make('customers')
                ->label('Pelanggan')
                ->relationship(
                    name: 'customers',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('customers.tenant_id', Auth::user()?->tenant_id)
                        ->orderBy('customers.name'),
                )
                ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->phone
                    ? $record->name.' — '.$record->phone
                    : $record->name)
                ->searchable(['name', 'phone'])
                ->multiple()
                ->preload(false)
                ->required()
                ->helperText('Pilih satu atau beberapa pelanggan. Pencarian mendukung nama dan nomor HP.'),
            Select::make('frequency')
                ->label('Frekuensi')
                ->options(['weekly' => 'Mingguan', 'monthly' => 'Bulanan'])
                ->default('monthly')
                ->required()
                ->native(false),
            TextInput::make('run_day')
                ->label('Hari Penagihan')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(28)
                ->default(1)
                ->required()
                ->helperText('Bulanan: tanggal 1–28. Mingguan: 1 (Senin) sampai 7 (Minggu).'),
            Toggle::make('is_active')
                ->label('Jadwal Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nama Jadwal')->searchable()->sortable(),
                TextColumn::make('customers_count')->label('Pelanggan')->badge(),
                TextColumn::make('frequency')
                    ->label('Frekuensi')
                    ->formatStateUsing(fn (string $state): string => $state === 'weekly' ? 'Mingguan' : 'Bulanan'),
                TextColumn::make('run_day')
                    ->label('Hari')
                    ->formatStateUsing(fn (int $state, MassInvoiceSchedule $record): string => $record->frequency === 'weekly'
                        ? ['1' => 'Senin', '2' => 'Selasa', '3' => 'Rabu', '4' => 'Kamis', '5' => 'Jumat', '6' => 'Sabtu', '7' => 'Minggu'][(string) $state]
                        : 'Tanggal '.$state),
                TextColumn::make('next_run_at')->label('Jadwal Berikutnya')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('last_run_at')->label('Terakhir Dijalankan')->dateTime('d M Y H:i')->placeholder('Belum pernah'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status Jadwal'),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('runNow')
                    ->label('Jalankan Sekarang')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Jalankan Faktur Massal Sekarang')
                    ->modalDescription('Sistem akan membuat faktur untuk pelanggan terpilih yang masih memiliki order belum lunas. Jadwal otomatis berikutnya tidak berubah.')
                    ->action(function (MassInvoiceSchedule $record): void {
                        try {
                            $count = app(\App\Actions\MassInvoices\GenerateMassInvoices::class)
                                ->handle($record, Auth::user());

                            \Filament\Notifications\Notification::make()
                                ->title($count.' faktur berhasil dibuat.')
                                ->success()
                                ->send();
                        } catch (\DomainException $exception) {
                            \Filament\Notifications\Notification::make()
                                ->title('Faktur massal gagal dijalankan.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMassInvoiceSchedules::route('/'),
        ];
    }
}
