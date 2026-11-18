<?php

use Illuminate\Support\Facades\Schema;

it('indexes the date and status fields used by reports', function (string $table, array $columns) {
    expect(Schema::hasIndex($table, $columns))->toBeTrue();
})->with([
    ['orders', ['confirmed_at']],
    ['payments', ['status', 'confirmed_at']],
    ['sales_returns', ['created_at']],
    ['goods_receipts', ['status', 'posted_at']],
]);
