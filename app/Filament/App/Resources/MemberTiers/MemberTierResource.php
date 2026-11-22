<?php

namespace App\Filament\App\Resources\MemberTiers;

use App\Filament\App\Resources\MemberTiers\Pages\CreateMemberTier;
use App\Filament\App\Resources\MemberTiers\Pages\EditMemberTier;
use App\Filament\App\Resources\MemberTiers\Pages\ListMemberTiers;
use App\Filament\App\Resources\MemberTiers\Schemas\MemberTierForm;
use App\Filament\App\Resources\MemberTiers\Tables\MemberTiersTable;
use App\Models\MemberTier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MemberTierResource extends Resource
{
    protected static ?string $model = MemberTier::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 20;
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Jenis Member';
    protected static ?string $modelLabel = 'Jenis Member';
    protected static ?string $pluralModelLabel = 'Jenis Member';

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

    public static function canEdit($record): bool
    {
        return static::canCreate();
    }

    public static function canDelete($record): bool
    {
        return static::canCreate() && ! $record->memberships()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return MemberTierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MemberTiersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberTiers::route('/'),
            'create' => CreateMemberTier::route('/create'),
            'edit' => EditMemberTier::route('/{record}/edit'),
        ];
    }
}
