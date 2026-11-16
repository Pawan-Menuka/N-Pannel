<?php

use App\Domain\Customers\Balance;

it('calculates nonnegative money owed in cents', function (int $total, int $paid, int $expected) {
    expect(Balance::owed($total, $paid))->toBe($expected);
})->with([[10000, 3000, 7000], [10000, 15000, 0], [0, 0, 0]]);

it('counts only confirmed and subsequent live order statuses', function () {
    expect(Balance::statuses())->toBe(['confirmed', 'processing', 'ready_for_collection', 'dispatched', 'completed']);
});
