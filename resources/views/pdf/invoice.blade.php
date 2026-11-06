<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $brand = $invoice->brand;
        $theme = $brand->theme();
        $merchant = $invoice->merchantName();
        $logo = $brand->logoDataUri();
        $money = fn ($value) => $invoice->money($value);
        $quantity = fn ($value) => rtrim(rtrim(number_format($value, 2), '0'), '.');
        $paid = $invoice->paidTotal();
        $status = $invoice->status === 'sent' ? ($invoice->is_overdue ? 'Overdue' : 'Unpaid') : ucfirst($invoice->status);
    @endphp
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10.5px; line-height: 1.45; margin: 0; }
        .band { background: {{ $theme['accent'] }}; color: {{ $theme['on_accent'] }}; padding: 30px 40px; }
        .band table { width: 100%; border-collapse: collapse; }
        .band td { vertical-align: middle; }
        .logo { background: #fff; padding: 7px 9px; border-radius: 6px; width: 1%; }
        .logo img { max-height: 40px; max-width: 130px; }
        .merchant { font-size: 16px; font-weight: bold; }
        .title { font-size: 24px; font-weight: bold; letter-spacing: 1px; }
        .page { padding: 26px 40px 30px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .label { color: #64748b; font-size: 8.5px; font-weight: bold; letter-spacing: .6px; text-transform: uppercase; margin-bottom: 3px; }
        table.parties { width: 100%; border-collapse: collapse; }
        table.parties td { vertical-align: top; padding-right: 18px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.items th { text-align: left; border-bottom: 2px solid {{ $theme['accent'] }}; padding: 7px 6px; color: #64748b; font-size: 8.5px; letter-spacing: .6px; text-transform: uppercase; }
        table.items th.right { text-align: right; }
        table.items td { border-bottom: 1px solid #e2e8f0; padding: 9px 6px; vertical-align: top; }
        table.totals { width: 44%; margin: 14px 0 0 auto; border-collapse: collapse; }
        table.totals td { padding: 4px 6px; }
        .grand td { font-weight: bold; font-size: 12.5px; border-top: 1px solid #cbd5e1; padding-top: 8px; }
        .note { margin-top: 22px; padding: 10px 12px; background: #f8fafc; border-radius: 4px; }
        .pay { margin-top: 16px; padding: 10px 12px; background: {{ $theme['soft'] }}; border-radius: 4px; word-break: break-all; }
        .pay strong { color: {{ $theme['ink'] }}; }
    </style>
</head>
<body>
    <div class="band">
        <table>
            <tr>
                @if($logo)
                    <td class="logo"><img src="{{ $logo }}" alt=""></td>
                    <td style="width: 12px"></td>
                @endif
                <td><div class="merchant">{{ $merchant }}</div></td>
                <td class="right">
                    <div class="title">INVOICE</div>
                    <div>{{ $invoice->number }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="page">
        <table class="parties">
            <tr>
                <td style="width: 36%">
                    <div class="label">From</div>
                    <strong>{{ $merchant }}</strong>
                    @if($brand->billing_address)<br><span class="muted">{!! nl2br(e($brand->billing_address)) !!}</span>@endif
                    @if($brand->billing_email)<br><span class="muted">{{ $brand->billing_email }}</span>@endif
                </td>
                <td style="width: 36%">
                    <div class="label">Bill to</div>
                    <strong>{{ $invoice->lead->name }}</strong>
                    @if($invoice->lead->company)<br>{{ $invoice->lead->company }}@endif
                    @if($invoice->lead->email)<br>{{ $invoice->lead->email }}@endif
                    @if($invoice->lead->phone)<br>{{ $invoice->lead->phone }}@endif
                </td>
                <td style="padding-right: 0">
                    <div class="label">Issued</div>
                    {{ $invoice->created_at->timezone(config('ppc.report_timezone'))->format('M j, Y') }}
                    @if($invoice->due_date)
                        <div class="label" style="margin-top: 8px">Due</div>
                        {{ $invoice->due_date->format('M j, Y') }}
                    @endif
                    <div class="label" style="margin-top: 8px">Status</div>
                    {{ $status }}
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="right" style="width: 44px">Qty</th>
                    <th class="right" style="width: 86px">Unit price</th>
                    <th class="right" style="width: 86px">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item['label'] }}</td>
                        <td class="right">{{ $quantity($item['quantity']) }}</td>
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
            @if($paid > 0)
                <tr><td class="muted">Amount paid</td><td class="right">{{ $money($paid) }}</td></tr>
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
    </div>
</body>
</html>
