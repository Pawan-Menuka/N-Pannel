<?php

use App\Domain\Customers\Balance;
use App\Domain\Returns\ReturnCalculator;

it('calculates lowered values and the refundable overpayment using the effective total', function () {
    $result = ReturnCalculator::calculate(
        [['id' => 1, 'qty' => 4, 'unit_price_cents' => 1000, 'already_returned_qty' => 1]],
        [['order_line_id' => 1, 'qty' => 2, 'unit_value_cents' => 800, 'restock' => false]],
        4000, 1000, 3000,
    );

    expect($result['errors'])->toBe([]);
    expect($result['total_cents'])->toBe(1600);
    expect($result['max_refundable_cents'])->toBe(1600);
    expect($result['lines'][0]['line_total_cents'])->toBe(1600);
    expect($result['lines'][0]['restock'])->toBeFalse();
});

it('reports invalid requests without producing usable return lines', function (array $requested, int $total, int $returned, string $message) {
    $result = ReturnCalculator::calculate(
        [['id' => 1, 'qty' => 4, 'unit_price_cents' => 1000, 'already_returned_qty' => 1]],
        $requested, $total, $returned, 0,
    );

    expect(implode(' ', $result['errors']))->toContain($message);
    expect($result['lines'])->toBe([]);
})->with([
    'nothing' => [[], 4000, 0, 'Choose at least one'],
    'zero quantity' => [[['order_line_id' => 1, 'qty' => 0, 'unit_value_cents' => 1000, 'restock' => true]], 4000, 0, 'Quantity'],
    'negative quantity' => [[['order_line_id' => 1, 'qty' => -1, 'unit_value_cents' => 1000, 'restock' => true]], 4000, 0, 'Quantity'],
    'remaining quantity' => [[['order_line_id' => 1, 'qty' => 4, 'unit_value_cents' => 1000, 'restock' => true]], 4000, 0, 'Only 3'],
    'raised value' => [[['order_line_id' => 1, 'qty' => 1, 'unit_value_cents' => 1001, 'restock' => true]], 4000, 0, 'sale price'],
    'negative value' => [[['order_line_id' => 1, 'qty' => 1, 'unit_value_cents' => -1, 'restock' => true]], 4000, 0, 'negative'],
    'unknown or non-product' => [[['order_line_id' => 99, 'qty' => 1, 'unit_value_cents' => 1000, 'restock' => true]], 4000, 0, 'product line'],
    'cumulative total' => [[['order_line_id' => 1, 'qty' => 2, 'unit_value_cents' => 1000, 'restock' => true]], 4000, 3000, 'order total'],
    'combined quantities on split lines' => [[['order_line_id' => 1, 'qty' => 2, 'unit_value_cents' => 1000, 'restock' => true], ['order_line_id' => 1, 'qty' => 2, 'unit_value_cents' => 1000, 'restock' => true]], 4000, 0, 'Only 1'],
]);

it('refuses a return with no value and never offers a refund for it', function () {
    $result = ReturnCalculator::calculate(
        [['id' => 1, 'qty' => 1, 'unit_price_cents' => 100, 'already_returned_qty' => 0]],
        [['order_line_id' => 1, 'qty' => 1, 'unit_value_cents' => 0, 'restock' => true]],
        100, 0, 0,
    );
    expect($result['errors'])->toBe(['The return total must be more than Rs 0.00.']);
    expect($result['lines'])->toBe([]);
    expect($result['total_cents'])->toBe(0);
    expect($result['max_refundable_cents'])->toBe(0);
});

it('reports amounts outside safe totals limits without overflowing', function () {
    $result = ReturnCalculator::calculate(
        [['id' => 1, 'qty' => 1000000, 'unit_price_cents' => PHP_INT_MAX, 'already_returned_qty' => 0]],
        [['order_line_id' => 1, 'qty' => 1000000, 'unit_value_cents' => PHP_INT_MAX, 'restock' => true]],
        PHP_INT_MAX, 0, 0,
    );
    expect($result['errors'])->not->toBeEmpty();
});

it('combines split restock and damaged quantities for a single sold line', function () {
    $result = ReturnCalculator::calculate(
        [['id' => 1, 'qty' => 2, 'unit_price_cents' => 1000, 'already_returned_qty' => 0]],
        [['order_line_id' => 1, 'qty' => 1, 'unit_value_cents' => 1000, 'restock' => true], ['order_line_id' => 1, 'qty' => 1, 'unit_value_cents' => 500, 'restock' => false]],
        2000, 0, 2000,
    );
    expect($result['errors'])->toBe([]);
    expect($result['lines'])->toHaveCount(2);
    expect($result['total_cents'])->toBe(1500);
    expect($result['max_refundable_cents'])->toBe(1500);
});

it('does not overflow when the paid balance is negative', function () {
    $result = ReturnCalculator::calculate(
        [['id' => 1, 'qty' => 1, 'unit_price_cents' => 1, 'already_returned_qty' => 0]],
        [['order_line_id' => 1, 'qty' => 1, 'unit_value_cents' => 1, 'restock' => false]],
        PHP_INT_MAX, 0, -PHP_INT_MAX,
    );
    expect($result['errors'])->toBe([]);
    expect($result['max_refundable_cents'])->toBe(0);
});

it('subtracts return credits in the customer balance domain calculation', function () {
    expect(Balance::owed(4000, 1000, 2000))->toBe(1000);
    expect(Balance::owed(4000, 3000, 2000))->toBe(0);
});

it('floors discounted unit values using integer arithmetic', function (int $price, int $subtotal, int $discount, int $expected) {
    expect(ReturnCalculator::discountedUnitValue($price, $subtotal, $discount))->toBe($expected);
})->with([
    'one fifth discount' => [100000, 500000, 100000, 80000],
    'fractional cent floors' => [100, 300, 1, 99],
    'no discount' => [100000, 500000, 0, 100000],
    'zero subtotal' => [0, 0, 0, 0],
    'zero subtotal guarded' => [100, 0, 0, 0],
    'full discount' => [100, 1000, 1000, 0],
    'legacy discount above subtotal' => [100, 1000, 1100, 0],
    'large subtotal with one cent discount' => [10000000000, 9000000000000000000, 1, 9999999999],
    'maximum integer ratio' => [PHP_INT_MAX, PHP_INT_MAX, 1, PHP_INT_MAX - 1],
]);

it('caps requested returns at the discounted unit value', function () {
    $lines = [['id' => 1, 'qty' => 5, 'unit_price_cents' => 100000, 'already_returned_qty' => 0]];
    $raised = ReturnCalculator::calculate($lines, [['order_line_id' => 1, 'qty' => 1, 'unit_value_cents' => 80001, 'restock' => false]], 400000, 0, 0, 500000, 100000);
    expect($raised['errors'])->toBe(['The return unit value cannot exceed the sale price.']);
    $all = ReturnCalculator::calculate($lines, [['order_line_id' => 1, 'qty' => 5, 'unit_value_cents' => 80000, 'restock' => false]], 400000, 0, 0, 500000, 100000);
    expect($all['errors'])->toBe([]);
    expect($all['total_cents'])->toBe(400000);
});
