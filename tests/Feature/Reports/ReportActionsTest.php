<?php

use App\Actions\Reports\GoodsReceivedBySupplier;
use App\Actions\Reports\MoneyOwed;
use App\Actions\Reports\SalesByDay;
use App\Actions\Reports\SalesByProduct;
use App\Actions\Reports\StockValuation;
use App\Enums\DocumentStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\Variant;
use Carbon\CarbonImmutable;

it('counts only accepted statuses on their confirmed Colombo date and fills empty days', function () {
    foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed] as $status) {
        Order::factory()->create([
            'status' => $status, 'total_cents' => 1000,
            'created_at' => '2026-10-01 12:00:00', 'confirmed_at' => '2026-11-01 23:59:59',
        ]);
    }
    foreach ([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Cancelled, OrderStatus::Expired] as $status) {
        Order::factory()->create(['status' => $status, 'total_cents' => 99000, 'confirmed_at' => '2026-11-01 12:00:00']);
    }
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'total_cents' => 2000, 'confirmed_at' => '2026-11-02 00:00:00']);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'total_cents' => 9000, 'confirmed_at' => '2026-10-31 23:59:59']);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'total_cents' => 9000, 'confirmed_at' => '2026-11-04 00:00:00']);

    $report = app(SalesByDay::class)->run(CarbonImmutable::parse('2026-11-01', 'Asia/Colombo'), CarbonImmutable::parse('2026-11-03', 'Asia/Colombo'));

    expect(array_column($report->rows, 'date'))->toBe(['2026-11-01', '2026-11-02', '2026-11-03']);
    expect(array_column($report->rows, 'orders_count'))->toBe([5, 1, 0]);
    expect(array_column($report->rows, 'gross_cents'))->toBe([5000, 2000, 0]);
    expect($report->totals)->toMatchArray(['orders_count' => 6, 'gross_cents' => 7000, 'net_cents' => 7000]);
});

it('dates returns and net confirmed payments independently and does not subtract credit notes twice', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'total_cents' => 10000, 'returned_cents' => 2000, 'confirmed_at' => '2026-11-01 23:59:59']);
    $return = SalesReturn::factory()->for($order)->create(['total_cents' => 2000, 'refund_cents' => 500, 'created_at' => '2026-11-02 00:00:00']);
    $invoice = Invoice::factory()->for($order)->create();
    CreditNote::factory()->for($invoice)->for($order)->create([
        'customer_id' => $order->customer_id, 'sales_return_id' => $return->id, 'total_cents' => 2000,
        'issued_on' => '2026-11-03', 'status' => DocumentStatus::Issued,
    ]);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'kind' => PaymentKind::Payment, 'amount_cents' => 8000, 'confirmed_at' => '2026-11-01 23:59:59']);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'kind' => PaymentKind::Refund, 'amount_cents' => 500, 'confirmed_at' => '2026-11-02 00:00:00']);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Pending, 'amount_cents' => 99999, 'confirmed_at' => '2026-11-02 12:00:00']);
    Payment::factory()->for($order)->create(['status' => PaymentStatus::Confirmed, 'amount_cents' => 99999, 'confirmed_at' => '2026-11-03 00:00:00']);

    $report = app(SalesByDay::class)->run(CarbonImmutable::parse('2026-11-01', 'Asia/Colombo'), CarbonImmutable::parse('2026-11-02', 'Asia/Colombo'));

    expect($report->rows[0])->toMatchArray(['gross_cents' => 10000, 'returns_cents' => 0, 'net_cents' => 10000, 'payments_cents' => 8000]);
    expect($report->rows[1])->toMatchArray(['gross_cents' => 0, 'returns_cents' => 2000, 'net_cents' => -2000, 'payments_cents' => -500, 'credit_notes_count' => 1, 'credit_notes_cents' => 2000]);
    expect($report->totals)->toMatchArray(['returns_cents' => 2000, 'net_cents' => 8000, 'payments_cents' => 7500, 'credit_notes_count' => 1]);
});

