<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\BuildInvoiceDocument;
use App\Models\Invoice;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AdminInvoiceDocumentController
{
    public function __invoke(Invoice $invoice, BuildInvoiceDocument $documents): Response
    {
        Gate::authorize('view', $invoice);

        return response()->view('documents.invoice', ['document' => $documents->handle($invoice)], 200, [
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
