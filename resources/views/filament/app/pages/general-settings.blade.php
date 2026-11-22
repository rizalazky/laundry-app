<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Regional</x-slot>
            <x-slot name="description">Atur zona waktu dan format tanggal yang digunakan dalam tampilan aplikasi perusahaan.</x-slot>

            <form wire:submit="save" class="space-y-6">
                <div class="space-y-2">
                    <label for="timezone" class="text-sm font-medium text-gray-950 dark:text-white">Zona Waktu</label>
                    <x-filament::input.wrapper>
                        <select id="timezone" wire:model="timezone" class="w-full border-0 bg-transparent text-sm focus:ring-0">
                            @foreach ($timezoneOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-filament::input.wrapper>
                    @error('timezone') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="space-y-2">
                    <label for="dateFormat" class="text-sm font-medium text-gray-950 dark:text-white">Format Tanggal</label>
                    <x-filament::input.wrapper>
                        <select id="dateFormat" wire:model="dateFormat" class="w-full border-0 bg-transparent text-sm focus:ring-0">
                            <option value="d/m/Y">DD/MM/YYYY (22/11/2026)</option>
                            <option value="d-m-Y">DD-MM-YYYY (22-11-2026)</option>
                            <option value="m/d/Y">MM/DD/YYYY (11/22/2026)</option>
                            <option value="Y-m-d">YYYY-MM-DD (2026-11-22)</option>
                        </select>
                    </x-filament::input.wrapper>
                    @error('dateFormat') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="border-t border-gray-200 pt-6 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Transaksi & Operasional</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Konfigurasi default yang digunakan ketika membuat order baru.</p>
                </div>

                <div class="space-y-2">
                    <label for="invoicePrefix" class="text-sm font-medium text-gray-950 dark:text-white">Prefix Nomor Invoice</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="invoicePrefix" type="text" wire:model="invoicePrefix" placeholder="Kosongkan untuk memakai kode cabang" maxlength="12" />
                    </x-filament::input.wrapper>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Jika diisi, prefix ini ditambahkan sebelum kode cabang agar nomor invoice tetap unik antar cabang. Hanya huruf dan angka.</p>
                    @error('invoicePrefix') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="space-y-2">
                    <label for="defaultEstimationHours" class="text-sm font-medium text-gray-950 dark:text-white">Estimasi Default (Jam)</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="defaultEstimationHours" type="number" min="1" max="720" wire:model="defaultEstimationHours" required />
                    </x-filament::input.wrapper>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Dipakai sebagai fallback jika layanan dalam order belum memiliki estimasi waktu pengerjaan.</p>
                    @error('defaultEstimationHours') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="border-t border-gray-200 pt-6 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Pembayaran</h3>
                </div>

                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="allowPartialPayment" class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                    <span>
                        <span class="block text-sm font-medium text-gray-950 dark:text-white">Izinkan pembayaran sebagian (DP / cicilan)</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">Jika dinonaktifkan, order harus dibayar lunas dalam satu pembayaran.</span>
                    </span>
                </label>
                @error('allowPartialPayment') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-m-check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Simpan pengaturan</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