it('aggregates product snapshots once per variant and reverses the original cost on returns', function () {
    $variant = Variant::factory()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'confirmed_at' => '2026-11-01 10:00:00']);
    $line = OrderLine::factory()->for($order)->for($variant)->create(['qty' => 4, 'unit_cost_cents' => 300, 'line_total_cents' => 4000, 'description' => 'First description']);
    OrderLine::factory()->for($order)->for($variant)->create(['qty' => 2, 'unit_cost_cents' => 400, 'line_total_cents' => 2400, 'description' => 'Later description']);
    $return = SalesReturn::factory()->for($order)->create(['created_at' => '2026-11-02 12:00:00', 'total_cents' => 1800]);
    SalesReturnLine::factory()->for($return)->for($line, 'orderLine')->for($variant)->create(['qty' => 2, 'unit_price_cents' => 900, 'line_total_cents' => 1800]);
    $excluded = Order::factory()->create(['status' => OrderStatus::Cancelled, 'confirmed_at' => '2026-11-01 10:00:00']);
    OrderLine::factory()->for($excluded)->for($variant)->create(['qty' => 99, 'line_total_cents' => 99000]);

    $report = app(SalesByProduct::class)->run(CarbonImmutable::parse('2026-11-01', 'Asia/Colombo'), CarbonImmutable::parse('2026-11-02', 'Asia/Colombo'));

    expect($report->rows)->toHaveCount(1);
    expect($report->rows[0])->toMatchArray([
        'variant_id' => $variant->id, 'qty_sold' => 6, 'gross_cents' => 6400, 'qty_returned' => 2,
        'returned_cents' => 1800, 'net_qty' => 4, 'net_cents' => 4600, 'cost_cents' => 2000,
        'returned_cost_cents' => 600, 'net_cost_cents' => 1400, 'unknown_cost' => false, 'margin_cents' => 3200,
    ]);
    expect($report->totals)->toMatchArray(['net_qty' => 4, 'net_cents' => 4600, 'margin_cents' => 3200]);
});

it('excludes returns and credit notes belonging to uncounted orders from sales reports', function (OrderStatus $status) {
    $order = Order::factory()->create(['status' => $status, 'total_cents' => 2000, 'confirmed_at' => '2026-10-01 10:00:00']);
    $line = OrderLine::factory()->for($order)->create(['qty' => 2, 'unit_cost_cents' => 300, 'line_total_cents' => 2000]);
    $return = SalesReturn::factory()->for($order)->create(['total_cents' => 1000, 'refund_cents' => 0, 'created_at' => '2026-11-02 10:00:00']);
    SalesReturnLine::factory()->for($return)->for($line, 'orderLine')->create([
        'variant_id' => $line->variant_id, 'qty' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000,
    ]);
    $invoice = Invoice::factory()->for($order)->create();
    CreditNote::factory()->for($invoice)->for($order)->create([
        'customer_id' => $order->customer_id, 'sales_return_id' => $return->id, 'total_cents' => 1000,
        'issued_on' => '2026-11-02', 'status' => DocumentStatus::Issued,
    ]);
    $day = CarbonImmutable::parse('2026-11-02', 'Asia/Colombo');

    $daily = app(SalesByDay::class)->run($day, $day);
    $products = app(SalesByProduct::class)->run($day, $day);

    expect($daily->rows[0])->toMatchArray([
        'gross_cents' => 0, 'returns_cents' => 0, 'net_cents' => 0, 'credit_notes_count' => 0, 'credit_notes_cents' => 0,
    ]);
    expect($daily->totals)->toMatchArray(['returns_cents' => 0, 'net_cents' => 0, 'credit_notes_count' => 0, 'credit_notes_cents' => 0]);
    expect($products->rows)->toBe([]);
    expect($products->totals)->toMatchArray(['qty_returned' => 0, 'returned_cents' => 0, 'net_qty' => 0, 'net_cents' => 0]);
})->with([
    'cancelled' => [OrderStatus::Cancelled],
    'expired' => [OrderStatus::Expired],
    'quoted' => [OrderStatus::Quoted],
    'placed' => [OrderStatus::Placed],
]);

