<x-filament-panels::page>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Transaksi baru · {{ $this->currentBranchName() }}</p>
                <h2 class="text-xl font-bold text-gray-950 dark:text-white">Kasir Laundry</h2>
            </div>
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-o-shopping-bag" class="h-5 w-5" />
                {{ count($this->cartLines()) }} item di keranjang
            </div>
        </div>

        @if ($order = $this->lastCreatedOrder())
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-500/30 dark:bg-success-400/10">
                <div>
                    <p class="font-semibold text-success-800 dark:text-success-300">Order {{ $order->invoice_no }} berhasil dibuat</p>
                    <p class="text-sm text-success-700 dark:text-success-400">
                        Total Rp {{ number_format((float) $order->total, 0, ',', '.') }} · {{ $order->payment_status->label() }}
                    </p>
                </div>
                <a href="{{ $this->receiptUrl($order) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">
                    <x-filament::icon icon="heroicon-o-printer" class="h-4 w-4" />
                    Cetak Nota
                </a>
            </div>
        @endif

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_23rem]">
            <section class="space-y-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <label for="pos-customer-search" class="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">Pelanggan</label>
                    @if ($customer = $this->selectedCustomer())
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-primary-50 px-3 py-3 dark:bg-primary-400/10">
                            <div>
                                <p class="font-semibold text-gray-950 dark:text-white">{{ $customer->name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $customer->phone ?: 'Tanpa nomor telepon' }}</p>
                            </div>
                            <button type="button" wire:click="clearCustomer" class="rounded-lg px-3 py-2 text-sm font-medium text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-400/10">Ganti</button>
                        </div>
                    @else
                        <input
                            id="pos-customer-search"
                            type="search"
                            wire:model.live.debounce.300ms="customerSearch"
                            placeholder="Cari nama atau nomor HP pelanggan..."
                            autocomplete="off"
                            class="min-h-12 w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        >
                        @if ($this->customerResults()->isNotEmpty())
                            <div class="mt-2 divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                                @foreach ($this->customerResults() as $result)
                                    <button type="button" wire:key="pos-customer-{{ $result->id }}" wire:click="selectCustomer({{ $result->id }})" class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $result->name }}</span>
                                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $result->phone }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif (mb_strlen(trim($customerSearch)) >= 2)
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Pelanggan tidak ditemukan.</p>
                        @endif
                    @endif
                    @error('customerId') <p class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Pilih layanan</h3>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Ketuk kartu untuk menambahkan</span>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-4">
                    @forelse ($this->serviceCatalog() as $service)
                        <button
                            type="button"
                            wire:key="pos-service-{{ $service->id }}"
                            wire:click="addToCart({{ $service->id }})"
                            class="flex min-h-32 flex-col justify-between rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:bg-primary-50 active:scale-[.98] dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-primary-400/10"
                        >
                            <span class="text-sm font-semibold leading-snug text-gray-950 dark:text-white">{{ $service->name }}</span>
                            <span>
                                <span class="block text-lg font-bold tabular-nums text-primary-700 dark:text-primary-400">Rp {{ number_format((float) $service->priceForBranch(\Filament\Facades\Filament::getTenant()), 0, ',', '.') }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $service->unit->label() }}</span>
                            </span>
                        </button>
                    @empty
                        <div class="col-span-full rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            Belum ada layanan aktif untuk tenant ini.
                        </div>
                    @endforelse
                </div>
            </section>

            <aside class="h-fit space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm xl:sticky xl:top-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Keranjang</h3>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ count($this->cartLines()) }}</span>
                </div>

                <div class="max-h-[26rem] space-y-3 overflow-y-auto">
                    @forelse ($this->cartLines() as $line)
                        <div wire:key="pos-cart-{{ $line['id'] }}" class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-white">{{ $line['name'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Rp {{ number_format((float) $line['price'], 0, ',', '.') }} / {{ $line['unit'] }}</p>
                                </div>
                                <button type="button" wire:click="removeFromCart({{ $line['id'] }})" aria-label="Hapus {{ $line['name'] }}" class="rounded-md p-1 text-gray-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                </button>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="updateQuantity({{ $line['id'] }}, {{ max(0, (float) $line['quantity'] - ($line['unit'] === 'pcs' ? 1 : 0.5)) }})" class="h-9 w-9 rounded-lg border border-gray-300 text-lg dark:border-gray-600">−</button>
                                    <input type="number" min="{{ $line['unit'] === 'pcs' ? 1 : 0.01 }}" step="{{ $line['unit'] === 'pcs' ? 1 : 0.01 }}" value="{{ $line['quantity'] }}" wire:change="updateQuantity({{ $line['id'] }}, $event.target.value)" class="h-9 w-20 rounded-lg border-gray-300 px-2 text-center text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                    <button type="button" wire:click="updateQuantity({{ $line['id'] }}, {{ (float) $line['quantity'] + ($line['unit'] === 'pcs' ? 1 : 0.5) }})" class="h-9 w-9 rounded-lg border border-gray-300 text-lg dark:border-gray-600">+</button>
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
                        <span class="font-medium tabular-nums">Rp {{ number_format((float) $this->subtotal(), 0, ',', '.') }}</span>
                    </div>
                    <div>
                        <label for="pos-discount" class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Diskon (Rp)</label>
                        <input id="pos-discount" type="number" min="0" step="1000" wire:model.live.debounce.300ms="discount" class="h-11 w-full rounded-lg border-gray-300 text-right tabular-nums dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        @error('discount') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center justify-between border-t border-dashed border-gray-200 pt-3 dark:border-gray-700">
                        <span class="font-semibold text-gray-900 dark:text-white">Total</span>
                        <span class="text-xl font-bold tabular-nums text-primary-700 dark:text-primary-400">Rp {{ number_format((float) $this->grandTotal(), 0, ',', '.') }}</span>
                    </div>
                    <x-filament::button type="button" color="primary" size="xl" class="w-full" wire:click="openPaymentModal" :disabled="count($this->cartLines()) === 0">
                        Lanjut Pembayaran
                    </x-filament::button>
                </div>
            </aside>
        </div>

        @if ($paymentModalOpen)
            <div class="fixed inset-0 z-[100] flex items-center justify-center overflow-y-auto bg-gray-950/60 p-4" wire:key="pos-payment-modal">
                <div class="w-full max-w-xl rounded-2xl bg-white p-5 shadow-2xl dark:bg-gray-900" @click.stop>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-bold text-gray-950 dark:text-white">Pembayaran</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Bisa menggunakan lebih dari satu metode pembayaran.</p>
                        </div>
                        <button type="button" wire:click="closePaymentModal" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Tutup">
                            <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="my-4 rounded-xl bg-gray-50 p-4 dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total tagihan</p>
                        <p class="text-3xl font-bold tabular-nums text-gray-950 dark:text-white">Rp {{ number_format((float) $this->grandTotal(), 0, ',', '.') }}</p>
                    </div>

                    <div class="space-y-3">
                        @foreach ($paymentLines as $index => $line)
                            <div wire:key="pos-payment-line-{{ $index }}" class="grid gap-2 rounded-xl border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_auto] dark:border-gray-700">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Metode pembayaran</label>
                                    <select wire:model="paymentLines.{{ $index }}.method" class="h-11 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                        @foreach ($this->paymentMethods() as $method => $label)
                                            <option value="{{ $method }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Nominal</label>
                                    <input type="number" min="0.01" step="0.01" wire:model="paymentLines.{{ $index }}.amount" class="h-11 w-full rounded-lg border-gray-300 text-right text-sm tabular-nums dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                </div>
                                <button type="button" wire:click="removePaymentLine({{ $index }})" class="mt-5 rounded-lg p-2 text-gray-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10" aria-label="Hapus metode pembayaran">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-5 w-5" />
                                </button>
                                <div class="sm:col-span-3">
                                    <input type="text" maxlength="255" placeholder="No. referensi (opsional)" wire:model="paymentLines.{{ $index }}.reference" class="h-10 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                </div>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addPaymentLine" class="min-h-11 w-full rounded-lg border border-dashed border-primary-400 text-sm font-semibold text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-primary-400/10">
                            + Tambah metode pembayaran
                        </button>
                    </div>

                    <div class="mt-4 space-y-2 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-300">
                            <span>Total dibayar</span>
                            <span class="font-semibold tabular-nums">Rp {{ number_format((float) $this->paymentTotal(), 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-300">
                            <span>Sisa tagihan</span>
                            <span class="font-semibold tabular-nums">Rp {{ number_format(max(0, (float) $this->paymentRemaining()), 0, ',', '.') }}</span>
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
            </div>
        @endif
    </div>
</x-filament-panels::page>
