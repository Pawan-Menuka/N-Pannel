<?php

namespace App\Actions\Reports;

use App\Domain\Reports\ReportResult;
use Illuminate\Support\Facades\DB;

class StockValuation
{
    public function run(): ReportResult
    {
        $stock = DB::table('stock_levels')->selectRaw('variant_id, SUM(qty) AS qty')->groupBy('variant_id');
        $variants = DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->leftJoinSub($stock, 'stock', 'stock.variant_id', '=', 'variants.id')->where('variants.track_stock', true)
            ->select('variants.id', 'variants.sku', 'variants.last_cost_cents', 'variants.reorder_level')
            ->selectRaw("COALESCE(products.title->>'en', '') || ' — ' || COALESCE(NULLIF(variants.label, ''), variants.sku) AS description, COALESCE(stock.qty, 0) AS qty")
            ->orderBy('variants.sku')->get();
        $totals = ['qty' => 0, 'value_cents' => 0, 'unknown_cost_count' => 0];
        /** @var list<array{variant_id: int, sku: string, description: string, qty: int, reorder_level: ?int, last_cost_cents: ?int, value_cents: ?int, unknown_cost: bool}> $rows */
        $rows = [];
        foreach ($variants as $variant) {
            $qty = (int) $variant->qty;
            $cost = $variant->last_cost_cents === null ? null : (int) $variant->last_cost_cents;
            $value = $cost === null ? null : $qty * $cost;
            $rows[] = [
                'variant_id' => (int) $variant->id, 'sku' => (string) $variant->sku, 'description' => (string) $variant->description,
                'qty' => $qty, 'reorder_level' => $variant->reorder_level === null ? null : (int) $variant->reorder_level,
                'last_cost_cents' => $cost, 'value_cents' => $value, 'unknown_cost' => $cost === null,
            ];
            $totals['qty'] += $qty;
            $totals['value_cents'] += $value ?? 0;
            $totals['unknown_cost_count'] += $cost === null ? 1 : 0;
        }

        return new ReportResult($rows, $totals, [
            'sku' => 'SKU', 'description' => 'Description', 'qty' => 'Quantity', 'last_cost_cents' => 'Last cost',
            'value_cents' => 'Stock value', 'unknown_cost' => 'Unknown cost',
        ], ['last_cost_cents', 'value_cents']);
    }
}
