<?php

use App\Domain\Order\QuoteValidity;

it('expires only strictly after a known validity deadline', function () {
    expect(QuoteValidity::isExpired(100, null))->toBeFalse();
    expect(QuoteValidity::isExpired(99, 100))->toBeFalse();
    expect(QuoteValidity::isExpired(100, 100))->toBeFalse();
    expect(QuoteValidity::isExpired(101, 100))->toBeTrue();
});
