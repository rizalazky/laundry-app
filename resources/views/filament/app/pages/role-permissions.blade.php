<x-filament-panels::page>
    @php
        $roleLabels = [
            'branch_manager' => 'Branch Manager',
            'cashier' => 'Kasir',
            'operator' => 'Operator Produksi',
        ];

        $selectedCount = count($selectedPermissions);
        $permissionGroups = $this->getPermissionGroups();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl space-y-2">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-shield-check" class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                            Pengaturan hak akses
                        </h2>
                    </div>
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-400">
                        Tentukan fitur yang dapat diakses oleh karyawan berdasarkan jabatan.
                        Perubahan berlaku untuk seluruh karyawan dengan jabatan tersebut di tenant ini.
                    </p>
                    <div class="flex flex-wrap items-center gap-2 pt-1 text-xs">
                        <x-filament::badge color="success">Tenant Owner: akses penuh</x-filament::badge>
                        <x-filament::badge color="gray">{{ $selectedCount }} hak akses dipilih</x-filament::badge>
                    </div>
                </div>

                <div class="w-full lg:max-w-xs">
                    <label for="role" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Jabatan yang diatur
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="role" wire:model.live="role">
                            @foreach ($roleLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Pilih jabatan untuk melihat dan mengubah hak aksesnya.
                    </p>
                </div>
            </div>
        </x-filament::section>

        @if ($permissionGroups !== [])
            <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                @foreach ($permissionGroups as $group => $permissions)
                    @php
                        $groupSelectedCount = collect($permissions)
                            ->whereIn('id', $selectedPermissions)
                            ->count();
                    @endphp

                    <x-filament::section wire:key="permission-group-{{ $loop->index }}">
                        <x-slot name="heading">
                            <div class="flex flex-wrap items-center gap-2">
                                <span>{{ $group }}</span>
                                <x-filament::badge color="{{ $groupSelectedCount === count($permissions) ? 'success' : 'gray' }}">
                                    {{ $groupSelectedCount }}/{{ count($permissions) }}
                                </x-filament::badge>
                            </div>
                        </x-slot>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); gap: .75rem;">
                            @foreach ($permissions as $permission)
                                <label
                                    wire:key="permission-{{ $permission->id }}"
                                    style="display: flex; align-items: center; gap: .75rem; width: 100%; min-width: 0; padding: .875rem 1rem; border: 1px solid rgb(229 231 235); border-radius: .625rem; cursor: pointer;"
                                >
                                    <x-filament::input.checkbox
                                        wire:model="selectedPermissions"
                                        value="{{ $permission->id }}"
                                    />
                                    <span style="min-width: 0; font-size: .875rem; line-height: 1.35rem; font-weight: 500; color: inherit;">
                                        {{ $permission->name }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @else
            <x-filament::section>
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 text-gray-400" />
                    <div>
                        <h3 class="text-sm font-medium text-gray-950 dark:text-white">
                            Katalog hak akses belum tersedia
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Jalankan seeder permission untuk menampilkan daftar hak akses di halaman ini.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Pastikan perubahan sudah sesuai sebelum disimpan.
            </p>
            <x-filament::button
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                icon="heroicon-m-check"
            >
                <span wire:loading.remove wire:target="save">Simpan perubahan</span>
                <span wire:loading wire:target="save">Menyimpan...</span>
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
