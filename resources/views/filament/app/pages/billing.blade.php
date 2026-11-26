<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Pricing & Paket</x-slot>
            <x-slot name="description">Bandingkan harga, fitur, dan limit dari paket langganan yang tersedia.</x-slot>

            @if ($plans->isEmpty())
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    Belum ada paket yang tersedia. Silakan hubungi administrator platform.
                </p>
            @else
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($plans as $plan)
                        <article class="flex h-full flex-col rounded-xl border border-gray-200 p-5 dark:border-gray-700">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $plan->name }}</h3>
                                    @if ($plan->description)
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $plan->description }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($subscription?->plan_id === $plan->id)
                                        <x-filament::badge color="success">Paket saat ini</x-filament::badge>
                                    @endif
                                    @if ((string) $selectedPlanId === (string) $plan->id && $subscription && $subscription->current_period_start === null && $subscription->current_period_end === null)
                                        <x-filament::badge color="info">Dipilih</x-filament::badge>
                                    @endif
                                </div>
                            </div>

                            @if ($subscription && $subscription->current_period_start === null && $subscription->current_period_end === null)
                                <label class="mt-4 flex cursor-pointer items-center gap-2 rounded-lg border border-primary-300 bg-primary-50 px-3 py-2 text-sm font-medium text-primary-700 dark:border-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                    <input
                                        type="radio"
                                        name="selectedPlanId"
                                        value="{{ $plan->id }}"
                                        wire:model="selectedPlanId"
                                        class="border-gray-300 text-primary-600 focus:ring-primary-500"
                                    >
                                    Pilih paket ini
                                </label>
                            @endif

                            @if ($subscription && $subscription->current_period_end?->isFuture() && $subscription->plan_id !== $plan->id)
                                <div class="mt-4">
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        wire:click="schedulePlanChange({{ $plan->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="schedulePlanChange({{ $plan->id }})"
                                    >
                                        Jadwalkan paket ini
                                    </x-filament::button>
                                </div>
                            @endif

                            <div class="mt-5 grid grid-cols-2 gap-3">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Bulanan</p>
                                    <p class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
                                        @if ((float) $plan->monthly_price <= 0)
                                            Gratis
                                        @else
                                            {{ $plan->currency }} {{ number_format((float) $plan->monthly_price, 0, ',', '.') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Tahunan</p>
                                    <p class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
                                        @if ($plan->yearly_price === null)
                                            Tidak tersedia
                                        @elseif ((float) $plan->yearly_price <= 0)
                                            Gratis
                                        @else
                                            {{ $plan->currency }} {{ number_format((float) $plan->yearly_price, 0, ',', '.') }}
                                        @endif
                                    </p>
                                </div>
                            </div>

                            @if ($plan->features->isNotEmpty())
                                <div class="mt-5 flex-1">
                                    <p class="mb-2 text-sm font-medium text-gray-950 dark:text-white">Fitur & limit</p>
                                    <ul class="space-y-2 text-sm">
                                        @foreach ($plan->features as $feature)
                                            <li class="flex items-start justify-between gap-3">
                                                <span class="text-gray-600 dark:text-gray-300">{{ match ($feature->feature_code) {
                                                        'max_outlets' => 'Maksimal Outlet',
                                                        'max_users' => 'Maksimal Pengguna',
                                                        default => str($feature->feature_code)->replace('_', ' ')->title(),
                                                    } }}</span>
                                                <span class="text-right font-medium text-gray-950 dark:text-white">
                                                    @if (is_bool($feature->value))
                                                        {{ $feature->value ? 'Termasuk' : 'Tidak termasuk' }}
                                                    @elseif ($feature->value === 'unlimited')
                                                        Tanpa batas
                                                    @elseif ($feature->value === null)
                                                        —
                                                    @else
                                                        {{ $feature->value }}
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @else
                                <p class="mt-5 flex-1 text-sm text-gray-500 dark:text-gray-400">
                                    Detail fitur dan limit belum dikonfigurasi.
                                </p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">Status Langganan</x-slot>
            <x-slot name="description">Informasi paket dan periode berlangganan bisnis Anda.</x-slot>

            @if ($subscription)
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Paket saat ini</p>
                        <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $subscription->plan?->name ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                        <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ str($subscription->status)->replace('_', ' ')->title() }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Akhir masa trial</p>
                        <p class="mt-1 font-medium text-gray-950 dark:text-white">{{ $subscription->trial_ends_at?->format('d M Y H:i') ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Periode berbayar berakhir</p>
                        <p class="mt-1 font-medium text-gray-950 dark:text-white">{{ $subscription->current_period_end?->format('d M Y H:i') ?? 'Belum ada' }}</p>
                    </div>
                </div>

                @if ($subscription->scheduledPlan)
                    <div class="mt-4 flex flex-col gap-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            Perubahan paket terjadwal ke {{ $subscription->scheduledPlan->name }}
                            @if ($subscription->scheduled_change_at)
                                pada {{ $subscription->scheduled_change_at->format('d M Y H:i') }}
                            @endif
                        </span>
                        <x-filament::button size="sm" color="gray" wire:click="cancelScheduledPlanChange" wire:loading.attr="disabled" wire:target="cancelScheduledPlanChange">
                            Batalkan jadwal
                        </x-filament::button>
                    </div>
                @endif
                @error('plan') <p class="mt-3 text-sm text-danger-600">{{ $message }}</p> @enderror
            @else
                <p class="text-sm text-danger-600">Data subscription belum tersedia. Hubungi administrator platform.</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Buat Invoice</x-slot>
            <x-slot name="description">
                @if ($subscription && $subscription->current_period_start === null && $subscription->current_period_end === null)
                    Pilih paket aktif di atas, lalu buat invoice sesuai periode yang diinginkan.
                @else
                    Buat invoice perpanjangan untuk paket yang sedang digunakan.
                @endif
            </x-slot>

            @if ($subscription)
                <form wire:submit="createInvoice" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="w-full sm:max-w-xs">
                        <label for="billingInterval" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Periode tagihan</label>
                        <x-filament::input.wrapper>
                            <select id="billingInterval" wire:model="billingInterval" class="w-full border-0 bg-transparent text-sm focus:ring-0">
                                <option value="monthly">Bulanan</option>
                                <option value="yearly">Tahunan</option>
                            </select>
                        </x-filament::input.wrapper>
                        @error('billingInterval') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        @error('invoice') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    @if ($subscription->current_period_start === null && $subscription->current_period_end === null)
                        @error('selectedPlanId') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                        <x-filament::button type="submit" icon="heroicon-m-document-plus" wire:loading.attr="disabled" wire:target="createInvoice">
                            Buat invoice paket terpilih
                        </x-filament::button>
                    @else
                        <x-filament::button type="submit" icon="heroicon-m-document-plus" wire:loading.attr="disabled" wire:target="createInvoice">
                            Buat invoice perpanjangan
                        </x-filament::button>
                    @endif
                </form>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Riwayat Invoice</x-slot>
            <x-slot name="description">Invoice terbaru untuk tenant ini.</x-slot>

            @if ($invoices->isEmpty())
                <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    Belum ada invoice. Buat invoice untuk memulai pembayaran.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="whitespace-nowrap py-3 pr-4 font-medium">Invoice</th>
                                <th class="whitespace-nowrap px-4 py-3 font-medium">Paket</th>
                                <th class="whitespace-nowrap px-4 py-3 font-medium">Periode</th>
                                <th class="whitespace-nowrap px-4 py-3 font-medium">Total</th>
                                <th class="whitespace-nowrap px-4 py-3 font-medium">Status</th>
                                <th class="whitespace-nowrap py-3 pl-4 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($invoices as $invoice)
                                @php($latestPayment = $invoice->payments->first())
                                <tr>
                                    <td class="whitespace-nowrap py-3 pr-4">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $invoice->invoice_number }}</span>
                                        <span class="mt-1 block text-xs text-gray-500">{{ $invoice->created_at?->format('d M Y') }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $invoice->plan_name_snapshot }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ str($invoice->billing_interval)->title() }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $invoice->currency }} {{ number_format((float) $invoice->total_amount, 0, ',', '.') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        {{ str($invoice->status)->replace('_', ' ')->title() }}
                                        @if ($latestPayment)
                                            <span class="mt-1 block text-xs text-gray-500">Pembayaran: {{ str($latestPayment->status)->title() }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap py-3 pl-4">
                                        @if (in_array($invoice->status, ['issued', 'pending'], true) && (float) $invoice->total_amount > 0)
                                            <x-filament::button size="sm" wire:click="payInvoice({{ $invoice->id }})" wire:loading.attr="disabled" wire:target="payInvoice({{ $invoice->id }})">
                                                Bayar
                                            </x-filament::button>
                                        @elseif ($invoice->status === 'paid')
                                            <span class="text-success-600">Lunas</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @unless ($gatewayConfigured)
                <p class="mt-4 text-sm text-warning-600">Payment gateway belum dikonfigurasi. Hubungi administrator platform.</p>
            @endunless
        </x-filament::section>
    </div>
</x-filament-panels::page>
