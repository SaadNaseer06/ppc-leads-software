<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function pdf(Request $request, int $id): Response
    {
        $invoice = Invoice::query()->visibleTo($request->user())->with(['brand', 'lead', 'createdBy'])->findOrFail($id);

        return $this->render($invoice)->download("{$invoice->number}.pdf");
    }

    /** Finance page: recent payments and invoices */
    public function finance(Request $request): JsonResponse
    {
        abort_unless($request->user()->canViewFinance(), 403);

        return response()->json([
            'transactions' => PaymentTransaction::with(['invoice:id,number,currency,brand_id,lead_id', 'invoice.brand:id,name', 'invoice.lead:id,name'])
                ->latest('recorded_at')->limit(self::LIMIT)->get(),
            'invoices' => Invoice::with('brand:id,name')->latest()->limit(self::LIMIT)
                ->get(['id', 'number', 'amount', 'currency', 'status', 'brand_id']),
            'limit' => self::LIMIT,
        ]);
    }

    public function bulkPdf(Request $request): BinaryFileResponse|JsonResponse
    {
        abort_unless($request->user()->canViewFinance(), 403);
        $ids = array_slice(array_filter((array) $request->input('invoice_ids', []), 'is_numeric'), 0, self::MAX_ZIP);
        $invoices = Invoice::with(['brand', 'lead', 'createdBy'])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->latest()->limit(self::MAX_ZIP)->get();
        if ($invoices->isEmpty()) {
            return response()->json(['error' => 'No invoices found'], 404);
        }

        $path = tempnam(sys_get_temp_dir(), 'inv').'.zip';
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

        $leads = Lead::whereIn('brand_id', $ids)->groupBy('brand_id')->selectRaw('brand_id, COUNT(*) AS n')->pluck('n', 'brand_id');
        $converted = Lead::whereIn('brand_id', $ids)->where('status', LeadStatus::Converted->value)
            ->groupBy('brand_id')->selectRaw('brand_id, COUNT(*) AS n')->pluck('n', 'brand_id');
        $invoiced = Invoice::whereIn('brand_id', $ids)->where('status', '!=', 'cancelled')->groupBy('brand_id')
            ->select('brand_id', DB::raw('COUNT(*) AS n'), DB::raw('SUM(amount) AS total'))->get()->keyBy('brand_id');
        $paid = Invoice::whereIn('brand_id', $ids)->where('status', 'paid')->groupBy('brand_id')
            ->selectRaw('brand_id, SUM(amount) AS total')->pluck('total', 'brand_id');

        return response()->json(['reports' => $brands->map(fn (Brand $b) => [
            'brand_id' => $b->id,
            'brand_name' => $b->name,
            'lead_count' => (int) ($leads[$b->id] ?? 0),
            'converted_count' => (int) ($converted[$b->id] ?? 0),
            'invoice_count' => (int) ($invoiced[$b->id]->n ?? 0),
            'total_amount' => (float) ($invoiced[$b->id]->total ?? 0),
            'paid_amount' => (float) ($paid[$b->id] ?? 0),
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
