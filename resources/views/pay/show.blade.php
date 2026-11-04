@extends('pay.layout')

@section('title', "Invoice {$invoice->number} · {$invoice->brand->name}")

@section('content')
    @php
        $money = fn ($value) => $invoice->currency.' '.number_format((float) $value, 2);
        $quantity = fn ($value) => rtrim(rtrim(number_format($value, 2), '0'), '.');
        [$pillClass, $pillText] = match (true) {
            $invoice->status === 'paid' => ['paid', 'Paid'],
            $invoice->status === 'cancelled' => ['cancelled', 'Cancelled'],
            $invoice->is_overdue => ['overdue', 'Overdue'],
            default => ['unpaid', 'Unpaid'],
        };
    @endphp

    <div class="card">
        <div class="top">
            <div>
                <div class="brand">{{ $invoice->brand->name }}</div>
                <div class="muted small">Invoice {{ $invoice->number }}</div>
            </div>
            <div><span class="pill {{ $pillClass }}">{{ $pillText }}</span></div>
        </div>

        <div class="section meta">
            <div>
                <div class="label">Bill to</div>
                <div>{{ $invoice->lead->name }}</div>
                @if($invoice->lead->company)<div class="muted small">{{ $invoice->lead->company }}</div>@endif
            </div>
            <div>
                <div class="label">Date</div>
                <div>{{ $invoice->created_at->timezone(config('ppc.report_timezone'))->format('M j, Y') }}</div>
            </div>
            @if($invoice->due_date)
                <div>
                    <div class="label">Due</div>
                    <div>{{ $invoice->due_date->format('M j, Y') }}</div>
                </div>
            @endif
        </div>

        <div class="section">
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="num hide-sm">Qty</th>
                        <th class="num hide-sm">Unit price</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        <tr>
                            <td>
                                {{ $item['label'] }}
                                @if((float) $item['quantity'] !== 1.0)
                                    <div class="muted small show-sm">{{ $quantity($item['quantity']) }} × {{ $money($item['unit_price']) }}</div>
                                @endif
                            </td>
                            <td class="num hide-sm">{{ $quantity($item['quantity']) }}</td>
                            <td class="num hide-sm">{{ $money($item['unit_price']) }}</td>
                            <td class="num">{{ $money($item['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="totals">
                @if((float) $invoice->discount > 0 || (float) $invoice->tax_amount > 0)
                    <div><span class="muted">Subtotal</span><span>{{ $money($invoice->subtotal) }}</span></div>
                @endif
                @if((float) $invoice->discount > 0)
                    <div><span class="muted">Discount</span><span>-{{ $money($invoice->discount) }}</span></div>
                @endif
                @if((float) $invoice->tax_amount > 0)
                    <div><span class="muted">Tax ({{ rtrim(rtrim($invoice->tax_rate, '0'), '.') }}%)</span><span>{{ $money($invoice->tax_amount) }}</span></div>
                @endif
                <div class="grand"><span>Total</span><span>{{ $money($invoice->amount) }}</span></div>
                @if($paid > 0)
                    <div><span class="muted">Paid</span><span>{{ $money($paid) }}</span></div>
                    <div class="grand"><span>Balance due</span><span>{{ $money($balance) }}</span></div>
                @endif
            </div>
        </div>

        @if($invoice->description)
            <div class="section">
                <div class="label">Notes</div>
                <div class="pre">{{ $invoice->description }}</div>
            </div>
        @endif

        <div class="section">
            @if($invoice->status === 'paid')
                <div class="notice ok">This invoice has been paid in full. Thank you.</div>
            @elseif($invoice->status === 'cancelled')
                <div class="notice">This invoice was cancelled, so there is nothing to pay.</div>
            @elseif($payable)
                @if(session('pay_error'))
                    <div class="notice error">{{ session('pay_error') }}</div>
                @endif
                @if($cardPayments)
                    <form method="post" action="{{ url("/pay/{$invoice->pay_token}/checkout") }}">
                        @csrf
                        <button class="btn" type="submit">Pay {{ $money($balance) }}</button>
                    </form>
                    <p class="muted small" style="text-align: center; margin: 10px 0 0">Secure card payment by Stripe. Your card details never reach us.</p>
                @endif
                @if($instructions)
                    <div @if($cardPayments) style="margin-top: 16px" @endif>
                        <div class="label">{{ $cardPayments ? 'Or pay by' : 'How to pay' }}</div>
                        <div class="pre">{{ $instructions }}</div>
                    </div>
                @elseif(! $cardPayments)
                    <div class="notice">Please contact {{ $invoice->brand->name }} to arrange payment.</div>
                @endif
            @endif
        </div>
    </div>

    <div class="foot">
        <span>{{ $invoice->brand->name }}</span>
        <a class="link" href="{{ url("/pay/{$invoice->pay_token}/pdf") }}">Download PDF</a>
    </div>
@endsection
