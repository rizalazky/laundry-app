<?php

namespace App\\Filament\\Auth;

use App\\Models\\User;
use Filament\\Auth\\Pages\\EditProfile as BaseEditProfile;
use Filament\\Forms\\Components\\TextInput;
use Filament\\Schemas\\Components\\Group;
use Filament\\Schemas\\Components\\Section;
use Filament\\Schemas\\Schema;
use Illuminate\\Validation\\Rule;

class EditUserProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profil Akun')
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        TextInput::make('phone_whatsapp')
                            ->label('Nomor WhatsApp Pribadi')
                            ->tel()
                            ->maxLength(25),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
                Section::make('Profil Perusahaan')
                    ->description('Informasi ini berlaku untuk seluruh cabang dalam perusahaan.')
                    ->visible(fn (): bool => $this->isTenantOwner())
                    ->schema([
                        Group::make()
                            ->statePath('tenant')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nama Perusahaan')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('owner_email')
                                    ->label('Email Owner / Email Bisnis')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->rules([
                                        fn () => Rule::unique('tenants', 'owner_email')
                                            ->ignore($this->getUser()->getAttribute('tenant_id')),
                                    ]),
                                TextInput::make('phone')
                                    ->label('Nomor WhatsApp Bisnis')
                                    ->tel()
                                    ->maxLength(25),
                            ]),
                    ]),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getUser();

        if ($this->isTenantOwner() && $user instanceof User && $user->tenant) {
            $data['tenant'] = [
                'name' => $user->tenant->name,
                'owner_email' => $user->tenant->owner_email,
                'phone' => $user->tenant->phone,
            ];
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $tenantData = $data['tenant'] ?? null;
        unset($data['tenant']);

        if ($this->isTenantOwner() && is_array($tenantData)) {
            $this->getUser()
                ->tenant()
                ->firstOrFail()
                ->update([
                    'name' => $tenantData['name'],
                    'owner_email' => $tenantData['owner_email'],
                    'phone' => filled($tenantData['phone'] ?? null) ? $tenantData['phone'] : null,
                ]);
        }

        return $data;
    }

    private function isTenantOwner(): bool
    {
        return $this->getUser() instanceof User
            && $this->getUser()->is_active
            && $this->getUser()->role === 'tenant_owner'
            && $this->getUser()->getAttribute('tenant_id') !== null
            && $this->getUser()->tenant !== null;
    }
}
