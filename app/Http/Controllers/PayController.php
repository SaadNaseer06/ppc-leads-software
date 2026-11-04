<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePayments;
use App\Services\InvoicePdf;
use App\Services\StripeCheckout;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The client's side of an invoice. The unguessable token in the link is the only credential. */
class PayController extends Controller
{
    public function __construct(private StripeCheckout $stripe, private InvoicePayments $payments) {}

    public function show(string $token): Response
    {
        $invoice = $this->invoice($token);
        if (! $invoice) {
            return response()->view('pay.invalid', [], 404)->withHeaders($this->privateHeaders());
        }

        return response()->view('pay.show', [
            'invoice' => $invoice,
            'items' => $invoice->items(),
            'paid' => $invoice->paidTotal(),
            'balance' => $invoice->balanceDue(),
            'payable' => $this->payable($invoice),
            'cardPayments' => $this->stripe->configured(),
            'instructions' => config('ppc.payment_instructions'),
        ])->withHeaders($this->privateHeaders());
    }

    /** Off to Stripe's hosted page for the balance due */
    public function checkout(string $token): RedirectResponse
    {
        $invoice = $this->invoice($token);
        abort_unless($invoice, 404);
        if (! $this->payable($invoice) || ! $this->stripe->configured()) {
            return redirect("/pay/{$token}");
        }

        try {
            return redirect()->away($this->stripe->createSession($invoice)['url']);
        } catch (RequestException $e) {
            report($e);

            return redirect("/pay/{$token}")->with('pay_error', 'Online payment is unavailable right now. Please try again shortly.');
        }
    }

    /**
     * The client is back from Stripe. Asking Stripe directly means the payment is recorded
     * even if the webhook is late or was never set up.
     */
    public function returned(Request $request, string $token): RedirectResponse
    {
        $invoice = $this->invoice($token);
        abort_unless($invoice, 404);

        $session = $this->stripe->configured() ? $this->stripe->session((string) $request->query('session_id')) : null;
        // A session for another invoice proves nothing about this one
        if ($session && (int) ($session['metadata']['invoice_id'] ?? 0) === $invoice->id) {
            $this->payments->recordCheckout($session);
        }

        return redirect("/pay/{$token}");
    }

    public function pdf(string $token, InvoicePdf $pdf): Response
    {
        $invoice = $this->invoice($token);
        abort_unless($invoice, 404);

        return $pdf->make($invoice)->download("{$invoice->number}.pdf");
    }

    private function invoice(string $token): ?Invoice
    {
        return Invoice::where('pay_token', $token)->with(['brand:id,name', 'lead', 'createdBy:id,name'])
            ->withSum('payments as paid_total', 'amount')->first();
    }

    private function payable(Invoice $invoice): bool
    {
        return $invoice->status === 'sent' && $invoice->balanceDue() > 0;
    }

    /** The link is private: keep it out of search engines and other sites' referrer logs */
    private function privateHeaders(): array
    {
        return ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store'];
    }
}
