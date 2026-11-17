<?php

namespace App\Http\Controllers;

use App\Actions\Orders\BuildQuoteDocument;
use App\Models\Order;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AdminQuoteDocumentController
{
    public function __invoke(Order $order, int $revision, BuildQuoteDocument $documents): Response
    {
        Gate::authorize('view', $order);
        $snapshot = $order->quoteSnapshots()->where('revision', $revision)->firstOrFail();

        return response()->view('documents.quote', ['document' => $documents->handle($order, $snapshot)], 200, [
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
