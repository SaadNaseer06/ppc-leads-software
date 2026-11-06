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
        $settled = $invoice->status === 'paid';
        $cancelled = $invoice->status === 'cancelled';
        $status = $invoice->status === 'sent' ? ($invoice->is_overdue ? 'Overdue' : 'Unpaid') : ucfirst($invoice->status);
    @endphp
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; line-height: 1.45; margin: 0; }
        .bar { height: 7px; background: {{ $theme['accent'] }}; }
        .page { padding: 32px 42px 30px; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .logo { max-height: 62px; max-width: 200px; }
        .name { font-size: 17px; font-weight: bold; }
        .title { font-size: 24px; letter-spacing: 3px; color: {{ $theme['ink'] }}; }
        .number { font-size: 10.5px; font-weight: bold; color: #334155; margin-top: 3px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .label { color: #64748b; font-size: 7.5px; font-weight: bold; letter-spacing: .7px; text-transform: uppercase; margin-bottom: 3px; }
        .facts { margin-top: 22px; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
        .facts td { padding: 9px 0; width: 25%; }
        .value { font-weight: bold; }
        .late { color: #b91c1c; }
        .parties { margin-top: 18px; }
        .parties td { width: 50%; padding-right: 20px; }
        table.items { margin-top: 20px; }
        table.items th { text-align: left; background: {{ $theme['soft'] }}; color: {{ $theme['ink'] }}; padding: 7px 8px; font-size: 7.5px; letter-spacing: .6px; text-transform: uppercase; }
        table.items th.right { text-align: right; }
        table.items td { border-bottom: 1px solid #eef1f5; padding: 9px 8px; }
        table.totals { width: 44%; margin: 12px 0 0 auto; }
        table.totals td { padding: 3px 8px; }
        .sum td { font-weight: bold; border-top: 1px solid #e2e8f0; padding-top: 7px; }
        .owed { width: 44%; margin: 8px 0 0 auto; background: {{ $theme['soft'] }}; color: {{ $theme['ink'] }}; font-weight: bold; }
        .owed td { padding: 9px 10px; font-size: 12px; }
        .note { margin-top: 20px; padding: 10px 12px; background: #f8fafc; }
        .pay { margin-top: 14px; padding: 10px 12px; background: {{ $theme['soft'] }}; word-break: break-all; }
        .pay strong { color: {{ $theme['ink'] }}; }
        .foot { margin-top: 24px; padding-top: 10px; border-top: 1px solid #e2e8f0; color: #64748b; }
    </style>
</head>
<body>
    <div class="bar"></div>
    <div class="page">
        <table>
            <tr>
                <td>
                    @if($logo)
                        <img class="logo" src="{{ $logo }}" alt="">
                    @else
                        <div class="name">{{ $merchant }}</div>
                    @endif
                </td>
                <td class="right">
                    <div class="title">INVOICE</div>
                    <div class="number">{{ $invoice->number }}</div>
                </td>
            </tr>
        </table>

        <table class="facts">
            <tr>
                <td><div class="label">Issued</div><span class="value">{{ $invoice->created_at->timezone(config('ppc.report_timezone'))->format('M j, Y') }}</span></td>
                <td><div class="label">Due</div><span class="value {{ $invoice->is_overdue ? 'late' : '' }}">{{ $invoice->due_date?->format('M j, Y') ?? 'On receipt' }}</span></td>
                <td><div class="label">Status</div><span class="value {{ $invoice->is_overdue ? 'late' : '' }}">{{ $status }}</span></td>
                <td><div class="label">{{ $settled ? 'Paid' : 'Amount due' }}</div><span class="value">{{ $money($settled ? $invoice->amount : ($cancelled ? 0 : $invoice->balanceDue())) }}</span></td>
            </tr>
        </table>

        <table class="parties">
            <tr>
                <td>
                    <div class="label">Billed to</div>
                    <strong>{{ $invoice->clientName() }}</strong>
                    @if($invoice->client_company)<br>{{ $invoice->client_company }}@endif
                    @if($invoice->client_address)<br><span class="muted">{!! nl2br(e($invoice->client_address)) !!}</span>@endif
                    @if($invoice->client_email)<br><span class="muted">{{ $invoice->client_email }}</span>@endif
                    @if($invoice->client_phone)<br><span class="muted">{{ $invoice->client_phone }}</span>@endif
                </td>
                <td>
                    <div class="label">From</div>
                    <strong>{{ $merchant }}</strong>
                    @if($brand->billing_address)<br><span class="muted">{!! nl2br(e($brand->billing_address)) !!}</span>@endif
                    @if($brand->billing_email)<br><span class="muted">{{ $brand->billing_email }}</span>@endif
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="right" style="width: 40px">Qty</th>
                    <th class="right" style="width: 84px">Unit price</th>
                    <th class="right" style="width: 84px">Amount</th>
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
            <tr class="sum"><td>Total</td><td class="right">{{ $money($invoice->amount) }}</td></tr>
            @if($paid > 0)
                <tr><td class="muted">Amount paid</td><td class="right">-{{ $money($paid) }}</td></tr>
            @endif
        </table>
        @unless($cancelled)
            <table class="owed"><tr><td>Balance due</td><td class="right">{{ $money($settled ? 0 : $invoice->balanceDue()) }}</td></tr></table>
        @endunless

        @if($invoice->description)
            <div class="note"><strong>Notes</strong><br>{{ $invoice->description }}</div>
        @endif

        @if($invoice->status === 'sent' && $invoice->pay_url)
            <div class="pay"><strong>Pay this invoice online</strong><br>{{ $invoice->pay_url }}</div>
            @if(config('ppc.payment_instructions'))
                <div class="note"><strong>Other ways to pay</strong><br>{!! nl2br(e(config('ppc.payment_instructions'))) !!}</div>
            @endif
        @endif

        <div class="foot">Thank you for your business.@if($brand->billing_email) Questions? {{ $brand->billing_email }}@endif</div>
    </div>
</body>
</html>
