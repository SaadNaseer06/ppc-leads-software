<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentTransaction;
use App\Services\InvoicePayments;
use App\Services\InvoicePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    private const LIMIT = 200;

    private const MAX_ZIP = 500;

    public function __construct(private InvoicePdf $pdf, private InvoicePayments $payments) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewInvoices(), 403);

        return response()->json([
            'invoices' => Invoice::query()->visibleTo($user)
                ->with(['brand:id,name', 'lead:id,name', 'createdBy:id,name'])
                ->withSum('payments as paid_total', 'amount')
                ->latest()->limit(self::LIMIT)->get(),
            'limit' => self::LIMIT,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canCreateInvoice(), 403);
        $today = now(config('ppc.report_timezone'))->toDateString();
        $data = $request->validate([
            'lead_id' => ['required', 'integer'],
            'items' => ['required_without:amount', 'array', 'min:1', 'max:50'],
            'items.*.label' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            // A single amount with no items is still accepted from API clients
            'amount' => ['required_without:items', 'numeric', 'gt:0', 'max:9999999999'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'due_date' => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$today}"],
            'currency' => ['sometimes', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'items.required_without' => 'Add at least one item',
            'items.*.label.required' => 'Every item needs a description',
            'items.*.quantity.gt' => 'Quantity must be greater than zero',
            'amount.gt' => 'Amount must be greater than zero',
            'due_date.after_or_equal' => "The due date can't be in the past",
        ]);

        $lead = Lead::query()->visibleTo($user)->findOrFail($data['lead_id']);
        $items = $data['items'] ?? [['label' => ($data['description'] ?? null) ?: 'Services', 'quantity' => 1, 'unit_price' => $data['amount']]];
        $priced = Invoice::priced($items, (float) ($data['discount'] ?? 0), (float) ($data['tax_rate'] ?? 0));
        if ($priced['discount'] > $priced['subtotal']) {
            return response()->json(['error' => "The discount can't be more than the subtotal"], 422);
        }
        if ($priced['amount'] <= 0) {
            return response()->json(['error' => 'The invoice total must be greater than zero'], 422);
        }
        if ($priced['amount'] > 9999999999) {
            return response()->json(['error' => 'The invoice total is too large'], 422);
        }

        $invoice = Invoice::createWithNumber([
            ...$priced,
            'lead_id' => $lead->id,
            'brand_id' => $lead->brand_id,
            'created_by_id' => $user->id,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => 'sent',
        ]);
        $lead->activities()->create([
            'actor_id' => $user->id,
            'type' => 'invoice',
            'data' => ['number' => $invoice->number, 'amount' => (float) $invoice->amount, 'currency' => $invoice->currency],
        ]);
        ActivityLog::record($user->id, 'invoice.create', 'Invoice', $invoice->id, ['number' => $invoice->number, 'amount' => $invoice->amount]);

        return response()->json([
            'id' => $invoice->id,
            'number' => $invoice->number,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'pay_url' => $invoice->pay_url,
            'lead' => $lead->only(['name', 'email']),
        ], 201);
    }

    /** Mark an invoice paid, cancelled, or back to sent */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageInvoices(), 403);
        $invoice = Invoice::query()->visibleTo($user)->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(Invoice::STATUSES)]]);
        // An invoice that payments already cover can only be paid, not reopened as unpaid
        $status = $data['status'] === 'sent' && $invoice->balanceDue() <= 0 ? 'paid' : $data['status'];

        if ($invoice->status !== $status) {
            $from = $invoice->status;
            DB::transaction(function () use ($invoice, $status) {
                $balance = $invoice->balanceDue();
                // "Paid" means the money arrived: the balance joins the payments, so totals stay true
                if ($status === 'paid' && $balance > 0) {
                    $this->payments->add($invoice, $balance, 'other', null, 'Marked as paid');
                }
                $invoice->update(['status' => $status]);
            });
            ActivityLog::record($user->id, 'invoice.status', 'Invoice', $invoice->id, ['from' => $from, 'to' => $status]);
        }

        return response()->json(['ok' => true, 'status' => $invoice->status]);
    }

    /** Record a payment; the invoice is marked paid once payments cover the total */
    public function storePayment(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageInvoices(), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::in(Invoice::PAYMENT_METHODS)],
            'external_ref' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['amount.gt' => 'Amount must be greater than zero']);

        $result = DB::transaction(function () use ($user, $id, $data) {
            // Locked so two people recording at once can't both pass the balance check
            $invoice = Invoice::query()->visibleTo($user)->lockForUpdate()->findOrFail($id);
            if ($invoice->status === 'cancelled') {
                return ['error' => 'This invoice is cancelled. Reopen it before recording a payment.'];
            }
            $paid = (float) $invoice->payments()->sum('amount');
            $balance = round((float) $invoice->amount - $paid, 2);
            if (round((float) $data['amount'], 2) > $balance) {
                return ['error' => $balance > 0
                    ? 'That is more than the balance due ('.number_format($balance, 2).' '.$invoice->currency.').'
                    : 'This invoice is already paid in full.'];
            }

            $paid = $this->payments->add($invoice, (float) $data['amount'], $data['method'], $data['external_ref'] ?? null, $data['notes'] ?? null);

            return ['invoice' => $invoice, 'paid' => $paid];
        });
        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        $invoice = $result['invoice'];
        ActivityLog::record($user->id, 'invoice.payment', 'Invoice', $invoice->id, ['amount' => $data['amount'], 'method' => $data['method']]);

        return response()->json(['ok' => true, 'status' => $invoice->status, 'paid_total' => $result['paid']], 201);
    }

    public function pdf(Request $request, int $id): Response
    {
        $invoice = Invoice::query()->visibleTo($request->user())->with(['brand', 'lead', 'createdBy'])
            ->withSum('payments as paid_total', 'amount')->findOrFail($id);

        return $this->pdf->make($invoice)->download("{$invoice->number}.pdf");
    }

    /** Finance page: recent payments and invoices */
    public function finance(Request $request): JsonResponse
    {
        abort_unless($request->user()->canViewFinance(), 403);

        return response()->json([
            'transactions' => PaymentTransaction::with(['invoice:id,number,currency,brand_id,lead_id', 'invoice.brand:id,name', 'invoice.lead:id,name'])
                ->latest('recorded_at')->limit(self::LIMIT)->get(),
            'invoices' => Invoice::with(['brand:id,name', 'lead:id,name'])->withSum('payments as paid_total', 'amount')
                ->latest()->limit(self::LIMIT)
                ->get(['id', 'number', 'amount', 'currency', 'status', 'due_date', 'pay_token', 'brand_id', 'lead_id', 'created_at']),
            'limit' => self::LIMIT,
        ]);
    }

    public function bulkPdf(Request $request): BinaryFileResponse|JsonResponse
    {
        abort_unless($request->user()->canViewFinance(), 403);
        $ids = array_slice(array_filter((array) $request->input('invoice_ids', []), 'is_numeric'), 0, self::MAX_ZIP);
        $invoices = Invoice::with(['brand', 'lead', 'createdBy'])->withSum('payments as paid_total', 'amount')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->latest()->limit(self::MAX_ZIP)->get();
        if ($invoices->isEmpty()) {
            return response()->json(['error' => 'No invoices found'], 404);
        }

        // ~0.3 s per PDF: hundreds of them outlast shared hosting's max_execution_time
        @set_time_limit(0);
        // Written into tempnam()'s own file, which is deleted after sending
        $path = tempnam(sys_get_temp_dir(), 'inv');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($invoices as $invoice) {
            $zip->addFromString("{$invoice->number}.pdf", $this->pdf->make($invoice)->output());
        }
        $zip->close();
        ActivityLog::record($request->user()->id, 'invoice.bulk_download', 'Invoice', null, ['count' => $invoices->count()]);

        return response()->download($path, 'invoices-bulk.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Per-brand totals computed with GROUP BY, never by loading rows into memory */
    public function salesByBrand(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewReports(), 403);
        $brands = Brand::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']);
        $ids = $brands->pluck('id');

        // One pass over the brand index for both counts; cached briefly because it reads every lead
        $leads = Cache::remember('reports:leads:'.$ids->implode(','), 300, fn () => Lead::whereIn('brand_id', $ids)
            ->groupBy('brand_id')
            ->selectRaw("brand_id, SUM(status <> 'spam') AS n, SUM(status = 'converted') AS converted")
            ->get()->mapWithKeys(fn ($r) => [$r->brand_id => ['n' => (int) $r->n, 'converted' => (int) $r->converted]])->all());
        $invoiced = Invoice::whereIn('brand_id', $ids)->where('status', '!=', 'cancelled')->groupBy('brand_id')
            ->select('brand_id', DB::raw('COUNT(*) AS n'), DB::raw('SUM(amount) AS total'))->get()->keyBy('brand_id');
        // Money actually received (partial payments included)
        $paid = PaymentTransaction::join('invoices', 'invoices.id', '=', 'payment_transactions.invoice_id')
            ->whereIn('invoices.brand_id', $ids)->where('invoices.status', '!=', 'cancelled')
            ->groupBy('invoices.brand_id')
            ->selectRaw('invoices.brand_id, SUM(payment_transactions.amount) AS total')->pluck('total', 'brand_id');
        // Still to collect: unpaid invoices minus their part payments
        $paidPerInvoice = PaymentTransaction::groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS paid');
        $due = Invoice::leftJoinSub($paidPerInvoice, 'p', 'p.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.brand_id', $ids)->where('invoices.status', 'sent')->groupBy('invoices.brand_id')
            ->selectRaw('invoices.brand_id, SUM(invoices.amount - COALESCE(p.paid, 0)) AS total')
            ->pluck('total', 'brand_id');

        return response()->json(['reports' => $brands->map(fn (Brand $b) => [
            'brand_id' => $b->id,
            'brand_name' => $b->name,
            'lead_count' => $leads[$b->id]['n'] ?? 0,
            'converted_count' => $leads[$b->id]['converted'] ?? 0,
            'invoice_count' => (int) ($invoiced[$b->id]->n ?? 0),
            'total_amount' => (float) ($invoiced[$b->id]->total ?? 0),
            'paid_amount' => (float) ($paid[$b->id] ?? 0),
            'outstanding_amount' => max(0, (float) ($due[$b->id] ?? 0)),
        ])]);
    }
}
