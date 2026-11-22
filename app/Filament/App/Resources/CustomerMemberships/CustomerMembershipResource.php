<?php

namespace App\Filament\App\Resources\CustomerMemberships;

use App\Filament\App\Resources\CustomerMemberships\Pages\ListCustomerMemberships;
use App\Models\CustomerMembership;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CustomerMembershipResource extends Resource
{
    protected static ?string $model = CustomerMembership::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 21;
    protected static ?string $recordTitleAttribute = 'id';
    protected static ?string $navigationLabel = 'Riwayat Membership';
    protected static ?string $modelLabel = 'Membership Pelanggan';
    protected static ?string $pluralModelLabel = 'Riwayat Membership';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('customers.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('customers.manage') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with(['customer', 'memberTier']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return CustomerMembershipTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerMemberships::route('/'),
        ];
    }
}
