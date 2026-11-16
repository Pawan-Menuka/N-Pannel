<?php

namespace App\Actions\Purchasing;

use App\Domain\Purchasing\AmountTooLarge;
use App\Domain\Purchasing\ReceiptTotals;
use App\Models\GoodsReceipt;
use Illuminate\Validation\ValidationException;

final class RecalculateReceiptTotal
{
    public function handle(GoodsReceipt $receipt): void
    {
        $amounts = [];
        foreach ($receipt->lines()->get() as $line) {
            $amounts[] = $line->line_total_cents;
        }
        try {
            $receipt->update(['total_cost_cents' => ReceiptTotals::sum($amounts)]);
        } catch (AmountTooLarge $exception) {
            throw ValidationException::withMessages(['receipt' => $exception->getMessage()]);
        }
    }
}
