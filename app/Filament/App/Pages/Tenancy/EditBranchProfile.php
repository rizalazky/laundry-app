<?php

namespace App\Filament\App\Pages\Tenancy;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditBranchProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Pengaturan Cabang';
    }

    public static function canView(Model $tenant): bool
    {
        return in_array(Auth::user()?->role, ['tenant_owner', 'branch_manager']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama Cabang')
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label('Kode Cabang')
                ->disabled()
                ->dehydrated(false),

            TextInput::make('phone')
                ->label('No. WhatsApp Cabang')
                ->tel()
                ->maxLength(25),

            Textarea::make('address')
                ->label('Alamat Lengkap')
                ->rows(2),

            Textarea::make('receipt_footer')
                ->label('Catatan Bawah Struk')
                ->placeholder('Contoh: Komplain maksimal 1x24 jam dengan menyertakan nota.')
                ->rows(2),

            Toggle::make('is_workshop')
                ->label('Central Workshop (Pusat Produksi)')
                ->visible(fn () => Auth::user()?->role === 'tenant_owner'),
        ]);
    }
}