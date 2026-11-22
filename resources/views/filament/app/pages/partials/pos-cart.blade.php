<div class="flex h-full flex-col gap-4">

                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Keranjang</h3>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ count($livewire->cartLines()) }}</span>
                </div>

                <div class="max-h-[26rem] space-y-3 overflow-y-auto">
                    @forelse ($livewire->cartLines() as $line)
                        <div wire:key="pos-cart-{{ $line['id'] }}" class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-white">{{ $line['name'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Rp {{ number_format((float) $line['price'], 0, ',', '.') }} / {{ $line['unit'] }}</p>
                                </div>
                                <x-filament::button type="button" wire:click="removeFromCart({{ $line['id'] }})" aria-label="Hapus {{ $line['name'] }}" class="rounded-md p-1 text-gray-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                </x-filament::button>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <x-filament::button type="button" size="sm" color="gray" outlined icon="heroicon-m-minus" wire:click="updateQuantity({{ $line['id'] }}, {{ max(0, (float) $line['quantity'] - ($line['unit'] === 'pcs' ? 1 : 0.5)) }})" class="!h-9 !w-9 !min-w-9 !p-0" aria-label="Kurangi jumlah" />
                                    <x-filament::input type="number" min="{{ $line['unit'] === 'pcs' ? 1 : 0.01 }}" step="{{ $line['unit'] === 'pcs' ? 1 : 0.01 }}" value="{{ $line['quantity'] }}" wire:change="updateQuantity({{ $line['id'] }}, $event.target.value)" class="w-20 text-center text-sm" />
                                    <x-filament::button type="button" size="sm" color="gray" outlined icon="heroicon-m-plus" wire:click="updateQuantity({{ $line['id'] }}, {{ (float) $line['quantity'] + ($line['unit'] === 'pcs' ? 1 : 0.5) }})" class="!h-9 !w-9 !min-w-9 !p-0" aria-label="Tambah jumlah" />
                                </div>
                                <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">Rp {{ number_format((float) $line['subtotal'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg bg-gray-50 px-4 py-10 text-center dark:bg-gray-800">
                            <x-filament::icon icon="heroicon-o-shopping-cart" class="mx-auto h-8 w-8 text-gray-400" />
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Keranjang masih kosong</p>
                        </div>
                    @endforelse
                </div>

                <div class="space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
                        <span>Subtotal</span>
                        <span class="font-medium tabular-nums">Rp {{ number_format((float) $livewire->subtotal(), 0, ',', '.') }}</span>
                    </div>
                    <div>
                        <label for="pos-discount" class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Diskon (Rp)</label>
                        <x-filament::input id="pos-discount" type="number" min="0" step="1000" wire:model.live.debounce.300ms="discount" class="text-right tabular-nums" />
                        @error('discount') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center justify-between border-t border-dashed border-gray-200 pt-3 dark:border-gray-700">
                        <span class="font-semibold text-gray-900 dark:text-white">Total</span>
                        <span class="text-xl font-bold tabular-nums text-primary-700 dark:text-primary-400">Rp {{ number_format((float) $livewire->grandTotal(), 0, ',', '.') }}</span>
                    </div>
                    <x-filament::button type="button" color="primary" size="xl" class="w-full" wire:click="openPaymentModal" :disabled="count($livewire->cartLines()) === 0">
                        Lanjut Pembayaran
                    </x-filament::button>
                </div>

</div>
