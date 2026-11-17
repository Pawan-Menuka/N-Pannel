<?php

namespace App\Actions\Reports;

use App\Domain\Customers\Balance;
use App\Domain\Reports\DateRange;
use App\Domain\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SalesByProduct
{
    public function run(CarbonImmutable $from, CarbonImmutable $to): ReportResult
    {
        [$start, $end] = (new DateRange($from, $to))->bounds();
        $sold = DB::table('order_lines as line')->join('orders', 'orders.id', '=', 'line.order_id')
            ->where('line.kind', 'product')->whereIn('orders.status', Balance::statuses())
            ->where('orders.confirmed_at', '>=', $start)->where('orders.confirmed_at', '<', $end)
            ->selectRaw('line.variant_id, line.sku, line.description, line.qty AS qty_sold, line.line_total_cents AS gross_cents, 0 AS qty_returned, 0 AS returned_cents, COALESCE(line.qty * line.unit_cost_cents, 0) AS cost_cents, 0 AS returned_cost_cents, CASE WHEN line.unit_cost_cents IS NULL THEN 1 ELSE 0 END AS unknown_cost');
        $returned = DB::table('sales_return_lines as returned')->join('sales_returns', 'sales_returns.id', '=', 'returned.sales_return_id')
            ->join('orders', 'orders.id', '=', 'sales_returns.order_id')->whereIn('orders.status', Balance::statuses())
            ->join('order_lines as line', 'line.id', '=', 'returned.order_line_id')
            ->where('line.kind', 'product')->where('sales_returns.created_at', '>=', $start)->where('sales_returns.created_at', '<', $end)
            ->selectRaw('line.variant_id, line.sku, line.description, 0 AS qty_sold, 0 AS gross_cents, returned.qty AS qty_returned, returned.line_total_cents AS returned_cents, 0 AS cost_cents, COALESCE(returned.qty * line.unit_cost_cents, 0) AS returned_cost_cents, CASE WHEN line.unit_cost_cents IS NULL THEN 1 ELSE 0 END AS unknown_cost');
        $aggregates = DB::query()->fromSub($sold->unionAll($returned), 'activity')
            ->selectRaw('variant_id, MAX(sku) AS sku, MAX(description) AS description, SUM(qty_sold) AS qty_sold, SUM(gross_cents) AS gross_cents, SUM(qty_returned) AS qty_returned, SUM(returned_cents) AS returned_cents, SUM(cost_cents) AS cost_cents, SUM(returned_cost_cents) AS returned_cost_cents, MAX(unknown_cost) AS unknown_cost')
            ->groupBy('variant_id')->groupByRaw("CASE WHEN variant_id IS NULL THEN sku ELSE '' END")
            ->orderByRaw('SUM(gross_cents) - SUM(returned_cents) DESC')->orderBy('variant_id')->get();

        $keys = ['qty_sold', 'gross_cents', 'qty_returned', 'returned_cents', 'net_qty', 'net_cents', 'cost_cents', 'returned_cost_cents', 'net_cost_cents', 'margin_cents'];
        $totals = array_fill_keys($keys, 0);
        /** @var list<array{variant_id: ?int, sku: string, description: string, qty_sold: int, gross_cents: int, qty_returned: int, returned_cents: int, net_qty: int, net_cents: int, cost_cents: int, returned_cost_cents: int, net_cost_cents: ?int, unknown_cost: bool, margin_cents: ?int}> $rows */
        $rows = [];
        $hasUnknownCost = false;
        foreach ($aggregates as $aggregate) {
            $unknownCost = (int) $aggregate->unknown_cost > 0;
            $hasUnknownCost = $hasUnknownCost || $unknownCost;
            $row = [
                'variant_id' => $aggregate->variant_id === null ? null : (int) $aggregate->variant_id,
                'sku' => (string) $aggregate->sku,
                'description' => (string) $aggregate->description,
                'qty_sold' => (int) $aggregate->qty_sold,
                'gross_cents' => (int) $aggregate->gross_cents,
                'qty_returned' => (int) $aggregate->qty_returned,
                'returned_cents' => (int) $aggregate->returned_cents,
                'net_qty' => (int) $aggregate->qty_sold - (int) $aggregate->qty_returned,
                'net_cents' => (int) $aggregate->gross_cents - (int) $aggregate->returned_cents,
                'cost_cents' => (int) $aggregate->cost_cents,
                'returned_cost_cents' => (int) $aggregate->returned_cost_cents,
                'net_cost_cents' => $unknownCost ? null : (int) $aggregate->cost_cents - (int) $aggregate->returned_cost_cents,
                'unknown_cost' => $unknownCost,
            ];
            $row['margin_cents'] = $row['net_cost_cents'] === null ? null : $row['net_cents'] - $row['net_cost_cents'];
            foreach ($keys as $key) {
                $totals[$key] += (int) ($row[$key] ?? 0);
            }
            $rows[] = $row;
        }
        $totals['unknown_cost'] = $hasUnknownCost;
        if ($hasUnknownCost) {
            $totals['net_cost_cents'] = null;
            $totals['margin_cents'] = null;
        }

        return new ReportResult($rows, $totals, [
            'sku' => 'SKU', 'description' => 'Description', 'qty_sold' => 'Quantity sold', 'gross_cents' => 'Gross value',
            'qty_returned' => 'Quantity returned', 'returned_cents' => 'Returned value', 'net_qty' => 'Net quantity',
            'net_cents' => 'Net value', 'cost_cents' => 'Known cost at sale', 'returned_cost_cents' => 'Known returned cost',
            'net_cost_cents' => 'Net cost', 'unknown_cost' => 'Unknown cost', 'margin_cents' => 'Margin',
        ], ['gross_cents', 'returned_cents', 'net_cents', 'cost_cents', 'returned_cost_cents', 'net_cost_cents', 'margin_cents']);
    }
}
