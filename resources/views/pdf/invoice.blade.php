<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; margin: 0; }
        .head { border-bottom: 2px solid #0f766e; padding-bottom: 14px; margin-bottom: 20px; }
        .head td { vertical-align: top; }
        h1 { font-size: 26px; margin: 0; letter-spacing: 1px; }
        .brand { font-size: 14px; font-weight: bold; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        table.full { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 2px 16px 2px 0; vertical-align: top; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 22px; }
        table.items th { text-align: left; border-bottom: 1px solid #cbd5e1; padding: 8px 6px; color: #64748b; font-size: 10px; text-transform: uppercase; }
        table.items th.right { text-align: right; }
        table.items td { border-bottom: 1px solid #e2e8f0; padding: 9px 6px; vertical-align: top; }
        table.totals { width: 45%; margin: 14px 0 0 auto; border-collapse: collapse; }
        table.totals td { padding: 4px 6px; }
        .grand td { font-weight: bold; font-size: 13px; border-top: 1px solid #cbd5e1; padding-top: 8px; }
        .note { margin-top: 26px; padding: 10px 12px; background: #f8fafc; border-radius: 4px; }
        .pay { margin-top: 18px; padding: 10px 12px; border: 1px solid #99f6e4; background: #f0fdfa; border-radius: 4px; word-break: break-all; }
    </style>
</head>
<body>
    @php($money = fn ($value) => $invoice->currency.' '.number_format((float) $value, 2))
    <table class="full head">
        <tr>
            <td><div class="brand">{{ $invoice->brand->name }}</div></td>
            <td class="right"><h1>INVOICE</h1><div class="muted">{{ $invoice->number }}</div></td>
        </tr>
    </table>

    <table class="full">
        <tr>
            <td style="vertical-align: top">
                <div class="muted">Bill to</div>
                <strong>{{ $invoice->lead->name }}</strong>
                @if($invoice->lead->company)<br>{{ $invoice->lead->company }}@endif
                @if($invoice->lead->email)<br>{{ $invoice->lead->email }}@endif
                @if($invoice->lead->phone)<br>{{ $invoice->lead->phone }}@endif
            </td>
            <td style="vertical-align: top; width: 45%">
                <table class="meta" style="margin-left: auto">
                    <tr><td class="muted">Date</td><td>{{ $invoice->created_at->timezone(config('ppc.report_timezone'))->format('M j, Y') }}</td></tr>
                    @if($invoice->due_date)
                        <tr><td class="muted">Due</td><td>{{ $invoice->due_date->format('M j, Y') }}</td></tr>
                    @endif
                    <tr><td class="muted">Status</td><td>{{ $invoice->status === 'sent' ? 'Unpaid' : ucfirst($invoice->status) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th>Description</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Amount</th></tr></thead>
        <tbody>
        @foreach($items as $item)
            <tr>
                <td>{{ $item['label'] }}</td>
                <td class="right">{{ rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') }}</td>
                <td class="right">{{ $money($item['unit_price']) }}</td>
                <td class="right">{{ $money($item['amount']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        @if((float) $invoice->discount > 0 || (float) $invoice->tax_amount > 0)
            <tr><td class="muted">Subtotal</td><td class="right">{{ $money($invoice->subtotal) }}</td></tr>
        @endif
        @if((float) $invoice->discount > 0)
            <tr><td class="muted">Discount</td><td class="right">-{{ $money($invoice->discount) }}</td></tr>
        @endif
        @if((float) $invoice->tax_amount > 0)
            <tr><td class="muted">Tax ({{ rtrim(rtrim($invoice->tax_rate, '0'), '.') }}%)</td><td class="right">{{ $money($invoice->tax_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">{{ $money($invoice->amount) }}</td></tr>
        @if($invoice->paidTotal() > 0)
            <tr><td class="muted">Amount paid</td><td class="right">{{ $money($invoice->paidTotal()) }}</td></tr>
            <tr class="grand"><td>Balance due</td><td class="right">{{ $money($invoice->balanceDue()) }}</td></tr>
        @endif
    </table>

    @if($invoice->description)
        <div class="note"><strong>Notes</strong><br>{{ $invoice->description }}</div>
    @endif

    @if($invoice->status === 'sent' && $invoice->pay_url)
        <div class="pay"><strong>Pay this invoice online</strong><br>{{ $invoice->pay_url }}</div>
        @if(config('ppc.payment_instructions'))
            <div class="note"><strong>Other ways to pay</strong><br>{!! nl2br(e(config('ppc.payment_instructions'))) !!}</div>
        @endif
    @endif

    <p class="muted" style="margin-top: 26px">Prepared by {{ $invoice->createdBy->name }}</p>
</body>
</html>
