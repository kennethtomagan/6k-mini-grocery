<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $transaction->reference_number }}</title>
    <style>
        @page { size: {{ $paperWidth }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 2mm;
            width: {{ $printableWidth }}mm;
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: {{ $paperWidth === 80 ? 12 : 10 }}px;
            line-height: 1.35;
            color: #000;
            background: #fff;
        }
        h1 { margin: 0; font-size: 1.3em; text-align: center; }
        p { margin: 0; }
        .center { text-align: center; }
        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .row span:last-child { text-align: right; white-space: nowrap; }
        .rule { border-top: 1px dashed #000; margin: 1.5mm 0; }
        .item-name { word-break: break-word; }
        .total { font-size: 1.3em; font-weight: bold; }
        .void { border: 2px solid #000; font-size: 1.4em; font-weight: bold; text-align: center; margin: 1.5mm 0; padding: 1mm; }
        .actions { margin-top: 12px; display: flex; gap: 8px; }
        .actions button { flex: 1; padding: 12px; font-size: 16px; cursor: pointer; }
        @media screen { body { margin: 16px auto; padding: 12px; border: 1px dashed #999; } }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    <h1>{{ $settings->store_name }}</h1>
    @if ($settings->store_address)
        <p class="center">{{ $settings->store_address }}</p>
    @endif
    @if ($settings->store_phone)
        <p class="center">{{ $settings->store_phone }}</p>
    @endif

    @if ($transaction->isVoided())
        <div class="void">VOIDED</div>
    @endif

    <div class="rule"></div>
    <div class="row"><span>No.</span><span>{{ $transaction->reference_number }}</span></div>
    <div class="row"><span>Date</span><span>{{ $transaction->created_at->format('Y-m-d h:i A') }}</span></div>
    <div class="row"><span>Cashier</span><span>{{ $transaction->cashier->name }}</span></div>
    <div class="rule"></div>

    @foreach ($transaction->items as $item)
        <p class="item-name">{{ $item->product_name }}</p>
        <div class="row">
            <span>&nbsp;&nbsp;{{ $item->quantity }} x {{ number_format((float) $item->unit_price, 2) }}</span>
            <span>{{ number_format((float) $item->line_total, 2) }}</span>
        </div>
    @endforeach

    <div class="rule"></div>
    <div class="row"><span>Subtotal</span><span>{{ number_format((float) $transaction->subtotal, 2) }}</span></div>
    @if ((float) $transaction->discount_amount > 0)
        <div class="row"><span>Discount</span><span>-{{ number_format((float) $transaction->discount_amount, 2) }}</span></div>
    @endif
    <div class="row total"><span>TOTAL PHP</span><span>{{ number_format((float) $transaction->total, 2) }}</span></div>
    <div class="row"><span>Cash</span><span>{{ number_format((float) $transaction->cash_received, 2) }}</span></div>
    <div class="row"><span>Change</span><span>{{ number_format((float) $transaction->change_amount, 2) }}</span></div>
    <div class="rule"></div>

    @if ($settings->receipt_footer)
        <p class="center">{{ $settings->receipt_footer }}</p>
    @endif
    <p class="center">This is not an official receipt.</p>

    <div class="actions">
        <button type="button" onclick="self.print()">Print</button>
        <button type="button" onclick="self.close()">Close</button>
    </div>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => window.print());
            window.addEventListener('afterprint', () => window.close());
        </script>
    @endif
</body>
</html>
