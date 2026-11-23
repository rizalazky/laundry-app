<?php

namespace App\\Filament\\App\\Resources\\Expenses;

use App\\Filament\\App\\Resources\\Expenses\\Pages\\CreateExpense;
use App\\Filament\\App\\Resources\\Expenses\\Pages\\ListExpenses;
use App\\Models\\BankAccount;
use App\\Models\\Branch;
use App\\Models\\ChartOfAccount;
use App\\Models\\Expense;
use BackedEnum;
use Filament\\Facades\\Filament;
use Filament\\Forms\\Components\\DateTimePicker;
use Filament\\Forms\\Components\\Select;
use Filament\\Forms\\Components\\TextInput;
use Filament\\Forms\\Components\\Textarea;
use Filament\\Resources\\Resource;
use Filament\\Schemas\\Components\\Section;
use Filament\\Schemas\\Components\\Utilities\\Get;
use Filament\\Schemas\\Schema;
use Filament\\Tables\\Columns\\TextColumn;
use Filament\\Tables\\Filters\\SelectFilter;
use Filament\\Tables\\Table;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Support\\Facades\\Auth;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';

    protected static ?int $navigationSort = 14;

    protected static ?string $navigationLabel = 'Pengeluaran Biaya';

    protected static ?string $modelLabel = 'Pengeluaran';

    protected static ?string $pluralModelLabel = 'Pengeluaran Biaya';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && $user->hasPermissionTo('finance.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('finance.manage') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = parent::getEloquentQuery()
            ->where('tenant_id', $user?->tenant_id)
            ->with(['branch', 'expenseAccount', 'bankAccount', 'creator']);

        if ($user?->role !== 'tenant_owner') {
            $query->where('location_id', Filament::getTenant()?->getKey());
        }

        return $query->latest('spent_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Detail Pengeluaran')
                ->columns(2)
                ->schema([
                    Select::make('branch_id')
                        ->label('Outlet / Workshop')
                        ->options(fn (): array => Branch::query()
                            ->where('tenant_id', Auth::user()?->tenant_id)
                            ->where('is_active', true)
                            ->when(
                                Auth::user()?->role !== 'tenant_owner',
                                fn (Builder $query) => $query->whereKey(Filament::getTenant()?->getKey()),
                            )
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Branch $branch): array => [
                                $branch->getKey() => $branch->name.($branch->is_workshop ? ' (Workshop)' : ' (Outlet)'),
                            ])->all())
                        ->searchable()
                        ->required(),
                    Select::make('coa_id')
                        ->label('Kategori Beban')
                        ->options(fn (): array => ChartOfAccount::query()
                            ->where('is_active', true)
                            ->where('account_type', 'expense')
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (ChartOfAccount $account): array => [
                                $account->getKey() => $account->code.' — '.$account->name,
                            ])->all())
                        ->searchable()
                        ->required(),
                    Select::make('payment_source_type')
                        ->label('Sumber Pembayaran')
                        ->options([
                            Expense::SOURCE_CASH => 'Kas Laci Cabang',
                            Expense::SOURCE_BANK => 'Rekening Bank',
                        ])
                        ->default(Expense::SOURCE_CASH)
                        ->live()
                        ->required(),
                    Select::make('bank_account_id')
                        ->label('Rekening Bank')
                        ->options(fn (): array => BankAccount::query()
                            ->where('is_active', true)
                            ->with('account')
                            ->orderBy('bank_name')
                            ->get()
                            ->mapWithKeys(fn (BankAccount $bank): array => [
                                $bank->getKey() => $bank->bank_name.' — '.$bank->account_number,
                            ])->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('payment_source_type') === Expense::SOURCE_BANK)
                        ->required(fn (Get $get): bool => $get('payment_source_type') === Expense::SOURCE_BANK)
                        ->dehydrated(fn (Get $get): bool => $get('payment_source_type') === Expense::SOURCE_BANK),
                    TextInput::make('amount')
                        ->label('Nominal Pengeluaran')
                        ->numeric()
                        ->minValue(0.01)
                        ->step('0.01')
                        ->prefix('Rp')
                        ->required(),
                    DateTimePicker::make('spent_at')
                        ->label('Tanggal Pengeluaran')
                        ->default(now())
                        ->required(),
                    Textarea::make('description')
                        ->label('Keterangan')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('spent_at', 'desc')
            ->columns([
                TextColumn::make('spent_at')->label('Tanggal')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('branch.name')->label('Lokasi')->sortable(),
                TextColumn::make('expenseAccount.code')->label('Kode Beban'),
                TextColumn::make('expenseAccount.name')->label('Kategori')->searchable(),
                TextColumn::make('description')->label('Keterangan')->limit(50)->searchable(),
                TextColumn::make('amount')->label('Nominal')->money('IDR')->sortable(),
                TextColumn::make('payment_source_type')->label('Dibayar Dari')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Expense::SOURCE_BANK ? 'Rekening Bank' : 'Kas Laci')
                    ->color(fn (string $state): string => $state === Expense::SOURCE_BANK ? 'info' : 'success'),
                TextColumn::make('bankAccount.bank_name')->label('Rekening')->placeholder('—'),
                TextColumn::make('creator.name')->label('Dicatat Oleh')->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('payment_source_type')->label('Sumber Pembayaran')->options([
                    Expense::SOURCE_CASH => 'Kas Laci Cabang',
                    Expense::SOURCE_BANK => 'Rekening Bank',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
        ];
    }
}
