<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Informasi Perusahaan</x-slot>
            <x-slot name="description">
                Kelola identitas dan kontak utama bisnis laundry. Perubahan berlaku untuk seluruh cabang dalam perusahaan ini.
            </x-slot>

            <form wire:submit="save" class="space-y-6">
                <div class="space-y-2">
                    <label for="companyName" class="text-sm font-medium text-gray-950 dark:text-white">Nama Perusahaan</label>
                    <x-filament::input.wrapper :valid="! $errors->has('companyName')" :invalid="$errors->has('companyName')">
                        <x-filament::input id="companyName" type="text" wire:model="companyName" required autocomplete="organization" />
                    </x-filament::input.wrapper>
                    @error('companyName')
                        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="ownerEmail" class="text-sm font-medium text-gray-950 dark:text-white">Email Owner / Email Bisnis</label>
                    <x-filament::input.wrapper :valid="! $errors->has('ownerEmail')" :invalid="$errors->has('ownerEmail')">
                        <x-filament::input id="ownerEmail" type="email" wire:model="ownerEmail" required autocomplete="email" />
                    </x-filament::input.wrapper>
                    @error('ownerEmail')
                        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                    <p class="text-sm text-gray-500 dark:text-gray-400">Email ini merupakan kontak pemilik perusahaan yang tersimpan pada data tenant.</p>
                </div>

                <div class="space-y-2">
                    <label for="phone" class="text-sm font-medium text-gray-950 dark:text-white">Nomor WhatsApp Bisnis</label>
                    <x-filament::input.wrapper :valid="! $errors->has('phone')" :invalid="$errors->has('phone')">
                        <x-filament::input id="phone" type="tel" wire:model="phone" autocomplete="tel" />
                    </x-filament::input.wrapper>
                    @error('phone')
                        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-m-check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Simpan perubahan</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section compact>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 text-gray-500" />
                <div class="space-y-1">
                    <h3 class="text-sm font-medium text-gray-950 dark:text-white">Profil akun pengguna</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nama pribadi, email login, nomor WhatsApp pribadi, dan password dikelola melalui menu Profil Saya di dropdown akun.
                    </p>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
