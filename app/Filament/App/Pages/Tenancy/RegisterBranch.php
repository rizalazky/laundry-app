<?php

namespace App\Filament\App\Pages\Tenancy;

use App\Models\Branch;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RegisterBranch extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Tambah Cabang Baru';
    }

    public static function canView(): bool
    {
        $user = Auth::user();
        $tenant = $user?->tenant;

        if ($user === null || $tenant === null || ! Gate::forUser($user)->allows('create', Branch::class)) {
            return false;
        }

        return $tenant->branches()->count() < $tenant->max_branches;
    }

    public function form(Schema $schema): Schema
    {
        $tenantId = Auth::user()->tenant_id;

        return $schema->components([
            TextInput::make('name')
                ->label('Nama Cabang')
                ->placeholder('Contoh: Cabang Dago')
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label('Kode Cabang (URL & Invoice)')
                ->placeholder('Contoh: DGO')
                ->alphaNum()
                ->maxLength(15)
                ->required()
                ->rules([
                    Rule::unique('branches', 'code')->where('tenant_id', $tenantId),
                ]),

            TextInput::make('phone')
                ->label('No. WhatsApp Cabang')
                ->tel()
                ->maxLength(25),

            Textarea::make('address')
                ->label('Alamat Lengkap')
                ->rows(2),

            Toggle::make('is_workshop')
                ->label('Jadikan Central Workshop (Pusat Produksi)')
                ->default(false),
        ]);
    }

    protected function handleRegistration(array $data): Branch
    {
        Gate::authorize('create', Branch::class);

        $user = Auth::user();

        return DB::transaction(function () use ($user, $data): Branch {
            $tenant = $user->tenant()->lockForUpdate()->firstOrFail();

            if ($tenant->branches()->count() >= $tenant->max_branches) {
                Notification::make()
                    ->title('Kuota Cabang Penuh')
                    ->body('Paket Anda maksimal ' . $tenant->max_branches . ' cabang.')
                    ->danger()
                    ->send();

                $this->halt();
            }

            $branch = $tenant->branches()->create([
                ...$data,
                'code' => strtoupper($data['code']),
                'is_active' => true,
            ]);

            $branch->users()->attach($user->id);

            return $branch;
        });
    }
}
