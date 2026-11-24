<div class="space-y-4">
    @forelse ($adjustments as $adjustment)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="font-semibold">
                    {{ $adjustment->type === 'refund' ? 'Refund' : 'Kompensasi' }}
                    <span class="ml-2 text-sm font-normal text-gray-500">
                        {{ strtoupper($adjustment->status) }}
                    </span>
                </div>
                <div class="font-semibold tabular-nums">
                    Rp {{ number_format((float) ($adjustment->approved_amount ?? $adjustment->requested_amount), 0, ',', '.') }}
                </div>
            </div>
            <p class="mt-2 text-sm">{{ $adjustment->reason }}</p>
            @if ($adjustment->notes)
                <p class="mt-1 text-sm text-gray-500">{{ $adjustment->notes }}</p>
            @endif
            <dl class="mt-3 grid gap-2 text-xs text-gray-500 sm:grid-cols-2">
                <div><dt class="inline">Diajukan:</dt> <dd class="inline">{{ $adjustment->requester?->name ?? '—' }} · {{ $adjustment->requested_at?->format('d/m/Y H:i') }}</dd></div>
                <div><dt class="inline">Diputuskan:</dt> <dd class="inline">{{ $adjustment->approver?->name ?? '—' }} · {{ ($adjustment->approved_at ?? $adjustment->rejected_at)?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                @if ($adjustment->paid_at)
                    <div><dt class="inline">Dibayar:</dt> <dd class="inline">{{ $adjustment->payer?->name ?? '—' }} · {{ $adjustment->paid_at->format('d/m/Y H:i') }}</dd></div>
                @endif
                @if ($adjustment->rejection_reason)
                    <div class="sm:col-span-2"><dt class="inline">Alasan penolakan:</dt> <dd class="inline">{{ $adjustment->rejection_reason }}</dd></div>
                @endif
            </dl>
        </div>
    @empty
        <p class="text-sm text-gray-500">Belum ada pengajuan refund atau kompensasi untuk order ini.</p>
    @endforelse
</div>
