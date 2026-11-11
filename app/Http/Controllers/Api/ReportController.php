<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\SalesReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private SalesReport $report) {}

    /** Sales analytics; every number respects the filters and the user's brands */
    public function sales(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewReports(), 403);

        return response()->json($this->report->data($user, $this->report->filters($request->query())));
    }

    /** The invoices behind the report, as CSV */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->canViewReports(), 403);
        $filters = $this->report->filters($request->query());
        ActivityLog::record($user->id, 'report.export', 'Invoice', null, $filters);

        return response()->streamDownload(function () use ($user, $filters) {
            @set_time_limit(0);
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Invoice', 'Brand', 'Client', 'Sales rep', 'Channel', 'Campaign', 'Status', 'Currency', 'Subtotal', 'Discount', 'Tax', 'Total', 'Paid', 'Balance', 'Due date']);

            $this->report->rows($user, $filters, function ($row, string $brand, string $rep) use ($out) {
                $paid = $row->status === 'paid' ? (float) $row->amount : min((float) $row->amount, (float) $row->paid);
                $cells = [
                    substr((string) $row->created_at, 0, 10), $row->number, $brand, $row->lead_name, $rep, $row->channel,
                    $row->campaign_label, $row->status === 'sent' ? 'unpaid' : $row->status, $row->currency,
                    $row->subtotal, $row->discount, $row->tax_amount, $row->amount,
                    number_format($paid, 2, '.', ''), number_format((float) $row->amount - $paid, 2, '.', ''), $row->due_date,
                ];
                // Neutralise spreadsheet formula injection in anything a visitor could have typed
                fputcsv($out, array_map(fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) ? "'{$cell}" : $cell, $cells));
            });

            fclose($out);
        }, 'sales-'.$filters['from'].'-to-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
