<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

class InvoicePdf
{
    public function make(Invoice $invoice): DomPdf
    {
        // Subsetting embeds only the glyphs used: Unicode-safe names, ~30 KB instead of ~900 KB
        return Pdf::setOption('enable_font_subsetting', true)
            ->loadView('pdf.invoice', ['invoice' => $invoice, 'items' => $invoice->items()]);
    }
}
