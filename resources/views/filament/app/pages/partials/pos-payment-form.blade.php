<div class="flex h-full min-h-0 flex-col">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-bold text-gray-950 dark:text-white">Pembayaran</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">Bisa menggunakan lebih dari satu metode pembayaran.</p>
        </div>
        <x-filament::button type="button" wire:click="closePaymentModal" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Kembali ke keranjang">
            <x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5" />
        </x-filament::button>
    </div>

    <div class="my-4 rounded-xl bg-gray-50 p-4 dark:bg-gray-800">
        <p class="text-sm text-gray-500 dark:text-gray-400">Total tagihan</p>
        <p class="text-3xl font-bold tabular-nums text-gray-950 dark:text-white">Rp {{ number_format((float) $livewire->grandTotal(), 0, ',', '.') }}</p>
    </div>

    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto">
        @foreach ($livewire->paymentLines as $index => $line)
            <div wire:key="pos-payment-line-{{ $index }}" class="grid gap-2 rounded-xl border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_auto] dark:border-gray-700">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Metode pembayaran</label>
                    <x-filament::input.select wire:model="paymentLines.{{ $index }}.method" class="text-sm">
                        @foreach ($livewire->paymentMethods() as $method => $label)
                            <option value="{{ $method }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Nominal</label>
                    <x-filament::input type="number" min="0.01" step="0.01" wire:model.live.debounce.300ms="paymentLines.{{ $index }}.amount" class="text-right text-sm tabular-nums" />
                </div>
                <x-filament::button type="button" wire:click="removePaymentLine({{ $index }})" class="mt-5 rounded-lg p-2 text-gray-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10" aria-label="Hapus metode pembayaran">
                    <x-filament::icon icon="heroicon-o-trash" class="h-5 w-5" />
                </x-filament::button>
                <div class="sm:col-span-3">
                    <x-filament::input type="text" maxlength="255" placeholder="No. referensi (opsional)" wire:model="paymentLines.{{ $index }}.reference" class="text-sm" />
                </div>
            </div>
        @endforeach
        @if ((float) $livewire->grandTotal() > 0)
            <x-filament::button type="button" wire:click="addPaymentLine" class="min-h-11 w-full rounded-lg border border-dashed border-primary-400 text-sm font-semibold text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-primary-400/10">
                + Tambah metode pembayaran
            </x-filament::button>
        @else
            <p class="rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-400/10 dark:text-success-400">
                Total tagihan Rp 0. Order akan langsung tercatat lunas tanpa pembayaran.
            </p>
        @endif
    </div>

    <div class="mt-4 space-y-2 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-300">
            <span>Total dibayar</span>
            <span class="font-semibold tabular-nums">Rp {{ number_format((float) $livewire->paymentTotal(), 0, ',', '.') }}</span>
        </div>
        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-300">
            <span>Sisa tagihan</span>
            <span class="font-semibold tabular-nums">Rp {{ number_format(max(0, (float) $livewire->paymentRemaining()), 0, ',', '.') }}</span>
        </div>
        @error('paymentLines') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
        @error('paymentLines.*.amount') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
    </div>

    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <x-filament::button color="gray" outlined wire:click="closePaymentModal">Kembali</x-filament::button>
        <x-filament::button color="success" icon="heroicon-o-check" wire:click="createOrder" wire:loading.attr="disabled" wire:target="createOrder">
            Simpan Order
        </x-filament::button>
    </div>
</div>
