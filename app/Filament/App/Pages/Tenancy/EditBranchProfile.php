<?php

namespace App\Filament\App\Pages\Tenancy;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Facades\Filament;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\Branch;

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
                ->live()
                ->visible(fn () => Auth::user()?->role === 'tenant_owner'),

            Select::make('workshop_ids')
                ->label('Workshop Pengerjaan')
                ->helperText('Pilih workshop yang menerima pekerjaan dari outlet ini. Mapping hanya tersedia untuk workshop aktif dalam tenant yang sama.')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn (): array => Branch::query()
                    ->where('tenant_id', Auth::user()?->tenant_id)
                    ->where('is_workshop', true)
                    ->where('is_active', true)
                    ->when(Filament::getTenant(), fn ($query, $branch) => $query->where('id', '!=', $branch->getKey()))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->default(fn (): array => Filament::getTenant()?->workshops()
                    ->pluck('branches.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all() ?? [])
                ->visible(fn (Get $get): bool => Auth::user()?->role === 'tenant_owner' && ! (bool) $get('is_workshop')),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $workshopIds = $data['workshop_ids'] ?? [];
        unset($data['workshop_ids']);

        $wasWorkshop = (bool) $record->is_workshop;
        $willBeWorkshop = (bool) ($data['is_workshop'] ?? $wasWorkshop);

        if ($wasWorkshop !== $willBeWorkshop) {
            $hasMappings = DB::table('outlet_workshops')
                ->where('tenant_id', $record->tenant_id)
                ->where(fn ($query) => $query
                    ->where('outlet_id', $record->getKey())
                    ->orWhere('workshop_id', $record->getKey()))
                ->exists();

            if ($hasMappings) {
                throw ValidationException::withMessages([
                    'data.is_workshop' => 'Hapus mapping outlet dan workshop terlebih dahulu sebelum mengubah tipe lokasi.',
                ]);
            }
        }

        return DB::transaction(function () use ($record, $data, $workshopIds, $willBeWorkshop): Model {
            $record->update($data);

            if (Auth::user()?->role === 'tenant_owner') {
                $record->syncWorkshops($willBeWorkshop ? [] : $workshopIds);
            }

            return $record;
        });
    }

}