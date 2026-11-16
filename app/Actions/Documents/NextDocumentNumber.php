<?php

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentNumber;
use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class NextDocumentNumber
{
    public function handle(DocumentKind $kind, ?CarbonInterface $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers require a database transaction.');
        }
        $year = ($at ?? now())->copy()->setTimezone(config('app.timezone'))->year;
        DocumentSequence::query()->insertOrIgnore([
            'kind' => $kind->value, 'year' => $year, 'next_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DocumentSequence::query()->where('kind', $kind->value)->where('year', $year)->lockForUpdate()->firstOrFail();
        $number = $sequence->next_number;
        $formatted = DocumentNumber::format($kind->prefix(), $year, $number);
        $sequence->update(['next_number' => $number + 1]);

        return $formatted;
    }
}
