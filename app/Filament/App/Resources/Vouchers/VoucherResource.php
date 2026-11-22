<?php

namespace App\Filament\App\Resources\Vouchers;

use App\Filament\App\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\App\Resources\Vouchers\Pages\EditVoucher;
use App\Filament\App\Resources\Vouchers\Pages\ListVouchers;
use App\Filament\App\Resources\Vouchers\Schemas\VoucherForm;
use App\Filament\App\Resources\Vouchers\Tables\VouchersTable;
use App\Models\Voucher;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 31;
    protected static ?string $recordTitleAttribute = 'code';
    protected static ?string $navigationLabel = 'Voucher';
    protected static ?string $modelLabel = 'Voucher';
    protected static ?string $pluralModelLabel = 'Voucher';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('promotions.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('promotions.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canCreate() && (int) $record->used_count === 0;
    }

    public static function canDelete($record): bool
    {
        return static::canCreate() && (int) $record->used_count === 0;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Auth::user()?->tenant_id)->with('promo');
    }

    public static function form(Schema $schema): Schema
    {
        return VoucherForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VouchersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVouchers::route('/'),
            'create' => CreateVoucher::route('/create'),
            'edit' => EditVoucher::route('/{record}/edit'),
        ];
    }
}
