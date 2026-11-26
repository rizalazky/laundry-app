<x-filament-panels::page>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">Waktu</th>
                    <th class="px-4 py-3 text-left font-semibold">Tenant</th>
                    <th class="px-4 py-3 text-left font-semibold">Event</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                    <th class="px-4 py-3 text-left font-semibold">Paket</th>
                    <th class="px-4 py-3 text-left font-semibold">Invoice</th>
                    <th class="px-4 py-3 text-left font-semibold">Actor</th>
                    <th class="px-4 py-3 text-left font-semibold">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($events as $event)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $event->occurred_at?->format('d M Y H:i:s') ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $event->tenant?->name ?? 'Tenant #'.$event->tenant_id }}</div>
                            <div class="text-xs text-gray-500">ID {{ $event->tenant_id }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 font-medium">
                            {{ str($event->event_type)->replace('_', ' ')->title() }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $event->from_status ?? '—' }} → {{ $event->to_status ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $event->fromPlan?->name ?? '—' }} → {{ $event->toPlan?->name ?? '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $event->invoice?->invoice_number ?? '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-300">
                            {{ $event->actor?->name ?? 'System / Gateway' }}
                        </td>
                        <td class="px-4 py-3">
                            @if (filled($event->metadata))
                                <details>
                                    <summary class="cursor-pointer text-primary-600 dark:text-primary-400">Lihat</summary>
                                    <pre class="mt-2 max-w-md overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($event->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-gray-500">
                            Belum ada histori subscription.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
