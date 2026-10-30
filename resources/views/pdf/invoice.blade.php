<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; margin: 0; }
        .head { border-bottom: 2px solid #0f766e; padding-bottom: 16px; margin-bottom: 24px; }
        h1 { font-size: 26px; margin: 0; letter-spacing: 1px; }
        .muted { color: #64748b; }
        table.meta td { padding: 2px 16px 2px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.items th { text-align: left; border-bottom: 1px solid #cbd5e1; padding: 8px 0; color: #64748b; font-size: 11px; text-transform: uppercase; }
        table.items td { border-bottom: 1px solid #e2e8f0; padding: 10px 0; }
        table.items .right { text-align: right; }
        .total td { font-weight: bold; font-size: 14px; border-bottom: none; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="head">
        <h1>INVOICE</h1>
        <div class="muted">{{ $invoice->brand->name }}</div>
    </div>

    <table class="meta">
        <tr><td class="muted">Invoice #</td><td><strong>{{ $invoice->number }}</strong></td></tr>
        <tr><td class="muted">Date</td><td>{{ $invoice->created_at->timezone(config('ppc.report_timezone'))->format('M j, Y') }}</td></tr>
        <tr><td class="muted">Status</td><td>{{ ucfirst($invoice->status) }}</td></tr>
        <tr><td class="muted">Bill to</td><td>{{ $invoice->lead->name }}@if($invoice->lead->email)<br>{{ $invoice->lead->email }}@endif</td></tr>
    </table>

    <table class="items">
        <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
        <tbody>
        @foreach($items as $item)
            <tr><td>{{ $item['label'] }}</td><td class="right">{{ $invoice->currency }} {{ number_format((float) $item['amount'], 2) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total</td><td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->amount, 2) }}</td></tr>
        @if((float) $invoice->paid_total > 0)
            <tr><td class="muted">Amount paid</td><td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->paid_total, 2) }}</td></tr>
            <tr class="total"><td>Balance due</td><td class="right">{{ $invoice->currency }} {{ number_format(max(0, (float) $invoice->amount - (float) $invoice->paid_total), 2) }}</td></tr>
        @endif
        </tbody>
    </table>

    <p class="muted" style="margin-top: 40px">Prepared by {{ $invoice->createdBy->name }}</p>
</body>
</html>
