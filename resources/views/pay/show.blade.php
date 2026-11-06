@extends('pay.layout')

@php
    $brand = $invoice->brand;
    $merchant = $invoice->merchantName();
    $money = fn ($value) => $invoice->money($value);
    $quantity = fn ($value) => rtrim(rtrim(number_format($value, 2), '0'), '.');
    $issued = $invoice->created_at->timezone(config('ppc.report_timezone'))->format('M j, Y');
    $settled = $invoice->status === 'paid';
    $cancelled = $invoice->status === 'cancelled';
    $cardForm = $payable && $cardPayments;
    $stripeSetup = [
        'key' => config('services.stripe.publishable'),
        'amount' => $amountDue,
        'currency' => strtolower($invoice->currency),
        'accent' => $theme['accent'],
        'intentUrl' => "/pay/{$invoice->pay_token}/intent",
        'returnPath' => "/pay/{$invoice->pay_token}/return",
        'name' => $invoice->lead->name,
        'email' => $invoice->lead->email,
    ];
    [$pillClass, $pillText] = match (true) {
        $settled => ['paid', 'Paid'],
        $cancelled => ['cancelled', 'Cancelled'],
        $invoice->is_overdue => ['overdue', 'Overdue'],
        default => ['unpaid', 'Unpaid'],
    };
@endphp

@section('title', "Invoice {$invoice->number} · {$merchant}")

@section('head')
    @if($brand->logoUrl())
        <link rel="icon" href="{{ $brand->logoUrl() }}">
    @endif
    @if($cardForm)
        <script src="{{ config('services.stripe.js_url') }}"></script>
    @endif
@endsection