it('includes return-only products in the period and suppresses margins when the sale cost is unknown', function () {
    $old = Order::factory()->create(['status' => OrderStatus::Completed, 'confirmed_at' => '2026-10-01 12:00:00']);
    $oldLine = OrderLine::factory()->for($old)->create(['qty' => 3, 'unit_cost_cents' => 200, 'line_total_cents' => 3000]);
    $return = SalesReturn::factory()->for($old)->create(['total_cents' => 1000, 'created_at' => '2026-11-02 12:00:00']);
    SalesReturnLine::factory()->for($return)->for($oldLine, 'orderLine')->create(['variant_id' => $oldLine->variant_id, 'qty' => 1, 'unit_price_cents' => 1000, 'line_total_cents' => 1000]);
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-11-02 12:00:00']);
    $unknown = OrderLine::factory()->for($order)->create(['qty' => 2, 'unit_cost_cents' => null, 'line_total_cents' => 5000]);

    $report = app(SalesByProduct::class)->run(CarbonImmutable::parse('2026-11-02', 'Asia/Colombo'), CarbonImmutable::parse('2026-11-02', 'Asia/Colombo'));

    expect(array_column($report->rows, 'variant_id'))->toBe([$unknown->variant_id, $oldLine->variant_id]);
    expect($report->rows[0])->toMatchArray(['unknown_cost' => true, 'net_cost_cents' => null, 'margin_cents' => null]);
    expect($report->rows[1])->toMatchArray(['qty_sold' => 0, 'gross_cents' => 0, 'qty_returned' => 1, 'net_qty' => -1, 'net_cents' => -1000, 'net_cost_cents' => -200, 'margin_cents' => -800]);
    expect($report->totals)->toMatchArray(['net_cents' => 4000, 'margin_cents' => null]);
});

it('values tracked stock across locations including negative quantities and flags unknown costs', function () {
    $known = Variant::factory()->create(['last_cost_cents' => 1000, 'track_stock' => true]);
    StockLevel::factory()->for($known)->create(['qty' => 5]);
    StockLevel::factory()->for($known)->create(['qty' => -2]);
    $negative = Variant::factory()->create(['last_cost_cents' => 400, 'track_stock' => true]);
    StockLevel::factory()->for($negative)->create(['qty' => -3]);
    $unknown = Variant::factory()->create(['last_cost_cents' => null, 'track_stock' => true]);
    StockLevel::factory()->for($unknown)->create(['qty' => 8]);
    $empty = Variant::factory()->create(['last_cost_cents' => 200, 'track_stock' => true]);
    StockLevel::factory()->for(Variant::factory()->create(['track_stock' => false, 'last_cost_cents' => 99999]))->create(['qty' => 99]);

    $report = app(StockValuation::class)->run();
    $rows = collect($report->rows)->keyBy('variant_id');

    expect($rows)->toHaveCount(4);
    expect($rows[$known->id])->toMatchArray(['qty' => 3, 'value_cents' => 3000, 'unknown_cost' => false]);
    expect($rows[$negative->id])->toMatchArray(['qty' => -3, 'value_cents' => -1200]);
    expect($rows[$unknown->id])->toMatchArray(['qty' => 8, 'value_cents' => null, 'unknown_cost' => true]);
    expect($rows[$empty->id])->toMatchArray(['qty' => 0, 'value_cents' => 0]);
    expect($report->totals)->toMatchArray(['qty' => 8, 'value_cents' => 1800, 'unknown_cost_count' => 1]);
});

