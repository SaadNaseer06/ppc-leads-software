<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $data = $request->validate([
            'lead_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:5000'],
            'line_items' => ['sometimes', 'array'],
            'line_items.*.label' => ['required', 'string'],
            'line_items.*.amount' => ['required', 'numeric'],
        ], ['amount.gt' => 'Amount must be greater than zero']);

        $lead = Lead::query()->visibleTo($user)->findOrFail($data['lead_id']);
        $invoice = Invoice::createWithNumber([
            'lead_id' => $lead->id,
            'brand_id' => $lead->brand_id,
            'created_by_id' => $user->id,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'description' => $data['description'] ?? null,
            'line_items' => $data['line_items'] ?? null,
            'status' => 'sent',
        ]);
        ActivityLog::record($user->id, 'invoice.create', 'Invoice', $invoice->id, ['number' => $invoice->number, 'amount' => $invoice->amount]);

        return response()->json(['id' => $invoice->id, 'number' => $invoice->number], 201);
    }

    /** Mark an invoice paid, cancelled, or back to sent */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageInvoices(), 403);
        $invoice = Invoice::query()->visibleTo($user)->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(Invoice::STATUSES)]]);

        if ($invoice->status !== $data['status']) {
            $from = $invoice->status;
            $invoice->update(['status' => $data['status']]);
            ActivityLog::record($user->id, 'invoice.status', 'Invoice', $invoice->id, ['from' => $from, 'to' => $data['status']]);
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

            $invoice->payments()->create([
                'amount' => $data['amount'],
                'method' => $data['method'],
                'external_ref' => $data['external_ref'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_at' => now(),
            ]);
            $paid = round($paid + (float) $data['amount'], 2);
            if ($paid >= (float) $invoice->amount && $invoice->status !== 'paid') {
                $invoice->update(['status' => 'paid']);
            }

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

        return $this->render($invoice)->download("{$invoice->number}.pdf");
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
                ->get(['id', 'number', 'amount', 'currency', 'status', 'brand_id', 'lead_id', 'created_at']),
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
            $zip->addFromString("{$invoice->number}.pdf", $this->render($invoice)->output());
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

    private function render(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $items = $invoice->line_items ?: [['label' => $invoice->description ?: 'Services', 'amount' => (float) $invoice->amount]];

        // Subsetting embeds only the glyphs used: Unicode-safe names, ~30 KB instead of ~900 KB
        return Pdf::setOption('enable_font_subsetting', true)
            ->loadView('pdf.invoice', ['invoice' => $invoice, 'items' => $items]);
    }
}
