<?php

use App\Domain\Reports\DateRange;
use Carbon\CarbonImmutable;

it('accepts exactly 366 inclusive days and produces an exclusive next-midnight upper bound', function () {
    $range = new DateRange(
        CarbonImmutable::parse('2026-01-01 15:30:00', 'Asia/Colombo'),
        CarbonImmutable::parse('2027-01-01 04:00:00', 'Asia/Colombo'),
    );

    expect($range->days())->toHaveCount(366);
    expect($range->bounds())->toBe(['2026-01-01 00:00:00', '2027-01-02 00:00:00']);
});

it('refuses reversed dates and more than 366 inclusive days', function (string $from, string $to) {
    expect(fn () => new DateRange(
        CarbonImmutable::parse($from, 'Asia/Colombo'),
        CarbonImmutable::parse($to, 'Asia/Colombo'),
    ))->toThrow(InvalidArgumentException::class, 'Choose an inclusive date range of no more than 366 days.');
})->with([
    'reversed dates' => ['2026-11-02', '2026-11-01'],
    '367 inclusive days' => ['2026-01-01', '2027-01-02'],
]);

it('normalises instants to Colombo calendar days before building local timestamp bounds', function () {
    $range = new DateRange(
        CarbonImmutable::parse('2026-10-31 18:30:00', 'UTC'),
        CarbonImmutable::parse('2026-11-01 18:29:59', 'UTC'),
    );

    expect($range->from->timezoneName)->toBe('Asia/Colombo');
    expect($range->days())->toBe(['2026-11-01']);
    expect($range->bounds())->toBe(['2026-11-01 00:00:00', '2026-11-02 00:00:00']);
});