it('keeps historical product snapshots separate after their variants have been deleted', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'confirmed_at' => '2026-11-02 12:00:00']);
    OrderLine::factory()->for($order)->create([
        'variant_id' => null, 'sku' => 'OLD-PANEL-A', 'description' => 'Deleted panel A',
        'qty' => 2, 'line_total_cents' => 2000, 'unit_cost_cents' => 300,
    ]);
    OrderLine::factory()->for($order)->create([
        'variant_id' => null, 'sku' => 'OLD-PANEL-B', 'description' => 'Deleted panel B',
        'qty' => 3, 'line_total_cents' => 6000, 'unit_cost_cents' => 500,
    ]);
    OrderLine::factory()->for($order)->create([
        'variant_id' => null, 'sku' => 'OLD-PANEL-A', 'description' => 'Deleted panel A',
        'qty' => 1, 'line_total_cents' => 1000, 'unit_cost_cents' => 300,
    ]);

    $report = app(SalesByProduct::class)->run(CarbonImmutable::parse('2026-11-02', 'Asia/Colombo'), CarbonImmutable::parse('2026-11-02', 'Asia/Colombo'));

    expect(array_column($report->rows, 'sku'))->toBe(['OLD-PANEL-B', 'OLD-PANEL-A']);
    expect($report->rows[0])->toMatchArray(['variant_id' => null, 'qty_sold' => 3, 'gross_cents' => 6000, 'cost_cents' => 1500, 'margin_cents' => 4500]);
    expect($report->rows[1])->toMatchArray(['variant_id' => null, 'qty_sold' => 3, 'gross_cents' => 3000, 'cost_cents' => 900, 'margin_cents' => 2100]);
    expect($report->totals)->toMatchArray(['qty_sold' => 6, 'gross_cents' => 9000, 'margin_cents' => 6600]);
});

it('includes walk-in and removed customers while ageing each positive effective order balance', function () {
    $asOf = CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Colombo');
    $customer = Customer::factory()->create(['name' => 'Regular buyer']);
    foreach ([30 => 1000, 31 => 2000, 60 => 3000, 61 => 4000, 90 => 5000, 91 => 6000] as $days => $cents) {
        Order::factory()->for($customer)->create([
            'status' => OrderStatus::Confirmed, 'total_cents' => $cents + 700, 'returned_cents' => 200, 'paid_cents' => 500,
            'confirmed_at' => $asOf->subDays($days)->setTime(23, 59, 59),
        ]);
    }
    $walkIn = Customer::factory()->create(['name' => 'Walk-in customer', 'phone_e164' => null, 'is_walk_in' => true]);
    Order::factory()->for($walkIn)->create(['status' => OrderStatus::Completed, 'total_cents' => 700, 'confirmed_at' => $asOf]);
    $removed = Customer::factory()->create(['name' => 'Private old name', 'anonymised_at' => $asOf]);
    Order::factory()->for($removed)->create(['status' => OrderStatus::Dispatched, 'total_cents' => 800, 'confirmed_at' => $asOf]);
    $merged = Customer::factory()->create(['name' => 'Private merged name', 'merged_into_id' => $customer->id]);
    Order::factory()->for($merged)->create(['status' => OrderStatus::Processing, 'total_cents' => 900, 'confirmed_at' => $asOf]);
    foreach ([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Cancelled, OrderStatus::Expired] as $status) {
        Order::factory()->for($customer)->create(['status' => $status, 'total_cents' => 99999, 'confirmed_at' => $asOf->subDays(150)]);
    }
    Order::factory()->for($customer)->create(['status' => OrderStatus::Confirmed, 'total_cents' => 900, 'returned_cents' => 900, 'confirmed_at' => $asOf->subDays(200)]);
    Order::factory()->for($customer)->create(['status' => OrderStatus::Confirmed, 'total_cents' => 900, 'paid_cents' => 1000, 'confirmed_at' => $asOf->subDays(200)]);

    $report = app(MoneyOwed::class)->run($asOf);
    $rows = collect($report->rows)->keyBy('customer_id');

    expect($report->rows[0]['customer_id'])->toBe($customer->id);
    expect($rows[$customer->id])->toMatchArray([
        'orders_owing' => 6, 'balance_cents' => 21000, 'oldest_order_date' => '2026-08-19',
        'age_0_30_cents' => 1000, 'age_31_60_cents' => 5000, 'age_61_90_cents' => 9000, 'age_90_plus_cents' => 6000,
    ]);
    expect($rows[$walkIn->id])->toMatchArray(['name' => 'Walk-in customer', 'balance_cents' => 700]);
    expect($rows[$removed->id])->toMatchArray(['name' => 'Removed customer', 'phone' => null, 'balance_cents' => 800]);
    expect($rows[$merged->id])->toMatchArray(['name' => 'Removed customer', 'phone' => null, 'balance_cents' => 900]);
    expect($report->totals)->toMatchArray([
        'customers_count' => 4, 'orders_owing' => 9, 'balance_cents' => 23400,
        'age_0_30_cents' => 3400, 'age_31_60_cents' => 5000, 'age_61_90_cents' => 9000, 'age_90_plus_cents' => 6000,
    ]);
});

