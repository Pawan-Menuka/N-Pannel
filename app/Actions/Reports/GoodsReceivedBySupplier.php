<?php

namespace App\Actions\Reports;

use App\Domain\Reports\DateRange;
use App\Domain\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GoodsReceivedBySupplier
{
    public function run(CarbonImmutable $from, CarbonImmutable $to): ReportResult
    {
        [$start, $end] = (new DateRange($from, $to))->bounds();
        $units = DB::table('goods_receipt_lines')->selectRaw('goods_receipt_id, SUM(qty) AS units')->groupBy('goods_receipt_id');
        $receipts = DB::table('goods_receipts as receipt')->join('suppliers', 'suppliers.id', '=', 'receipt.supplier_id')
            ->leftJoinSub($units, 'lines', 'lines.goods_receipt_id', '=', 'receipt.id')
            ->where('receipt.status', 'posted')->where('receipt.posted_at', '>=', $start)->where('receipt.posted_at', '<', $end)
            ->selectRaw('suppliers.id AS supplier_id, suppliers.name AS supplier, COUNT(*) AS receipts_count, COALESCE(SUM(lines.units), 0) AS units, SUM(receipt.total_cost_cents) AS cost_cents')
            ->groupBy('suppliers.id', 'suppliers.name')->orderByDesc('cost_cents')->orderBy('suppliers.id')->get();
        /** @var list<array{supplier_id: int, supplier: string, receipts_count: int, units: int, cost_cents: int}> $rows */
        $rows = [];
        $totals = ['receipts_count' => 0, 'units' => 0, 'cost_cents' => 0];
        foreach ($receipts as $receipt) {
            $row = [
                'supplier_id' => (int) $receipt->supplier_id, 'supplier' => (string) $receipt->supplier,
                'receipts_count' => (int) $receipt->receipts_count, 'units' => (int) $receipt->units, 'cost_cents' => (int) $receipt->cost_cents,
            ];
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) $row[$key];
            }
            $rows[] = $row;
        }

        return new ReportResult($rows, $totals, [
            'supplier' => 'Supplier', 'receipts_count' => 'Receipts', 'units' => 'Units', 'cost_cents' => 'Total cost',
        ], ['cost_cents']);
    }
}
