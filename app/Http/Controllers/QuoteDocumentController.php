<?php

namespace App\Http\Controllers;

use App\Actions\Orders\BuildQuoteDocument;
use App\Enums\OrderSource;
use App\Models\Order;
use Illuminate\Http\Response;

final class QuoteDocumentController
{
    public function __invoke(string $reference, string $token, BuildQuoteDocument $documents): Response
    {
        $order = Order::query()->where('source', OrderSource::Quote)->where('reference', $reference)->first();
        abort_if($order === null || $order->token_hash === null || ! hash_equals($order->token_hash, hash('sha256', $token)), 404);

        $snapshot = $order->quoteSnapshots()->orderByDesc('revision')->firstOrFail();

        return response()->view('documents.quote', ['document' => $documents->handle($order, $snapshot)], 200, [
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