it('keeps debt on orders whose customer has been removed instead of dropping it from totals', function () {
    $order = Order::factory()->create([
        'customer_id' => null, 'customer_name' => 'Private snapshot name', 'phone_e164' => '+94771234567',
        'status' => OrderStatus::Confirmed, 'total_cents' => 3000, 'returned_cents' => 500, 'paid_cents' => 1000,
        'confirmed_at' => '2026-11-01 12:00:00',
    ]);

    $report = app(MoneyOwed::class)->run(CarbonImmutable::parse('2026-11-18', 'Asia/Colombo'));

    expect($report->rows)->toHaveCount(1);
    expect($report->rows[0])->toMatchArray([
        'customer_id' => null, 'name' => 'Removed customer', 'phone' => null,
        'orders_owing' => 1, 'balance_cents' => $order->balanceCents(), 'age_0_30_cents' => 1500,
    ]);
    expect($report->totals)->toMatchArray(['customers_count' => 1, 'balance_cents' => 1500]);
});

it('groups only posted goods receipts by supplier and counts each receipt once despite multiple lines', function () {
    $supplier = Supplier::factory()->create(['name' => 'Panel supplier']);
    $receipt = GoodsReceipt::factory()->for($supplier)->create(['status' => GoodsReceiptStatus::Posted, 'posted_at' => '2026-11-01 00:00:00', 'total_cost_cents' => 1600]);
    GoodsReceiptLine::factory()->for($receipt)->create(['qty' => 3, 'unit_cost_cents' => 200, 'line_total_cents' => 600]);
    GoodsReceiptLine::factory()->for($receipt)->create(['qty' => 5, 'unit_cost_cents' => 200, 'line_total_cents' => 1000]);
    $second = GoodsReceipt::factory()->for($supplier)->create(['status' => GoodsReceiptStatus::Posted, 'posted_at' => '2026-11-30 23:59:59', 'total_cost_cents' => 900]);
    GoodsReceiptLine::factory()->for($second)->create(['qty' => 3, 'unit_cost_cents' => 300, 'line_total_cents' => 900]);
    foreach ([GoodsReceiptStatus::Void, GoodsReceiptStatus::Draft] as $status) {
        $excluded = GoodsReceipt::factory()->for($supplier)->create(['status' => $status, 'posted_at' => '2026-11-02 10:00:00', 'total_cost_cents' => 99999]);
        GoodsReceiptLine::factory()->for($excluded)->create(['qty' => 99]);
    }
    GoodsReceipt::factory()->for($supplier)->create(['status' => GoodsReceiptStatus::Posted, 'posted_at' => '2026-12-01 00:00:00', 'total_cost_cents' => 99999]);

    $report = app(GoodsReceivedBySupplier::class)->run(CarbonImmutable::parse('2026-11-01', 'Asia/Colombo'), CarbonImmutable::parse('2026-11-30', 'Asia/Colombo'));

    expect($report->rows)->toHaveCount(1);
    expect($report->rows[0])->toMatchArray(['supplier_id' => $supplier->id, 'supplier' => 'Panel supplier', 'receipts_count' => 2, 'units' => 11, 'cost_cents' => 2500]);
    expect($report->totals)->toMatchArray(['receipts_count' => 2, 'units' => 11, 'cost_cents' => 2500]);
});
