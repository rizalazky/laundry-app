<x-filament-panels::page>
    <div class="space-y-6">
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
                    <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        Perubahan paket terjadwal ke {{ $subscription->scheduledPlan->name }}
                        @if ($subscription->scheduled_change_at)
                            pada {{ $subscription->scheduled_change_at->format('d M Y') }}
                        @endif
                    </div>
                @endif
            @else
                <p class="text-sm text-danger-600">Data subscription belum tersedia. Hubungi administrator platform.</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Buat Invoice</x-slot>
            <x-slot name="description">Buat invoice pembelian awal atau perpanjangan paket yang sedang digunakan.</x-slot>

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
                    </div>
                    <x-filament::button type="submit" icon="heroicon-m-document-plus" wire:loading.attr="disabled" wire:target="createInvoice">
                        Buat invoice
                    </x-filament::button>
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