@section('content')
    <header class="band">
        <div class="band-in">
            <div class="who">
                @if($brand->logoUrl())
                    <span class="logo"><img src="{{ $brand->logoUrl() }}" alt=""></span>
                @endif
                <div>
                    <div class="merchant">{{ $merchant }}</div>
                    <div class="sub">Invoice {{ $invoice->number }}</div>
                </div>
            </div>
            <div class="due">
                <div class="sub">{{ $settled ? 'Paid in full' : ($cancelled ? 'Cancelled' : 'Amount due') }}</div>
                <div class="due-amount">{{ $money($payable ? $balance : $invoice->amount) }}</div>
                @if($payable && $invoice->due_date)
                    <div class="sub">{{ $invoice->is_overdue ? 'Was due' : 'Due' }} {{ $invoice->due_date->format('M j, Y') }}</div>
                @endif
                @if($cardForm)
                    <a class="jump" href="#pay">Pay now</a>
                @endif
            </div>
        </div>
    </header>

    <main class="shell">
        <article class="card">
            <div class="sheet-head">
                <h1>Invoice {{ $invoice->number }}</h1>
                <span class="pill {{ $pillClass }}">{{ $pillText }}</span>
            </div>

            <div class="section parties">
                <div>
                    <div class="label">From</div>
                    <div class="strong">{{ $merchant }}</div>
                    @if($brand->billing_address)<div class="muted small pre">{{ $brand->billing_address }}</div>@endif
                    @if($brand->billing_email)<div class="small"><a href="mailto:{{ $brand->billing_email }}">{{ $brand->billing_email }}</a></div>@endif
                </div>
                <div>
                    <div class="label">Bill to</div>
                    <div class="strong">{{ $invoice->lead->name }}</div>
                    @if($invoice->lead->company)<div class="muted small">{{ $invoice->lead->company }}</div>@endif
                    @if($invoice->lead->email)<div class="muted small">{{ $invoice->lead->email }}</div>@endif
                </div>
                <div>
                    <div class="label">Issued</div>
                    <div>{{ $issued }}</div>
                    @if($invoice->due_date)
                        <div class="label" style="margin-top: 10px">Due</div>
                        <div>{{ $invoice->due_date->format('M j, Y') }}</div>
                    @endif
                </div>
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
        </article>

        <aside class="card panel" id="pay">
            @if($settled)
                <div class="state">
                    <span class="state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg></span>
                    <h2>Payment received</h2>
                    <p>This invoice has been paid in full. Thank you.</p>
                </div>
            @elseif($cancelled)
                <div class="state">
                    <span class="state-icon off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></span>
                    <h2>Invoice cancelled</h2>
                    <p>This invoice was cancelled, so there is nothing to pay.</p>
                </div>
            @elseif($payable)
                @if($outcome === 'failed')
                    <div class="notice error">Your card wasn't charged. Please try again or use a different card.</div>
                @elseif($outcome === 'processing')
                    <div class="notice info">Your payment is being processed. This invoice will update once it's confirmed.</div>
                @endif

                @if($cardForm)
                    <h2>Pay by card</h2>
                    <p class="paying">You're paying <strong>{{ $merchant }}</strong></p>
                    <form id="pay-form">
                        <div id="card-element"><div class="loading" id="card-loading">Loading secure card form…</div></div>
                        <div class="notice error" id="pay-error" role="alert" style="margin: 14px 0 0" hidden></div>
                        <button class="btn" id="pay-button" type="submit">Pay {{ $money($balance) }}</button>
                    </form>
                    <p class="secure">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <span>Your card details go straight to Stripe over an encrypted connection and are never stored by {{ $merchant }}.</span>
                    </p>
                @endif

                @if($instructions)
                    <div @class(['or' => $cardForm])>
                        <div class="label">{{ $cardForm ? 'Or pay by' : 'How to pay' }}</div>
                        <div class="pre">{{ $instructions }}</div>
                    </div>
                @elseif(! $cardForm)
                    <h2>How to pay</h2>
                    <p class="paying" style="margin-bottom: 0">
                        Please contact {{ $merchant }} to arrange payment.
                        @if($brand->billing_email)
                            <br><a href="mailto:{{ $brand->billing_email }}">{{ $brand->billing_email }}</a>
                        @endif
                    </p>
                @endif
            @endif

            <a class="ghost" href="/pay/{{ $invoice->pay_token }}/pdf">{{ $settled ? 'Download receipt' : 'Download PDF' }}</a>
        </aside>
    </main>

    <footer class="foot">
        <span>{{ $merchant }}</span>
        @if($brand->billing_email)<a href="mailto:{{ $brand->billing_email }}">{{ $brand->billing_email }}</a>@endif
    </footer>

    @if($cardForm)
        <script>
            (() => {
                const pay = @json($stripeSetup);
                const form = document.getElementById('pay-form');
                const button = document.getElementById('pay-button');
                const errorBox = document.getElementById('pay-error');
                const loading = document.getElementById('card-loading');
                const buttonText = button.textContent;

                const showError = (message) => {
                    errorBox.textContent = message;
                    errorBox.hidden = false;
                    button.disabled = false;
                    button.textContent = buttonText;
                };

                if (!window.Stripe) {
                    loading.hidden = true;
                    button.hidden = true;
                    showError("The card form couldn't load. Please refresh the page, or turn off any content blocker for this site.");
                    return;
                }

                const stripe = Stripe(pay.key);
                const elements = stripe.elements({
                    mode: 'payment',
                    amount: pay.amount,
                    currency: pay.currency,
                    allowedPaymentMethodTypes: ['card'],
                    appearance: {
                        theme: 'stripe',
                        variables: {
                            colorPrimary: pay.accent,
                            colorText: '#0f172a',
                            borderRadius: '10px',
                            fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                        },
                    },
                });
                const card = elements.create('payment', {
                    layout: 'tabs',
                    defaultValues: { billingDetails: { name: pay.name, email: pay.email || '' } },
                });
                card.on('ready', () => { loading.hidden = true; });
                card.mount('#card-element');

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (button.disabled) return;
                    button.disabled = true;
                    button.textContent = 'Processing…';
                    errorBox.hidden = true;

                    const { error: invalid } = await elements.submit();
                    if (invalid) return showError(invalid.message);

                    let result;
                    try {
                        const response = await fetch(pay.intentUrl, { method: 'POST', headers: { Accept: 'application/json' } });
                        result = await response.json();
                    } catch {
                        return showError('We could not reach the payment service. You have not been charged. Please try again.');
                    }
                    if (result.reload) return location.reload();
                    if (!result.client_secret) return showError(result.error || 'Something went wrong. You have not been charged.');

                    const { error } = await stripe.confirmPayment({
                        elements,
                        clientSecret: result.client_secret,
                        confirmParams: { return_url: new URL(pay.returnPath, location.origin).href },
                    });
                    if (error) showError(error.message);
                });
            })();
        </script>
    @endif
@endsection
