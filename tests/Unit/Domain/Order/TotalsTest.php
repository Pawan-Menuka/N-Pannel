<?php

use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\InvalidOrderTotals;
use App\Domain\Order\Totals;
use App\Enums\OrderLineKind;

it('calculates percentages with integer half up rounding', function (int $total, int $percent, int $expected) {
    expect(Totals::percentOf($total, $percent))->toBe($expected);
})->with([[101, 50, 51], [1, 50, 1], [1, 49, 0], [12345, 0, 0], [12345, 100, 12345], [PHP_INT_MAX, 100, PHP_INT_MAX]]);

it('refuses invalid percentage inputs', function (int $total, int $percent) {
    expect(fn () => Totals::percentOf($total, $percent))->toThrow(InvalidOrderTotals::class);
})->with([[-1, 50], [100, -1], [100, 101]]);

it('separates subtotal delivery and positive discounts', function () {
    $totals = Totals::calculate([
        ['kind' => OrderLineKind::Product, 'qty' => 2, 'unitPriceCents' => 10000],
        ['kind' => OrderLineKind::Labour, 'qty' => 3, 'unitPriceCents' => 2000],
        ['kind' => OrderLineKind::Other, 'qty' => 1, 'unitPriceCents' => 500],
        ['kind' => OrderLineKind::Delivery, 'qty' => 1, 'unitPriceCents' => 1500],
        ['kind' => OrderLineKind::Discount, 'qty' => 2, 'unitPriceCents' => 1000],
    ], 10000);

    expect($totals->subtotalCents)->toBe(26500);
    expect($totals->deliveryCents)->toBe(1500);
    expect($totals->discountCents)->toBe(2000);
    expect($totals->totalCents)->toBe(26000);
    expect($totals->requiredAdvanceCents)->toBe(10000);
    expect(Totals::lineTotal(OrderLineKind::Discount, 2, 1000))->toBe(-2000);
});

it('refuses discounts larger than the order', function () {
    expect(fn () => Totals::calculate([
        ['kind' => OrderLineKind::Discount, 'qty' => 1, 'unitPriceCents' => 1],
    ]))->toThrow(InvalidOrderTotals::class, 'The discount is larger than the order.');
});

it('accepts null zero and full advances', function (?int $advance) {
    expect(Totals::calculate([
        ['kind' => OrderLineKind::Product, 'qty' => 1, 'unitPriceCents' => 100],
    ], $advance)->requiredAdvanceCents)->toBe($advance);
})->with([null, 0, 100]);

it('refuses advances outside the order total', function (int $advance) {
    expect(fn () => Totals::calculate([
        ['kind' => OrderLineKind::Product, 'qty' => 1, 'unitPriceCents' => 100],
    ], $advance))->toThrow(InvalidOrderTotals::class);
})->with([-1, 101]);

it('checks line quantity and unit bounds', function (int $qty, int $unit) {
    expect(fn () => Totals::lineTotal(OrderLineKind::Product, $qty, $unit))->toThrow(InvalidOrderTotals::class);
})->with([[0, 100], [-1, 100], [1000001, 1], [1, -1], [1, 10000000001], [PHP_INT_MAX, PHP_INT_MAX]]);

it('accepts the inclusive line bounds for every kind', function (OrderLineKind $kind) {
    expect(Totals::lineTotal($kind, 1, 0))->toBe(0);
    expect(Totals::lineTotal($kind, 1000000, 10000000000))->toBe($kind === OrderLineKind::Discount ? -10000000000000000 : 10000000000000000);
})->with(OrderLineKind::cases());

it('refuses aggregate overflow in every bucket and in the combined total', function (OrderLineKind $kind) {
    $lines = array_fill(0, 923, ['kind' => $kind, 'qty' => 1000000, 'unitPriceCents' => 10000000000]);
    expect(fn () => Totals::calculate($lines))->toThrow(AmountTooLarge::class);
})->with([OrderLineKind::Product, OrderLineKind::Delivery, OrderLineKind::Discount]);

it('guards addition across subtotal and delivery', function () {
    $lines = array_fill(0, 922, ['kind' => OrderLineKind::Product, 'qty' => 1000000, 'unitPriceCents' => 10000000000]);
    $lines[] = ['kind' => OrderLineKind::Delivery, 'qty' => 1000000, 'unitPriceCents' => 10000000000];
    expect(fn () => Totals::calculate($lines))->toThrow(AmountTooLarge::class);
});

it('supports an empty zero order', function () {
    expect(Totals::calculate([])->totalCents)->toBe(0);
});
