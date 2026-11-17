<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\BuildInvoiceDocument;
use App\Enums\DocumentStatus;
use App\Models\Invoice;
use Illuminate\Http\Response;

final class InvoiceDocumentController
{
    public function __invoke(string $number, string $token, BuildInvoiceDocument $documents): Response
    {
        $invoice = Invoice::query()->where('number', $number)->where('status', DocumentStatus::Issued)->first();
        abort_if($invoice === null || $invoice->token_hash === null || ! hash_equals($invoice->token_hash, hash('sha256', $token)), 404);

        return response()->view('documents.invoice', ['document' => $documents->handle($invoice)], 200, [
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
