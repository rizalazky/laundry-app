<?php

namespace App\Filament\App\Resources\AffiliateReferrals;

use App\Filament\App\Resources\AffiliateReferrals\Pages\ListAffiliateReferrals;
use App\Models\AffiliateReferral;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AffiliateReferralResource extends Resource
{
    protected static ?string $model = AffiliateReferral::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 40;
    protected static ?string $navigationLabel = 'Afiliasi';
    protected static ?string $modelLabel = 'Referral Afiliasi';
    protected static ?string $pluralModelLabel = 'Referral Afiliasi';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && $user->role === 'tenant_owner'
            && $user->hasPermissionTo('affiliates.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('referrer_tenant_id', Auth::user()?->tenant_id)
            ->with(['referred', 'payouts']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return AffiliateReferralsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListAffiliateReferrals::route('/')];
    }
}
