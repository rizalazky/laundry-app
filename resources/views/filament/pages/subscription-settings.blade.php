<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Registrasi & Trial</x-slot>
            <x-slot name="description">Konfigurasi ini berlaku di tingkat platform. Perubahan durasi dan paket default digunakan saat trial tenant baru dibuat; tidak mengubah snapshot subscription tenant yang sudah berjalan.</x-slot>

            <form wire:submit="save" class="space-y-6">
                <div class="space-y-2">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="registrationEnabled" class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                        <span>
                            <span class="block text-sm font-medium text-gray-950 dark:text-white">Buka pendaftaran tenant baru</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">Jika dinonaktifkan, pengunjung tidak dapat membuat akun bisnis baru.</span>
                        </span>
                    </label>
                    @error('registrationEnabled') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="space-y-2">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="trialEnabled" class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                        <span>
                            <span class="block text-sm font-medium text-gray-950 dark:text-white">Aktifkan masa trial</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">Jika dinonaktifkan, tenant baru tidak mendapatkan masa trial.</span>
                        </span>
                    </label>
                    @error('trialEnabled') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <label for="trialDays" class="text-sm font-medium text-gray-950 dark:text-white">Durasi Trial (Hari)</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="trialDays" type="number" min="0" max="3650" wire:model="trialDays" required />
                        </x-filament::input.wrapper>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Isi 0 untuk tidak memberikan durasi trial.</p>
                        @error('trialDays') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="gracePeriodDays" class="text-sm font-medium text-gray-950 dark:text-white">Grace Period (Hari)</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="gracePeriodDays" type="number" min="0" max="3650" wire:model="gracePeriodDays" required />
                        </x-filament::input.wrapper>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Masa toleransi setelah periode berbayar berakhir.</p>
                        @error('gracePeriodDays') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="space-y-2">
                    <label for="defaultPlanCode" class="text-sm font-medium text-gray-950 dark:text-white">Paket Default Tenant Baru</label>
                    <x-filament::input.wrapper>
                        <select id="defaultPlanCode" wire:model="defaultPlanCode" class="w-full border-0 bg-transparent text-sm focus:ring-0">
                            @foreach ($planOptions as $code => $name)
                                <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                            @endforeach
                        </select>
                    </x-filament::input.wrapper>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Hanya paket yang masih aktif yang dapat dipilih sebagai paket default.</p>
                    @error('defaultPlanCode') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end border-t border-gray-200 pt-6 dark:border-gray-700">
                    <x-filament::button type="submit" icon="heroicon-m-check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Simpan pengaturan</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
