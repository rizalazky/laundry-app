<?php

namespace App\Filament\App\Resources\LoyaltyPointRules;

use App\Filament\App\Resources\LoyaltyPointRules\Pages\CreateLoyaltyPointRule;
use App\Filament\App\Resources\LoyaltyPointRules\Pages\EditLoyaltyPointRule;
use App\Filament\App\Resources\LoyaltyPointRules\Pages\ListLoyaltyPointRules;
use App\Filament\App\Resources\LoyaltyPointRules\Schemas\LoyaltyPointRuleForm;
use App\Filament\App\Resources\LoyaltyPointRules\Tables\LoyaltyPointRulesTable;
use App\Models\LoyaltyPointRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class LoyaltyPointRuleResource extends Resource
{
    protected static ?string $model = LoyaltyPointRule::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 32;
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Aturan Poin';
    protected static ?string $modelLabel = 'Aturan Poin';
    protected static ?string $pluralModelLabel = 'Aturan Poin';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('loyalty.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('loyalty.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canCreate();
    }

    public static function canDelete($record): bool
    {
        return static::canCreate() && ! $record->pointLogs()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Auth::user()?->tenant_id)->with(['branch', 'memberTier']);
    }

    public static function form(Schema $schema): Schema
    {
        return LoyaltyPointRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoyaltyPointRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoyaltyPointRules::route('/'),
            'create' => CreateLoyaltyPointRule::route('/create'),
            'edit' => EditLoyaltyPointRule::route('/{record}/edit'),
        ];
    }
}
