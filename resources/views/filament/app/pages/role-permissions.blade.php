<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="space-y-2">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                    Atur hak akses berdasarkan jabatan
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Pengaturan berlaku untuk seluruh karyawan dengan role yang dipilih di tenant ini.
                    Tenant Owner tetap memiliki akses penuh dan tidak dapat dibatasi dari halaman ini.
                </p>

                <div class="max-w-md pt-2">
                    <label for="role" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">
                        Jabatan
                    </label>
                    <select
                        id="role"
                        wire:model.live="role"
                        class="fi-input w-full rounded-lg border-gray-300 bg-white shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                    >
                        <option value="branch_manager">Branch Manager</option>
                        <option value="cashier">Kasir</option>
                        <option value="operator">Operator Produksi</option>
                    </select>
                </div>
            </div>
        </x-filament::section>

        @forelse ($this->getPermissionGroups() as $group => $permissions)
            <x-filament::section :heading="$group">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($permissions as $permission)
                        <label
                            wire:key="permission-{{ $permission->id }}"
                            class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10"
                        >
                            <input
                                type="checkbox"
                                value="{{ $permission->id }}"
                                wire:model="selectedPermissions"
                                class="mt-1 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-white/20 dark:bg-white/5"
                            >
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $permission->name }}
                                </span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $permission->slug }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Katalog permission belum tersedia. Jalankan seeder permission terlebih dahulu.
                </p>
            </x-filament::section>
        @endforelse

        <div class="flex justify-end">
            <x-filament::button wire:click="save" icon="heroicon-m-check">
                Simpan Matriks Hak Akses
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
