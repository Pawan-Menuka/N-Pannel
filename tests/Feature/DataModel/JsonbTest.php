<?php

use Illuminate\Support\Facades\DB;

it('stores every JSON column as jsonb', function () {
    $columns = DB::select(
        "select table_name, column_name from information_schema.columns where table_schema = current_schema() and data_type = 'json'"
    );

    expect($columns)->toBe([]);
});

it('creates the expected jsonb columns', function (string $table, string $column) {
    $columns = DB::select(
        'select data_type from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
        [$table, $column],
    );

    expect($columns)->toHaveCount(1);
    expect($columns[0]->data_type)->toBe('jsonb');
})->with([
    'district name' => ['districts', 'name'],
    'category name' => ['categories', 'name'],
    'category description' => ['categories', 'description'],
    'product title' => ['products', 'title'],
    'product summary' => ['products', 'summary'],
    'product description' => ['products', 'description'],
    'customer tags' => ['customers', 'tags'],
    'quote snapshot lines' => ['quote_snapshots', 'lines'],
    'invoice lines' => ['invoices', 'lines'],
    'invoice customer snapshot' => ['invoices', 'customer_snapshot'],
    'credit note lines' => ['credit_notes', 'lines'],
    'credit note customer snapshot' => ['credit_notes', 'customer_snapshot'],
    'activity changes' => ['activity_log', 'attribute_changes'],
    'activity properties' => ['activity_log', 'properties'],
    'settings payload' => ['settings', 'payload'],
]);
