<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\BrandTheme;
use App\Services\InvoicePayments;
use App\Services\InvoicePdf;
use App\Services\PayDomain;
use App\Services\StripePayments;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** The client's side of an invoice. The unguessable token in the link is the only credential. */
class PayController extends Controller
{
    public function __construct(private StripePayments $stripe, private InvoicePayments $payments) {}

    public function show(Request $request, string $token): Response
    {
        $invoice = $this->invoice($request, $token);
        if (! $invoice) {
            return response()->view('pay.invalid', ['theme' => BrandTheme::from(null)], 404)->withHeaders($this->privateHeaders());
        }

        return response()->view('pay.show', [
            'invoice' => $invoice,
            'theme' => $invoice->brand->theme(),
            'items' => $invoice->items(),
            'paid' => $invoice->paidTotal(),
            'balance' => $invoice->balanceDue(),
            'payable' => $this->payable($invoice),
            'cardPayments' => $this->stripe->configured($invoice->brand),
            'stripeKey' => $this->stripe->account($invoice->brand)['publishable'],
            'amountDue' => StripePayments::amountDue($invoice),
            'instructions' => config('ppc.payment_instructions'),
            'outcome' => $request->query('payment'),
        ])->withHeaders($this->privateHeaders());
    }

    /** Called by the Pay button: the card form needs a payment to confirm */
    public function intent(Request $request, string $token): JsonResponse
    {
        abort_unless($found = $this->invoice($request, $token), 404);
        if (! $this->stripe->configured($found->brand)) {
            return response()->json(['error' => 'Card payments are unavailable for this invoice.'], 409);
        }

        try {
            $intent = DB::transaction(function () use ($found) {
                // Locked so two open tabs can't each start a payment for the same balance
                $invoice = Invoice::with('brand', 'lead')->lockForUpdate()->find($found->id);

                return $this->payable($invoice) ? $this->stripe->intentFor($invoice) : null;
            });
        } catch (RequestException|ConnectionException $e) {
            report($e);

            return response()->json(['error' => 'Card payment is unavailable right now. You have not been charged. Please try again shortly.'], 503);
        }

        if (! $intent) {
            return response()->json(['error' => 'This invoice no longer needs paying.', 'reload' => true], 409);
        }
        if ($intent['status'] === 'succeeded') {
            $this->payments->recordIntent($intent);

            return response()->json(['error' => 'This invoice has already been paid.', 'reload' => true], 409);
        }
        if ($intent['status'] === 'processing') {
            return response()->json(['error' => 'Your payment is still being processed. Please check back shortly.'], 409);
        }

        return response()->json(['client_secret' => $intent['client_secret']]);
    }

    /**
     * The client is back from Stripe. Asking Stripe directly means the payment is recorded
     * even if the webhook is late or was never set up, and a crafted link proves nothing.
     */
    public function returned(Request $request, string $token): RedirectResponse
    {
        abort_unless($invoice = $this->invoice($request, $token), 404);

        $intent = $this->stripe->configured($invoice->brand) ? $this->stripe->intent((string) $request->query('payment_intent'), $invoice->brand) : null;
        // A payment for another invoice proves nothing about this one
        if (! $intent || (int) ($intent['metadata']['invoice_id'] ?? 0) !== $invoice->id) {
            return redirect("/pay/{$token}");
        }

        $this->payments->recordIntent($intent);

        return redirect("/pay/{$token}".match ($intent['status']) {
            'succeeded' => '',
            'processing' => '?payment=processing',
            default => '?payment=failed',
        });
    }

    public function pdf(Request $request, string $token, InvoicePdf $pdf): Response
    {
        abort_unless($invoice = $this->invoice($request, $token), 404);

        return $pdf->make($invoice)->download("{$invoice->number}.pdf");
    }

    /** Proves to the Brands page that a domain really reaches this app */
    public function domainProof(Request $request, PayDomain $domains): Response
    {
        return response($domains->proof($request->getHost()), 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    /** On a brand's own domain, only that brand's invoices exist */
    private function invoice(Request $request, string $token): ?Invoice
    {
        return Invoice::where('pay_token', $token)
            ->when($request->attributes->get('pay_brand_id'), fn ($q, $brandId) => $q->where('brand_id', $brandId))
            ->with(['brand', 'lead', 'createdBy:id,name'])
            ->withSum('payments as paid_total', 'amount')->first();
    }

    private function payable(Invoice $invoice): bool
    {
        return $invoice->status === 'sent' && $invoice->balanceDue() > 0;
    }

    /** The link is private: keep it out of search engines and other sites' logs. Stripe still sees our origin. */
    private function privateHeaders(): array
    {
        return ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'strict-origin', 'Cache-Control' => 'no-store'];
    }
}
