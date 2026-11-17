<?php

namespace App\Http\Controllers;

use App\Actions\Returns\BuildCreditNoteDocument;
use App\Models\CreditNote;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AdminCreditNoteDocumentController
{
    public function __invoke(CreditNote $creditNote, BuildCreditNoteDocument $documents): Response
    {
        Gate::authorize('view', $creditNote);

        return response()->view('documents.credit-note', ['document' => $documents->handle($creditNote)], 200, [
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
