<?php

namespace App\Actions\Reports;

use App\Domain\Customers\Balance;
use App\Domain\Stock\StockLevelStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardData
{
    /** @return array{net_cents: int, orders_count: int, payments_cents: int} */
    public function today(): array
    {
        $today = CarbonImmutable::now('Asia/Colombo');
        $totals = app(SalesByDay::class)->run($today, $today)->totals;

        return [
            'net_cents' => (int) $totals['net_cents'],
            'orders_count' => (int) $totals['orders_count'],
            'payments_cents' => (int) $totals['payments_cents'],
        ];
    }

    /** @return array{balance_cents: int, customers_count: int} */
    public function moneyOwed(): array
    {
        $totals = app(MoneyOwed::class)->run()->totals;

        return ['balance_cents' => (int) $totals['balance_cents'], 'customers_count' => (int) $totals['customers_count']];
    }

    public function needsAttention(): int
    {
        return Order::query()->where('needs_attention', true)->count();
    }

    /** @return list<array{id: int, reference: string, customer: string, balance_cents: int, confirmed_at: ?string}> */
    public function acceptedWithBalance(): array
    {
        return Order::query()->with('customer')
            ->whereIn('status', Balance::statuses())
            ->whereRaw('total_cents - returned_cents > paid_cents')
            ->orderByDesc('confirmed_at')->orderByDesc('id')->limit(10)->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'customer' => $this->customerName($order),
                'balance_cents' => $order->balanceCents(),
                'confirmed_at' => $order->getRawOriginal('confirmed_at') === null ? null : (string) $order->getRawOriginal('confirmed_at'),
            ])->all();
    }

    /** @return list<array{id: int, reference: string, customer: string, total_cents: int, valid_until: ?string}> */
    public function quotesExpiringSoon(): array
    {
        $now = CarbonImmutable::now('Asia/Colombo');

        return Order::query()->with('customer')->where('status', OrderStatus::Quoted)
            ->whereBetween('valid_until', [$now, $now->addDays(3)])
            ->orderBy('valid_until')->orderBy('id')->limit(10)->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'customer' => $this->customerName($order),
                'total_cents' => $order->total_cents,
                'valid_until' => $order->getRawOriginal('valid_until') === null ? null : (string) $order->getRawOriginal('valid_until'),
            ])->all();
    }

    /** @return list<array{variant_id: int, sku: string, description: string, qty: int, reorder_level: int}> */
    public function lowStock(): array
    {
        $stock = DB::table('stock_levels')->selectRaw('variant_id, SUM(qty) AS qty')->groupBy('variant_id');
        $variants = DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->leftJoinSub($stock, 'stock', 'stock.variant_id', '=', 'variants.id')
            ->where('variants.track_stock', true)->where('variants.is_active', true)
            ->whereNotNull('variants.reorder_level')->whereRaw('COALESCE(stock.qty, 0) <= variants.reorder_level')
            ->select('variants.id', 'variants.sku', 'variants.reorder_level')
            ->selectRaw("COALESCE(products.title->>'en', '') || ' — ' || COALESCE(NULLIF(variants.label, ''), variants.sku) AS description, COALESCE(stock.qty, 0) AS qty")
            ->orderByRaw('variants.reorder_level - COALESCE(stock.qty, 0) DESC')
            ->orderBy('variants.sku')->orderBy('variants.id')->limit(10)->get();
        $rows = [];
        foreach ($variants as $variant) {
            $qty = (int) $variant->qty;
            $reorderLevel = (int) $variant->reorder_level;
            if (! StockLevelStatus::isLow($qty, $reorderLevel)) {
                continue;
            }
            $rows[] = [
                'variant_id' => (int) $variant->id, 'sku' => (string) $variant->sku,
                'description' => (string) $variant->description, 'qty' => $qty, 'reorder_level' => $reorderLevel,
            ];
        }

        return $rows;
    }

    private function customerName(Order $order): string
    {
        $customer = $order->customer;
        if ($customer === null || $customer->anonymised_at !== null || $customer->merged_into_id !== null) {
            return 'Removed customer';
        }

        return $customer->name;
    }
}
