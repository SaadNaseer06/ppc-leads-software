@extends('pay.layout')

@php
    $brand = $invoice->brand;
    $merchant = $invoice->merchantName();
    $client = $invoice->clientName();
    $money = fn ($value) => $invoice->money($value);
    $quantity = fn ($value) => rtrim(rtrim(number_format($value, 2), '0'), '.');
    $issued = $invoice->created_at->timezone(config('ppc.report_timezone'))->format('M j, Y');
    $settled = $invoice->status === 'paid';
    $cancelled = $invoice->status === 'cancelled';
    $cardForm = $payable && $cardPayments;
    $testMode = $cardForm && ! str_contains((string) $stripeKey, '_live_');
    $stripeSetup = [
        'key' => $stripeKey,
        'amount' => $amountDue,
        'currency' => strtolower($invoice->currency),
        'accent' => $theme['accent'],
        'intentUrl' => "/pay/{$invoice->pay_token}/intent",
        'returnPath' => "/pay/{$invoice->pay_token}/return",
        'billing' => array_filter(['name' => $client, 'email' => $invoice->client_email]),
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
    <div class="band"></div>

    <main class="shell">
        <article class="paper doc">
            <header class="head">
                <div>
                    @if($brand->logoUrl())
                        <img class="head-logo" src="{{ $brand->logoUrl() }}" alt="{{ $merchant }}">
                    @else
                        <div class="head-name">{{ $merchant }}</div>
                    @endif
                </div>
                <div class="head-right">
                    <div class="head-title">INVOICE</div>
                    <div class="head-meta">
                        <span>{{ $invoice->number }}</span>
                        <span class="pill {{ $pillClass }}">{{ $pillText }}</span>
                    </div>
                </div>
            </header>

            <div class="info">
                <div>
                    <div class="label">Billed to</div>
                    <div class="strong">{{ $client }}</div>
                    @if($invoice->client_company)<div class="muted small">{{ $invoice->client_company }}</div>@endif
                    @if($invoice->client_address)<div class="muted small pre">{{ $invoice->client_address }}</div>@endif
                    @if($invoice->client_email)<div class="muted small">{{ $invoice->client_email }}</div>@endif
                    @if($invoice->client_phone)<div class="muted small">{{ $invoice->client_phone }}</div>@endif
                </div>
                <div>
                    <div class="label">From</div>
                    <div class="strong">{{ $merchant }}</div>
                    @if($brand->billing_address)<div class="muted small pre">{{ $brand->billing_address }}</div>@endif
                    @if($brand->billing_email)<div class="small"><a href="mailto:{{ $brand->billing_email }}">{{ $brand->billing_email }}</a></div>@endif
                </div>
                <div>
                    <div class="label">Issued</div>
                    <div class="strong">{{ $issued }}</div>
                </div>
                <div>
                    <div class="label">Due</div>
                    <div @class(['strong', 'late' => $invoice->is_overdue])>{{ $invoice->due_date?->format('M j, Y') ?? 'On receipt' }}</div>
                </div>
            </div>

            @if($cardForm)
                <a class="jump" href="#pay">Pay {{ $money($balance) }}</a>
            @endif

            <div class="items">
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
            </div>

            <div class="summary">
                @if($invoice->description)
                    <div class="note">
                        <div class="label">Notes</div>
                        <div class="pre">{{ $invoice->description }}</div>
                    </div>
                @endif
                <div class="summary-right">
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
                        <div class="sum"><span>Total</span><span>{{ $money($invoice->amount) }}</span></div>
                        @if($paid > 0)
                            <div><span class="muted">Paid</span><span>-{{ $money($paid) }}</span></div>
                        @endif
                    </div>
                    @unless($cancelled)
                        <div class="owed"><span>Balance due</span><strong>{{ $money($settled ? 0 : $balance) }}</strong></div>
                    @endunless
                </div>
            </div>

            <footer class="doc-foot">
                <span>Thank you for your business.</span>
                @if($brand->billing_email)<span>Questions? <a href="mailto:{{ $brand->billing_email }}">{{ $brand->billing_email }}</a></span>@endif
            </footer>
        </article>

        <aside class="paper panel" id="pay">
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

                <div class="panel-top">
                    <div class="label">Amount due</div>
                    @if($testMode)
                        <span class="pill test" title="No real money will be taken">Test mode</span>
                    @endif
                </div>
                <div class="panel-amount">{{ $money($balance) }}</div>
                <p class="paying">To <strong>{{ $merchant }}</strong></p>
                <hr class="rule">

                @if($cardForm)
                    <form id="pay-form">
                        <div class="loading" id="card-loading">Loading secure card form…</div>
                        <div id="card-element"></div>
                        <div class="notice error" id="pay-error" role="alert" style="margin: 14px 0 0" hidden></div>
                        <button class="btn" id="pay-button" type="submit">Pay {{ $money($balance) }}</button>
                    </form>
                    <p class="secure">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <span>Encrypted and processed securely by Stripe</span>
                    </p>
                @endif

                @if($instructions)
                    <div @class(['or' => $cardForm])>
                        <div class="label">{{ $cardForm ? 'Or pay by' : 'How to pay' }}</div>
                        <div class="pre small">{{ $instructions }}</div>
                    </div>
                @elseif(! $cardForm)
                    <div class="label">How to pay</div>
                    <div class="small">
                        Please contact {{ $merchant }} to arrange payment.
                        @if($brand->billing_email)
                            <br><a href="mailto:{{ $brand->billing_email }}">{{ $brand->billing_email }}</a>
                        @endif
                    </div>
                @endif
            @endif

            <a class="pdf" href="/pay/{{ $invoice->pay_token }}/pdf">{{ $settled ? 'Download receipt' : 'Download PDF' }}</a>
        </aside>
    </main>

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
                const formFailed = () => {
                    loading.hidden = true;
                    button.hidden = true;
                    showError("The card form couldn't load. Please refresh the page, or turn off any content blocker for this site.");
                };

                if (!window.Stripe) return formFailed();

                let stripe, elements;
                try {
                    stripe = Stripe(pay.key, { developerTools: { assistant: { enabled: false } } });
                    elements = stripe.elements({
                        mode: 'payment',
                        amount: pay.amount,
                        currency: pay.currency,
                        allowedPaymentMethodTypes: ['card'],
                        appearance: {
                            theme: 'stripe',
                            labels: 'floating',
                            variables: {
                                colorPrimary: pay.accent,
                                colorText: '#0f172a',
                                borderRadius: '10px',
                                spacingUnit: '3px',
                                // Phones zoom into anything smaller when it's tapped
                                fontSizeBase: window.innerWidth > 980 ? '14px' : '16px',
                                fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                            },
                        },
                    });
                    const card = elements.create('payment', { layout: 'tabs', wallets: { link: 'never' }, defaultValues: { billingDetails: pay.billing } });
                    card.on('ready', () => { loading.hidden = true; });
                    card.on('loaderror', formFailed);
                    card.mount('#card-element');
                } catch (problem) {
                    console.error(problem);
                    return formFailed();
                }

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (button.disabled) return;
                    button.disabled = true;
                    button.textContent = 'Processing…';
                    errorBox.hidden = true;

                    const { error: invalid } = await elements.submit();
                    if (invalid?.type === 'validation_error') {
                        // Stripe already shows these under each field
                        button.disabled = false;
                        button.textContent = buttonText;
                        return;
                    }
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
