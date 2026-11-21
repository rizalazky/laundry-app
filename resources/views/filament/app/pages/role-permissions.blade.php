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

    <style>
        .role-permissions-page {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .role-permissions-intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 2rem;
        }

        .role-permissions-intro__content {
            min-width: 0;
            max-width: 48rem;
        }

        .role-permissions-intro__title {
            display: flex;
            align-items: center;
            gap: .625rem;
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            line-height: 1.5rem;
        }

        .role-permissions-intro__description {
            margin: .5rem 0 0;
            color: var(--gray-500);
            font-size: .875rem;
            line-height: 1.5rem;
        }

        .role-permissions-intro__badges {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-top: .875rem;
        }

        .role-permissions-role {
            flex: 0 0 18rem;
            max-width: 100%;
        }

        .role-permissions-role label {
            display: block;
            margin-bottom: .5rem;
            font-size: .875rem;
            font-weight: 500;
        }

        .role-permissions-role__hint {
            margin: .5rem 0 0;
            color: var(--gray-500);
            font-size: .75rem;
            line-height: 1.125rem;
        }

        .role-permissions-groups {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
            align-items: start;
        }

        .role-permissions-group__heading {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: .625rem;
        }

        .role-permissions-list {
            display: flex;
            flex-direction: column;
        }

        .role-permissions-item {
            display: flex !important;
            align-items: flex-start;
            gap: .75rem;
            width: 100%;
            padding: .875rem 1.5rem;
            cursor: pointer;
            border-top: 1px solid var(--fi-border-color, #e5e7eb);
            transition: background-color .15s ease;
        }

        .role-permissions-item:first-child {
            border-top: 0;
        }

        .role-permissions-item:hover {
            background: var(--gray-50);
        }

        .dark .role-permissions-item:hover {
            background: rgb(255 255 255 / .04);
        }

        .role-permissions-item__checkbox {
            flex: 0 0 auto;
            margin-top: .125rem;
        }

        .role-permissions-item__copy {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-width: 0;
            gap: .125rem;
        }

        .role-permissions-item__name {
            display: block;
            font-size: .875rem;
            font-weight: 500;
            line-height: 1.375rem;
        }

        .role-permissions-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .role-permissions-footer__hint {
            margin: 0;
            color: var(--gray-500);
            font-size: .75rem;
        }

        .role-permissions-empty {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
        }

        .role-permissions-empty p {
            margin: .25rem 0 0;
            color: var(--gray-500);
            font-size: .875rem;
        }

        @media (max-width: 767px) {
            .role-permissions-intro {
                flex-direction: column;
                gap: 1.25rem;
            }

            .role-permissions-role {
                flex-basis: auto;
                width: 100%;
            }

            .role-permissions-groups {
                grid-template-columns: minmax(0, 1fr);
            }

            .role-permissions-footer {
                align-items: stretch;
                flex-direction: column-reverse;
            }
        }
    </style>

    <div class="role-permissions-page">
        <x-filament::section>
            <div class="role-permissions-intro">
                <div class="role-permissions-intro__content">
                    <h2 class="role-permissions-intro__title">
                        <x-filament::icon icon="heroicon-o-shield-check" style="width: 1.25rem; height: 1.25rem;" />
                        Pengaturan hak akses
                    </h2>

                    <p class="role-permissions-intro__description">
                        Tentukan fitur yang dapat diakses oleh karyawan berdasarkan jabatan.
                        Perubahan berlaku untuk seluruh karyawan dengan jabatan tersebut di tenant ini.
                    </p>

                    <div class="role-permissions-intro__badges">
                        <x-filament::badge color="success">Tenant Owner: akses penuh</x-filament::badge>
                        <x-filament::badge color="gray">{{ $selectedCount }} hak akses dipilih</x-filament::badge>
                    </div>
                </div>

                <div class="role-permissions-role">
                    <label for="role">Jabatan yang diatur</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="role" wire:model.live="role">
                            @foreach ($roleLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p class="role-permissions-role__hint">
                        Pilih jabatan untuk melihat dan mengubah hak aksesnya.
                    </p>
                </div>
            </div>
        </x-filament::section>

        @if ($permissionGroups !== [])
            <div class="role-permissions-groups">
                @foreach ($permissionGroups as $group => $permissions)
                    @php
                        $groupSelectedCount = collect($permissions)
                            ->whereIn('id', $selectedPermissions)
                            ->count();
                    @endphp

                    <x-filament::section wire:key="permission-group-{{ $loop->index }}">
                        <x-slot name="heading">
                            <div class="role-permissions-group__heading">
                                <span>{{ $group }}</span>
                                <x-filament::badge color="{{ $groupSelectedCount === count($permissions) ? 'success' : 'gray' }}">
                                    {{ $groupSelectedCount }}/{{ count($permissions) }}
                                </x-filament::badge>
                            </div>
                        </x-slot>

                        <div class="role-permissions-list">
                            @foreach ($permissions as $permission)
                                <label
                                    wire:key="permission-{{ $permission->id }}"
                                    class="role-permissions-item"
                                >
                                    <span class="role-permissions-item__checkbox">
                                        <x-filament::input.checkbox
                                            wire:model="selectedPermissions"
                                            value="{{ $permission->id }}"
                                        />
                                    </span>
                                    <span class="role-permissions-item__copy">
                                        <span class="role-permissions-item__name">
                                            {{ $permission->name }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @else
            <x-filament::section>
                <div class="role-permissions-empty">
                    <x-filament::icon icon="heroicon-o-information-circle" style="width: 1.25rem; height: 1.25rem;" />
                    <div>
                        <strong>Katalog hak akses belum tersedia</strong>
                        <p>Jalankan seeder permission untuk menampilkan daftar hak akses di halaman ini.</p>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <div class="role-permissions-footer">
            <p class="role-permissions-footer__hint">
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
