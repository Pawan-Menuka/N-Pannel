<?php

namespace App\Actions\Reports;

use App\Domain\Customers\Balance;
use App\Domain\Reports\DateRange;
use App\Domain\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SalesByDay
{
    public function run(CarbonImmutable $from, CarbonImmutable $to): ReportResult
    {
        $range = new DateRange($from, $to);
        [$start, $end] = $range->bounds();
        $orderAggregates = DB::table('orders')->whereIn('status', Balance::statuses())
            ->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $end)
            ->selectRaw('DATE(confirmed_at) AS day, COUNT(*) AS orders_count, SUM(total_cents) AS gross_cents')
            ->groupByRaw('DATE(confirmed_at)')->get();
        $returnAggregates = DB::table('sales_returns')->join('orders', 'orders.id', '=', 'sales_returns.order_id')
            ->whereIn('orders.status', Balance::statuses())
            ->where('sales_returns.created_at', '>=', $start)->where('sales_returns.created_at', '<', $end)
            ->selectRaw('DATE(sales_returns.created_at) AS day, SUM(sales_returns.total_cents) AS returns_cents')
            ->groupByRaw('DATE(sales_returns.created_at)')->get();
        $paymentAggregates = DB::table('payments')->where('status', 'confirmed')
            ->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $end)
            ->selectRaw("DATE(confirmed_at) AS day, SUM(CASE WHEN kind = 'payment' THEN amount_cents ELSE -amount_cents END) AS payments_cents")
            ->groupByRaw('DATE(confirmed_at)')->get();
        $creditNoteAggregates = DB::table('credit_notes')->join('sales_returns', 'sales_returns.id', '=', 'credit_notes.sales_return_id')
            ->join('orders', 'orders.id', '=', 'sales_returns.order_id')->whereIn('orders.status', Balance::statuses())
            ->where('credit_notes.status', 'issued')->where('sales_returns.created_at', '>=', $start)->where('sales_returns.created_at', '<', $end)
            ->selectRaw('DATE(sales_returns.created_at) AS day, COUNT(*) AS credit_notes_count, SUM(credit_notes.total_cents) AS credit_notes_cents')
            ->groupByRaw('DATE(sales_returns.created_at)')->get();

        /** @var array<string, array{orders_count: int, gross_cents: int}> $orders */
        $orders = [];
        foreach ($orderAggregates as $aggregate) {
            $orders[(string) $aggregate->day] = ['orders_count' => (int) $aggregate->orders_count, 'gross_cents' => (int) $aggregate->gross_cents];
        }
        /** @var array<string, int> $returns */
        $returns = [];
        foreach ($returnAggregates as $aggregate) {
            $returns[(string) $aggregate->day] = (int) $aggregate->returns_cents;
        }
        /** @var array<string, int> $payments */
        $payments = [];
        foreach ($paymentAggregates as $aggregate) {
            $payments[(string) $aggregate->day] = (int) $aggregate->payments_cents;
        }
        /** @var array<string, array{credit_notes_count: int, credit_notes_cents: int}> $creditNotes */
        $creditNotes = [];
        foreach ($creditNoteAggregates as $aggregate) {
            $creditNotes[(string) $aggregate->day] = ['credit_notes_count' => (int) $aggregate->credit_notes_count, 'credit_notes_cents' => (int) $aggregate->credit_notes_cents];
        }

        $totals = array_fill_keys(['orders_count', 'gross_cents', 'returns_cents', 'net_cents', 'payments_cents', 'credit_notes_count', 'credit_notes_cents'], 0);
        /** @var list<array{date: string, orders_count: int, gross_cents: int, returns_cents: int, net_cents: int, payments_cents: int, credit_notes_count: int, credit_notes_cents: int}> $rows */
        $rows = [];
        foreach ($range->days() as $day) {
            $dayOrders = $orders[$day] ?? ['orders_count' => 0, 'gross_cents' => 0];
            $dayCreditNotes = $creditNotes[$day] ?? ['credit_notes_count' => 0, 'credit_notes_cents' => 0];
            $row = [
                'date' => $day,
                'orders_count' => $dayOrders['orders_count'],
                'gross_cents' => $dayOrders['gross_cents'],
                'returns_cents' => $returns[$day] ?? 0,
                'payments_cents' => $payments[$day] ?? 0,
                'credit_notes_count' => $dayCreditNotes['credit_notes_count'],
                'credit_notes_cents' => $dayCreditNotes['credit_notes_cents'],
            ];
            $row['net_cents'] = $row['gross_cents'] - $row['returns_cents'];
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) $row[$key];
            }
            $rows[] = $row;
        }

        return new ReportResult($rows, $totals, [
            'date' => 'Date', 'orders_count' => 'Orders', 'gross_cents' => 'Gross sales', 'returns_cents' => 'Returns',
            'net_cents' => 'Net sales', 'payments_cents' => 'Payments received',
            'credit_notes_count' => 'Credit notes', 'credit_notes_cents' => 'Credit notes value (included in returns)',
        ], ['gross_cents', 'returns_cents', 'net_cents', 'payments_cents', 'credit_notes_cents']);
    }
}
