<?php

use App\Enums\LateCancelReason;

it('labels late cancellation reasons', function (string $value, string $label) {
    expect(LateCancelReason::from($value)->getLabel())->toBe($label);
})->with([
    ['delivery_refused', 'Delivery refused'],
    ['not_collected', 'Not collected'],
    ['damaged_in_transit', 'Damaged in transit'],
]);
