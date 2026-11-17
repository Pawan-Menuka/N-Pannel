<?php

namespace App\Actions\Orders;

use App\Actions\Stock\ReserveStock;
use App\Domain\Stock\InsufficientStock;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\OrderLineKind;
use App\Enums\StockReason;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** The caller must hold the order lock in a transaction; stock is locked by ascending variant ID. */
final class ReduceOrderStock
{
    public function __construct(private ReserveStock $stock) {}

    /** @return list<string> */
    public function handle(Order $order, StockReason $reason, User $by, ?string $overrideReason): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reducing order stock requires a database transaction and order lock.');
        }
        /** @var array<int, array{variant: Variant, quantities: non-empty-list<int>}> $stockLines */
        $stockLines = [];
        foreach ($order->lines()->with('variant')->where('kind', OrderLineKind::Product)->get() as $line) {
            $variant = $line->variant;
            if ($variant === null) {
                throw ValidationException::withMessages(['lines' => 'The variant for '.$line->description.' no longer exists.']);
            }
            if (! $variant->track_stock) {
                continue;
            }
            if (isset($stockLines[$variant->id])) {
                $stockLines[$variant->id]['quantities'][] = $line->qty;
            } else {
                $stockLines[$variant->id] = ['variant' => $variant, 'quantities' => [$line->qty]];
            }
        }
        ksort($stockLines, SORT_NUMERIC);
        $shortages = [];
        $overrides = [];
        try {
            foreach ($stockLines as $line) {
                $variant = $line['variant'];
                try {
                    $this->stock->handleAggregated($variant, $line['quantities'], $reason, $order, $by, location: $order->location);
                } catch (InsufficientStock $exception) {
                    if ($overrideReason === null) {
                        $shortages[] = $variant->sku.': wanted '.$exception->requested.', in stock '.$exception->available;
                    } else {
                        $this->stock->handleAggregated($variant, $line['quantities'], $reason, $order, $by, $overrideReason, $order->location);
                        $overrides[] = $variant->sku;
                    }
                }
            }
        } catch (StockLimitExceeded $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }
        if ($shortages !== []) {
            throw ValidationException::withMessages(['lines' => 'Stock is short. '.implode('; ', $shortages)]);
        }

        return $overrides;
    }
}
