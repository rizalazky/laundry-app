<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CompanyProfile extends Page
{
    protected string $view = 'filament.app.pages.company-profile';

    protected static ?string $slug = 'profil-perusahaan';

    protected static bool $shouldRegisterNavigation = false;

    public string $companyName = '';

    public string $ownerEmail = '';

    public string $phone = '';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && $user->role === 'tenant_owner'
            && $user->tenant_id !== null
            && $user->tenant !== null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Auth::user()->tenant;

        $this->companyName = $tenant->name;
        $this->ownerEmail = $tenant->owner_email;
        $this->phone = $tenant->phone ?? '';
    }

    public function save(): void
    {
        $user = Auth::user();

        abort_unless(
            $user instanceof User
                && $user->is_active
                && $user->role === 'tenant_owner'
                && $user->tenant_id !== null,
            403,
        );

        $tenant = $user->tenant()->firstOrFail();

        $validated = $this->validate([
            'companyName' => ['required', 'string', 'max:255'],
            'ownerEmail' => [
                'required',
                'email',
                'max:255',
                Rule::unique('tenants', 'owner_email')->ignore($tenant->getKey()),
            ],
            'phone' => ['nullable', 'string', 'max:25'],
        ]);

        $tenant->update([
            'name' => $validated['companyName'],
            'owner_email' => $validated['ownerEmail'],
            'phone' => $validated['phone'] !== '' ? $validated['phone'] : null,
        ]);

        Notification::make()
            ->title('Profil perusahaan berhasil diperbarui')
            ->success()
            ->send();
    }
}
