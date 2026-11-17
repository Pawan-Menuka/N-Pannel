<?php

use App\Domain\Reports\AgeBucket;

it('assigns each calendar-day boundary to its ageing bucket', function (int $days, AgeBucket $expected, string $column) {
    expect(AgeBucket::forDays($days))->toBe($expected);
    expect(AgeBucket::forDays($days)->column())->toBe($column);
})->with([
    'today' => [0, AgeBucket::Days0To30, 'age_0_30_cents'],
    '30 days' => [30, AgeBucket::Days0To30, 'age_0_30_cents'],
    '31 days' => [31, AgeBucket::Days31To60, 'age_31_60_cents'],
    '60 days' => [60, AgeBucket::Days31To60, 'age_31_60_cents'],
    '61 days' => [61, AgeBucket::Days61To90, 'age_61_90_cents'],
    '90 days' => [90, AgeBucket::Days61To90, 'age_61_90_cents'],
    '91 days' => [91, AgeBucket::Days90Plus, 'age_90_plus_cents'],
]);
