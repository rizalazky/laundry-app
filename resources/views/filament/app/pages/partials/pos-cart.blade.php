<div class="flex h-full min-h-0 flex-col gap-4 w-full">

                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Keranjang</h3>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ count($livewire->cartLines()) }}</span>
                </div>

                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1 scrollbar-none">
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
                                    <x-filament::button type="button" size="sm" color="gray" outlined icon="heroicon-m-minus" wire:click="updateQuantity({{ $line['id'] }}, {{ max(0, (float) $line['quantity'] - ($line['unit'] === 'pcs' ? 1 : 0.5)) }})" class="h-9! w-9! min-w-9! p-0!" aria-label="Kurangi jumlah" />
                                    <x-filament::input type="number" min="{{ $line['unit'] === 'pcs' ? 1 : 0.01 }}" step="{{ $line['unit'] === 'pcs' ? 1 : 0.01 }}" value="{{ $line['quantity'] }}" wire:change="updateQuantity({{ $line['id'] }}, $event.target.value)" class="w-20 text-center text-sm" />
                                    <x-filament::button type="button" size="sm" color="gray" outlined icon="heroicon-m-plus" wire:click="updateQuantity({{ $line['id'] }}, {{ (float) $line['quantity'] + ($line['unit'] === 'pcs' ? 1 : 0.5) }})" class="h-9! w-9! min-w-9! p-0!" aria-label="Tambah jumlah" />
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

                <div class="shrink-0 space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
                        <span>Subtotal</span>
                        <span class="font-medium tabular-nums">Rp {{ number_format((float) $livewire->subtotal(), 0, ',', '.') }}</span>
                    </div>
                    @if ($membership = $livewire->membershipDiscountDetails())
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-gray-600 dark:text-gray-300">Diskon Membership · {{ $membership['tier_name'] }} ({{ rtrim(rtrim(number_format((float) $membership['discount_percent'], 2, ',', '.'), '0'), ',') }}%)</span>
                            <span class="font-medium tabular-nums text-success-700 dark:text-success-400">− Rp {{ number_format((float) $livewire->membershipDiscountAmount(), 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="space-y-1">
                        <label for="pos-voucher" class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Kode Voucher</label>
                        <div class="flex gap-2">
                            <x-filament::input id="pos-voucher" type="text" maxlength="100" placeholder="Masukkan kode voucher" wire:model.live.debounce.500ms="voucherCode" class="min-w-0 flex-1 uppercase" />
                        </div>
                        @if ($voucher = $livewire->voucherPreview())
                            <div class="flex items-center justify-between gap-3 text-sm text-success-700 dark:text-success-400">
                                <span>Voucher diterapkan · {{ $voucher['promo_name'] }} ({{ $voucher['voucher_code'] }})</span>
                                <span class="font-medium tabular-nums">− Rp {{ number_format((float) $livewire->promoDiscountAmount(), 0, ',', '.') }}</span>
                            </div>
                        @elseif ($error = $livewire->voucherPreviewError())
                            <p class="text-xs text-danger-600">{{ $error }}</p>
                        @endif
                    </div>
                    <div>
                        <label for="pos-discount" class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Diskon manual (Rp)</label>
                        <x-filament::input id="pos-discount" type="number" min="0" step="1000" wire:model.live.debounce.300ms="discount" class="text-right tabular-nums" />
                        @error('discount') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
                        <span>Total diskon</span>
                        <span class="font-semibold tabular-nums text-success-700 dark:text-success-400">− Rp {{ number_format((float) $livewire->totalDiscountAmount(), 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-dashed border-gray-200 pt-3 dark:border-gray-700">
                        <span class="font-semibold text-gray-900 dark:text-white">Total</span>
                        <span class="text-xl font-bold tabular-nums text-primary-700 dark:text-primary-400">Rp {{ number_format((float) $livewire->grandTotal(), 0, ',', '.') }}</span>
                    </div>
                    <x-filament::button type="button" color="primary" size="xl" class="w-full" x-on:click="close();setTimeout(() => $wire.openPaymentModal(), 200);" :disabled="count($livewire->cartLines()) === 0">
                        Lanjut Pembayaran
                    </x-filament::button>
                </div>

</div>
