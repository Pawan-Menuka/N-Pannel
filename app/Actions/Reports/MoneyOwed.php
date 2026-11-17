<?php

namespace App\Actions\Reports;

use App\Domain\Customers\Balance;
use App\Domain\Reports\AgeBucket;
use App\Domain\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MoneyOwed
{
    public function run(?CarbonImmutable $asOf = null): ReportResult
    {
        $today = ($asOf ?? CarbonImmutable::now('Asia/Colombo'))->setTimezone('Asia/Colombo')->toDateString();
        $orders = DB::table('orders')->whereIn('status', Balance::statuses())
            ->whereRaw('total_cents - returned_cents > paid_cents')
            ->selectRaw('customer_id, confirmed_at, GREATEST(total_cents - returned_cents - paid_cents, 0) AS balance_cents')
            ->selectRaw('GREATEST(CAST(? AS date) - CAST(confirmed_at AS date), 0) AS age_days', [$today]);
        $customers = DB::query()->fromSub($orders, 'owing')->leftJoin('customers', 'customers.id', '=', 'owing.customer_id')
            ->selectRaw("customers.id AS customer_id, CASE WHEN customers.id IS NULL OR customers.anonymised_at IS NOT NULL OR customers.merged_into_id IS NOT NULL THEN 'Removed customer' ELSE customers.name END AS name")
            ->selectRaw('CASE WHEN customers.anonymised_at IS NOT NULL OR customers.merged_into_id IS NOT NULL THEN NULL ELSE customers.phone_e164 END AS phone')
            ->selectRaw('COUNT(*) AS orders_owing, SUM(owing.balance_cents) AS balance_cents, MIN(CAST(owing.confirmed_at AS date)) AS oldest_order_date');
        foreach (AgeBucket::cases() as $bucket) {
            $predicate = $bucket->maximumDays() === null ? 'owing.age_days >= ?' : 'owing.age_days BETWEEN ? AND ?';
            $bindings = $bucket->maximumDays() === null ? [$bucket->minimumDays()] : [$bucket->minimumDays(), $bucket->maximumDays()];
            $customers->selectRaw('SUM(CASE WHEN '.$predicate.' THEN owing.balance_cents ELSE 0 END) AS '.$bucket->column(), $bindings);
        }
        $customers->groupBy('customers.id')->orderByDesc('balance_cents')->orderBy('customers.id');
        $totals = ['customers_count' => 0, 'orders_owing' => 0, 'balance_cents' => 0];
        foreach (AgeBucket::cases() as $bucket) {
            $totals[$bucket->column()] = 0;
        }
        /** @var list<array{customer_id: ?int, name: string, phone: ?string, orders_owing: int, balance_cents: int, oldest_order_date: ?string, age_0_30_cents: int, age_31_60_cents: int, age_61_90_cents: int, age_90_plus_cents: int}> $rows */
        $rows = [];
        foreach ($customers->get() as $customer) {
            $row = [
                'customer_id' => $customer->customer_id === null ? null : (int) $customer->customer_id, 'name' => (string) $customer->name,
                'phone' => $customer->phone === null ? null : (string) $customer->phone,
                'orders_owing' => (int) $customer->orders_owing, 'balance_cents' => (int) $customer->balance_cents,
                'oldest_order_date' => $customer->oldest_order_date === null ? null : (string) $customer->oldest_order_date,
            ];
            foreach (AgeBucket::cases() as $bucket) {
                $row[$bucket->column()] = (int) $customer->{$bucket->column()};
            }
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($key === 'customers_count' ? 1 : (int) $row[$key]);
            }
            $rows[] = $row;
        }

        return new ReportResult($rows, $totals, [
            'name' => 'Customer', 'phone' => 'Phone', 'orders_owing' => 'Orders owing', 'balance_cents' => 'Balance',
            'oldest_order_date' => 'Oldest owing order', 'age_0_30_cents' => '0–30 days', 'age_31_60_cents' => '31–60 days',
            'age_61_90_cents' => '61–90 days', 'age_90_plus_cents' => '90+ days (over 90)',
        ], ['balance_cents', 'age_0_30_cents', 'age_31_60_cents', 'age_61_90_cents', 'age_90_plus_cents']);
    }
}
