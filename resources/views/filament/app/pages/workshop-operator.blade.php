<x-filament-panels::page>
    <div wire:poll.15s class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Scan nota masuk</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->currentBranchName() }} · Gunakan barcode scanner USB atau ketik nomor nota.
                    </p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full bg-success-50 px-3 py-1 text-xs font-medium text-success-700 dark:bg-success-400/10 dark:text-success-400">
                    <span class="h-2 w-2 rounded-full bg-success-500"></span>
                    Antrean diperbarui otomatis
                </span>
            </div>

            <form wire:submit="scanOrder" class="flex flex-col gap-2 sm:flex-row">
                <x-filament::input
                    id="workshop-barcode"
                    type="text"
                    wire:model="barcode"
                    placeholder="Scan barcode / masukkan nomor nota..."
                    autocomplete="off"
                    autofocus
                    class="text-base"
                />
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" class="min-h-12">
                    Cari Nota
                </x-filament::button>
            </form>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Scanner yang berfungsi seperti keyboard akan mengirim nomor nota dan menekan Enter secara otomatis.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($this->queueStatusOptions() as $status => $label)
                <x-filament::button
                    type="button"
                    :color="$selectedStatus === $status ? 'primary' : 'gray'"
                    :outlined="$selectedStatus !== $status"
                    size="sm"
                    wire:click="$set('selectedStatus', '{{ $status }}')"
                >
                    {{ $label }}
                    <x-filament::badge color="gray">{{ $this->queueCounts()[$status] ?? 0 }}</x-filament::badge>
                </x-filament::button>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.85fr)]">
            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Antrean pengerjaan</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Maks. 100 order</span>
                </div>

                @forelse ($this->queueOrders() as $order)
                    <x-filament::button
                        type="button"
                        wire:key="workshop-order-{{ $order->id }}"
                        wire:click="selectOrder({{ $order->id }})"
                        @class([
                            'w-full rounded-xl border p-4 text-left shadow-sm transition',
                            'border-primary-500 bg-primary-50 dark:bg-primary-400/10' => $selectedOrderId === $order->id,
                            'border-gray-200 bg-white hover:border-primary-300 dark:border-gray-700 dark:bg-gray-900' => $selectedOrderId !== $order->id,
                        ])
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-gray-950 dark:text-white">{{ $order->invoice_no }}</p>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $order->customer?->name ?? 'Pelanggan' }}</p>
                            </div>
                            <x-filament::badge :color="$order->status->color()">
                                {{ $order->status->label() }}
                            </x-filament::badge>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ $order->items->count() }} layanan</span>
                            <span>Masuk {{ $order->order_date?->format('d M H:i') }}</span>
                            <span>Estimasi {{ $order->due_at?->format('d M H:i') ?? '—' }}</span>
                        </div>
                    </x-filament::button>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                        <x-filament::icon icon="heroicon-o-inbox" class="mx-auto h-8 w-8 text-gray-400" />
                        <p class="mt-2 font-medium text-gray-700 dark:text-gray-300">Belum ada order pada antrean ini</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Order yang ditugaskan ke workshop ini akan muncul di sini.</p>
                    </div>
                @endforelse
            </section>

            <section class="space-y-3">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Detail pengerjaan</h2>

                @php($selectedOrder = $this->selectedOrder())

                @if ($selectedOrder)
                    <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Nomor nota</p>
                                <h3 class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $selectedOrder->invoice_no }}</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $selectedOrder->customer?->name ?? 'Pelanggan' }}</p>
                            </div>
                            <x-filament::badge :color="$selectedOrder->status->color()">
                                {{ $selectedOrder->status->label() }}
                            </x-filament::badge>
                        </div>

                        <div class="space-y-2">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Rincian layanan</h4>
                            @foreach ($selectedOrder->items as $item)
                                <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800">
                                    <span class="text-gray-700 dark:text-gray-200">{{ $item->service_name }}</span>
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ rtrim(rtrim((string) $item->quantity, '0'), '.') }} {{ $item->unit->value }}
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        @php($activeLog = $this->activeProductionLog($selectedOrder))
                        @if ($activeLog)
                            <div
                                wire:key="production-timer-{{ $activeLog->id }}"
                                data-started-at="{{ $activeLog->started_at->timestamp }}"
                                x-data="{
                                    startedAt: {{ $activeLog->started_at->timestamp }},
                                    elapsedSeconds: 0,
                                    intervalId: null,
                                    init() {
                                        this.updateElapsed();
                                        this.intervalId = window.setInterval(() => this.updateElapsed(), 1000);
                                    },
                                    destroy() {
                                        if (this.intervalId !== null) {
                                            window.clearInterval(this.intervalId);
                                        }
                                    },
                                    updateElapsed() {
                                        this.elapsedSeconds = Math.max(0, Math.floor(Date.now() / 1000) - this.startedAt);
                                    },
                                    formatElapsed() {
                                        const hours = Math.floor(this.elapsedSeconds / 3600);
                                        const minutes = Math.floor((this.elapsedSeconds % 3600) / 60);
                                        const seconds = this.elapsedSeconds % 60;

                                        return [hours, minutes, seconds]
                                            .map((value) => String(value).padStart(2, '0'))
                                            .join(':');
                                    },
                                }"
                                class="rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-500/30 dark:bg-success-400/10"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide text-success-700 dark:text-success-400">Timer tahap berjalan</p>
                                <p
                                    class="mt-1 font-mono text-3xl font-bold tabular-nums text-success-800 dark:text-success-300"
                                    x-text="formatElapsed()"
                                >{{ $this->formatElapsedTime($activeLog) }}</p>
                                <p class="mt-1 text-xs text-success-700 dark:text-success-400">
                                    Mulai {{ $activeLog->started_at->format('d M Y H:i:s') }}
                                    @if ($activeLog->worker)
                                        · {{ $activeLog->worker->name }}
                                    @endif
                                    @if ($activeLog->machine_code)
                                        · Mesin {{ $activeLog->machine_code }}
                                    @endif
                                </p>
                            </div>
                        @else
                            <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                Belum ada tahap produksi yang sedang berjalan.
                            </div>
                        @endif

                        @if (count($this->availableTransitions($selectedOrder)) > 0)
                            <div class="space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Penugasan tahap berikutnya</h4>

                                @if (auth()->user()->role !== 'operator')
                                    <div>
                                        <label for="workshop-worker" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Petugas produksi</label>
                                        <x-filament::input.select id="workshop-worker" wire:model="workerId" class="text-sm">
                                            <option value="">Pilih petugas (opsional)</option>
                                            @foreach ($this->workerOptions() as $worker)
                                                <option value="{{ $worker->id }}">{{ $worker->name }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        Petugas: <strong>{{ auth()->user()->name }}</strong>
                                    </p>
                                @endif

                                <div>
                                    <label for="workshop-machine" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Kode mesin (opsional)</label>
                                    <x-filament::input
                                        id="workshop-machine"
                                        type="text"
                                        wire:model="machineCode"
                                        maxlength="50"
                                        placeholder="Contoh: WASH-02"
                                        class="text-sm"
                                    />
                                </div>

                                <div>
                                    <label for="workshop-note" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Catatan (opsional)</label>
                                    <x-filament::input.wrapper>
                                        <textarea
                                            id="workshop-note"
                                            wire:model="note"
                                            rows="2"
                                            maxlength="500"
                                            class="fi-input block w-full border-none bg-transparent px-3 py-2 text-sm text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:text-white dark:placeholder:text-gray-500 sm:text-sm"
                                        ></textarea>
                                    </x-filament::input.wrapper>
                                </div>

                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach ($this->availableTransitions($selectedOrder) as $nextStatus)
                                        <x-filament::button
                                            type="button"
                                            color="primary"
                                            wire:click="changeStage('{{ $nextStatus->value }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="changeStage"
                                        >
                                            {{ $nextStatus->label() }}
                                        </x-filament::button>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <p class="rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-400/10 dark:text-success-400">
                                Order sudah berada pada tahap akhir yang dapat dikerjakan di workshop.
                            </p>
                        @endif
                    </div>
                @else
                    <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                        <x-filament::icon icon="heroicon-o-qr-code" class="mx-auto h-8 w-8 text-gray-400" />
                        <p class="mt-2 font-medium text-gray-700 dark:text-gray-300">Pilih atau scan nota</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Detail order, timer, petugas, dan tahap produksi akan tampil di sini.</p>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
