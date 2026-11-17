<?php

namespace App\Http\Controllers;

use App\Actions\Payments\BuildReceiptDocument;
use App\Models\Payment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AdminReceiptDocumentController
{
    public function __invoke(Payment $payment, BuildReceiptDocument $documents): Response
    {
        Gate::authorize('view', $payment);

        return response()->view('documents.receipt', ['document' => $documents->handle($payment)], 200, [
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
