<x-filament-panels::page>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500 dark:text-gray-400">Transaksi baru · {{ $this->currentBranchName() }}</p>
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

        @if ($this->selectedCustomer())
            <div class="grid grid-cols-1 gap-5 xl:h-[calc(100dvh-15rem)] xl:min-h-136 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <section class="flex min-h-0 min-w-0 flex-col gap-4">
                    <div class="shrink-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        {{ $this->form }}
                    </div>

                    <div class="flex shrink-0 items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Pilih layanan</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Ketuk kartu untuk menambahkan</span>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto pr-1">
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-4">
                        @forelse ($this->serviceCatalog() as $service)
                            <x-filament::button
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
                            </x-filament::button>
                        @empty
                            <div class="col-span-full rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                Belum ada layanan aktif untuk tenant ini.
                            </div>
                        @endforelse
                    </div>
                    </div>
                </section>

                <aside class="hidden h-full min-h-0 min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm xl:flex dark:border-gray-700 dark:bg-gray-900">
                    @include('filament.app.pages.partials.pos-cart', ['livewire' => $this])
                </aside>
            </div>

        @else
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                {{ $this->form }}
            </div>
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center dark:border-gray-700 dark:bg-gray-900">
                <x-filament::icon icon="heroicon-o-user-circle" class="mx-auto h-10 w-10 text-gray-400" />
                <h2 class="mt-3 text-base font-semibold text-gray-950 dark:text-white">Pilih pelanggan terlebih dahulu</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Katalog layanan dan keranjang akan muncul setelah pelanggan dipilih.</p>
            </div>
        @endif

        @if ($paymentModalOpen && ! $paymentInCart)
            <div class="fixed inset-0 z-100 flex items-center justify-center overflow-y-auto bg-gray-950/60 p-4" wire:key="pos-payment-modal">
                <div class="w-full max-w-xl rounded-2xl bg-white p-5 shadow-2xl dark:bg-gray-900" @click.stop>
                    @include('filament.app.pages.partials.pos-payment-form', ['livewire' => $this])
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
