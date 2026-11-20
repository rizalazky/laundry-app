@php
    $rp = function ($value): string {
        $number = (float) $value;
        $decimals = fmod($number, 1.0) === 0.0 ? 0 : 2;

        return 'Rp '.number_format($number, $decimals, ',', '.');
    };
    $isThermal = $size === 'thermal';
    $isRefund = fn ($payment): bool => $payment->type === \App\Enums\PaymentType::Refund;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota {{ $order->invoice_no }}</title>
    <style>
        @page { @unless ($isThermal) size: A4; @endunless margin: {{ $isThermal ? '4mm' : '14mm' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #111827; font-family: "Courier New", ui-monospace, monospace; font-size: {{ $isThermal ? '12px' : '14px' }}; line-height: 1.45; }
        .toolbar { display: flex; gap: 8px; justify-content: center; padding: 12px; font-family: system-ui, sans-serif; }
        .toolbar a, .toolbar button { padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; font-size: 14px; text-decoration: none; cursor: pointer; }
        .toolbar .active { background: #111827; color: #fff; border-color: #111827; }
        .receipt { width: {{ $isThermal ? '72mm' : '182mm' }}; margin: 0 auto 24px; padding: {{ $isThermal ? '4mm' : '10mm' }}; background: #fff; }
        .center { text-align: center; }
        .brand { font-size: {{ $isThermal ? '15px' : '20px' }}; font-weight: 700; }
        .muted { color: #4b5563; }
        .rule { border: 0; border-top: 1px dashed #6b7280; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .row > span:last-child { text-align: right; white-space: nowrap; }
        .item { margin-bottom: 6px; }
        .item-name { font-weight: 700; word-break: break-word; }
        .strong { font-weight: 700; }
        .status { margin: 8px 0; padding: 6px; border: 2px solid #111827; text-align: center; font-size: {{ $isThermal ? '14px' : '18px' }}; font-weight: 700; letter-spacing: 1px; }
        .notes { word-break: break-word; white-space: pre-line; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .receipt { margin: 0; padding: 0; width: auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak</button>
        <a href="{{ route('orders.receipt', ['order' => $order, 'size' => 'thermal']) }}" @class(['active' => $isThermal])>Struk 80mm</a>
        <a href="{{ route('orders.receipt', ['order' => $order, 'size' => 'a4']) }}" @class(['active' => ! $isThermal])>A4</a>
    </div>

    <main class="receipt">
        <div class="center">
            <div class="brand">{{ $order->tenant->name }}</div>
            <div>{{ $order->branch->name }}</div>
            @if (filled($order->branch->address))
                <div class="muted">{{ $order->branch->address }}</div>
            @endif
            @if (filled($order->branch->phone))
                <div class="muted">Telp. {{ $order->branch->phone }}</div>
            @endif
        </div>

        <hr class="rule">

        <div class="row"><span>No. Nota</span><span class="strong">{{ $order->invoice_no }}</span></div>
        <div class="row"><span>Tanggal</span><span>{{ $order->order_date->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span>Pelanggan</span><span>{{ $order->customer->name }}</span></div>
        @if (filled($order->customer->phone))
            <div class="row"><span>Telepon</span><span>{{ $order->customer->phone }}</span></div>
        @endif
        @if ($order->creator)
            <div class="row"><span>Kasir</span><span>{{ $order->creator->name }}</span></div>
        @endif
        @if ($order->due_at)
            <div class="row"><span>Estimasi selesai</span><span>{{ $order->due_at->format('d/m/Y H:i') }}</span></div>
        @endif

        <hr class="rule">

        @foreach ($order->items as $item)
            <div class="item">
                <div class="item-name">{{ $item->service_name }}</div>
                <div class="row">
                    <span>{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }} {{ $item->unit->value }} x {{ $rp($item->unit_price) }}</span>
                    <span>{{ $rp($item->subtotal) }}</span>
                </div>
            </div>
        @endforeach

        <hr class="rule">

        <div class="row"><span>Subtotal</span><span>{{ $rp($order->subtotal) }}</span></div>
        @if ((float) $order->discount > 0)
            <div class="row"><span>Diskon</span><span>-{{ $rp($order->discount) }}</span></div>
        @endif
        <div class="row strong"><span>TOTAL</span><span>{{ $rp($order->total) }}</span></div>

        @if ($order->payments->isNotEmpty())
            <hr class="rule">
            <div class="strong">Pembayaran</div>
            @foreach ($order->payments as $payment)
                <div class="row">
                    <span>{{ $payment->paid_at->format('d/m H:i') }} {{ $isRefund($payment) ? 'Refund' : $payment->method->label() }}@if ($isRefund($payment)) ({{ $payment->method->label() }})@endif</span>
                    <span>{{ $isRefund($payment) ? '-' : '' }}{{ $rp($payment->amount) }}</span>
                </div>
            @endforeach
            <div class="row strong"><span>Total dibayar</span><span>{{ $rp($order->paid_amount) }}</span></div>
        @endif

        @if ($order->status === \App\Enums\OrderStatus::Cancelled)
            <div class="status">DIBATALKAN</div>
        @elseif ($order->payment_status === \App\Enums\PaymentStatus::Paid)
            <div class="status">LUNAS</div>
        @else
            <div class="row strong"><span>SISA TAGIHAN</span><span>{{ $rp($order->balanceDue()) }}</span></div>
        @endif

        @if (filled($order->notes))
            <hr class="rule">
            <div class="strong">Catatan</div>
            <div class="notes">{{ $order->notes }}</div>
        @endif

        <hr class="rule">
        <div class="center muted">Simpan nota ini untuk pengambilan cucian.<br>Terima kasih.</div>
    </main>
</body>
</html>
