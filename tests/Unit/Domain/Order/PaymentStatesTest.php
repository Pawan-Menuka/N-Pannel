<?php

use App\Domain\Order\AmountTooLarge;
use App\Domain\Order\PaymentStates;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;

it('derives payment state from net paid and refund history', function (int $total, int $net, int $refunded, PaymentState $expected) {
    expect(PaymentStates::derive($total, $net, $refunded))->toBe($expected);
})->with([
    'unpaid' => [10000, 0, 0, PaymentState::Unpaid],
    'negative unpaid' => [10000, -1, 0, PaymentState::Unpaid],
    'partial' => [10000, 5000, 0, PaymentState::PartiallyPaid],
    'paid' => [10000, 10000, 0, PaymentState::Paid],
    'late overpayment' => [10000, 12000, 0, PaymentState::Paid],
    'fully refunded' => [10000, 0, 10000, PaymentState::Refunded],
    'negative refunded' => [10000, -1, 10000, PaymentState::Refunded],
    'partly refunded' => [10000, 6000, 4000, PaymentState::PartiallyPaid],
    'zero total' => [0, 0, 0, PaymentState::Paid],
    'positive net on zero total' => [0, 100, 0, PaymentState::Paid],
    'refunded zero total' => [0, 0, 100, PaymentState::Refunded],
]);

it('floors the balance at zero', function (int $total, int $net, int $expected) {
    expect(PaymentStates::balance($total, $net, OrderStatus::Confirmed))->toBe($expected);
})->with([[10000, 0, 10000], [10000, 4000, 6000], [10000, 10000, 0], [10000, 12000, 0], [10000, -100, 10100]]);

it('refuses balance overflow', function () {
    expect(fn () => PaymentStates::balance(PHP_INT_MAX, -1, OrderStatus::Confirmed))->toThrow(AmountTooLarge::class);
});

it('has no amount owed on cancelled or expired orders regardless of net payments', function (OrderStatus $status, int $net) {
    expect(PaymentStates::balance(16250000, $net, $status))->toBe(0);
})->with([OrderStatus::Cancelled, OrderStatus::Expired])->with([0, 5000, 16250000, 20000000]);

it('keeps the amount to pay on quotes and other live orders', function (OrderStatus $status) {
    expect(PaymentStates::balance(10000, 3000, $status))->toBe(7000);
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed]);

it('distinguishes unpaid full returns from originally free orders and refunded orders', function (int $original, int $net, int $refunded, PaymentState $expected) {
    expect(PaymentStates::derive(0, $net, $refunded, $original))->toBe($expected);
})->with([
    'never paid and fully returned' => [10000, 0, 0, PaymentState::Unpaid],
    'originally free' => [0, 0, 0, PaymentState::Paid],
    'fully refunded' => [10000, 0, 10000, PaymentState::Refunded],
    'paid and awaiting refund' => [10000, 10000, 0, PaymentState::Paid],
]);
