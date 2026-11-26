<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-100">
            Pembayaran di bawah ini telah dikonfirmasi oleh payment gateway setelah invoice melewati batas waktu.
            Pembayaran dan invoice tidak diaktifkan otomatis. Tandai sudah ditinjau setelah tindak lanjut dilakukan di luar sistem.
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Waktu Pembayaran</th>
                        <th class="px-4 py-3 text-left font-semibold">Tenant</th>
                        <th class="px-4 py-3 text-left font-semibold">Invoice</th>
                        <th class="px-4 py-3 text-left font-semibold">Nominal</th>
                        <th class="px-4 py-3 text-left font-semibold">Provider Reference</th>
                        <th class="px-4 py-3 text-left font-semibold">Batas Invoice</th>
                        <th class="px-4 py-3 text-left font-semibold">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($reviewEvents as $event)
                        <tr wire:key="late-payment-{{ $event->id }}">
                            <td class="whitespace-nowrap px-4 py-3">{{ $event->occurred_at?->format('d M Y H:i:s') ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $event->tenant?->name ?? 'Tenant #'.$event->tenant_id }}</div>
                                <div class="text-xs text-gray-500">ID {{ $event->tenant_id }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $event->invoice?->invoice_number ?? '—' }}</div>
                                <div class="text-xs text-gray-500">{{ $event->invoice?->status ?? 'Invoice unavailable' }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                {{ $event->metadata['currency'] ?? 'IDR' }} {{ number_format((float) ($event->metadata['amount'] ?? 0), 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $event->metadata['provider_reference'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ isset($event->metadata['invoice_due_at']) ? \Illuminate\\Support\\Carbon::parse($event->metadata['invoice_due_at'])->format('d M Y H:i:s') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <x-filament::button
                                    size="sm"
                                    color="success"
                                    wire:click="markReviewed({{ $event->id }})"
                                    wire:confirm="Tandai pembayaran ini sudah ditinjau? Tindakan ini hanya mencatat review dan tidak mengubah status pembayaran atau subscription."
                                >
                                    Tandai Ditinjau
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-gray-500">
                                Tidak ada pembayaran terlambat yang menunggu review.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
