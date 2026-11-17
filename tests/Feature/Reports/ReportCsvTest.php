<?php

use App\Actions\Reports\ExportReportCsv;
use App\Actions\Reports\GoodsReceivedBySupplier;
use App\Actions\Reports\MoneyOwed;
use App\Actions\Reports\SalesByDay;
use App\Actions\Reports\SalesByProduct;
use App\Actions\Reports\StockValuation;
use App\Domain\Reports\ReportResult;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;

it('streams a BOM CSV with headers safe customer cells plain decimal money and totals', function () {
    $customer = Customer::factory()->create(['name' => '=SUM(A1)']);
    Order::factory()->for($customer)->create(['status' => OrderStatus::Confirmed, 'total_cents' => 125050, 'confirmed_at' => '2026-11-01 10:00:00']);
    $report = app(MoneyOwed::class)->run(CarbonImmutable::parse('2026-11-18', 'Asia/Colombo'));
    $response = app(ExportReportCsv::class)->download($report, 'money-owed');

    ob_start();
    try {
        $response->sendContent();
        $content = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $lines = explode("\n", trim(substr($content, 3)));
    $header = str_getcsv($lines[0], ',', '"', '');
    $data = str_getcsv($lines[1], ',', '"', '');
    $totals = str_getcsv($lines[2], ',', '"', '');

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF");
    expect($header)->toBe(array_values($report->columns));
    expect($data[array_search('name', array_keys($report->columns), true)])->toBe("'=SUM(A1)");
    expect($data[array_search('balance_cents', array_keys($report->columns), true)])->toBe('1250.50');
    expect(strtolower($totals[0]))->toContain('total');
    expect($totals[array_search('balance_cents', array_keys($report->columns), true)])->toBe('1250.50');
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->headers->get('Content-Disposition'))->toContain('money-owed');
});

it('includes a header and totals even when a report has no records', function (string $action, string $slug, bool $ranged) {
    $from = CarbonImmutable::parse('2026-11-01', 'Asia/Colombo');
    $to = CarbonImmutable::parse('2026-11-30', 'Asia/Colombo');
    $report = $ranged ? app($action)->run($from, $to) : app($action)->run();
    $response = app(ExportReportCsv::class)->download($report, $slug, $ranged ? $from : null, $ranged ? $to : null);

    ob_start();
    try {
        $response->sendContent();
        $content = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $lines = explode("\n", trim(substr($content, 3)));

    expect(str_getcsv($lines[0], ',', '"', ''))->toBe(array_values($report->columns));
    expect(strtolower(str_getcsv($lines[array_key_last($lines)], ',', '"', '')[0]))->toContain('total');
    expect(count($lines))->toBe(count($report->rows) + 2);
})->with([
    'daily sales' => [SalesByDay::class, 'sales-by-day', true],
    'product sales' => [SalesByProduct::class, 'sales-by-product', true],
    'stock valuation' => [StockValuation::class, 'stock-value', false],
    'money owed' => [MoneyOwed::class, 'money-owed', false],
    'goods received' => [GoodsReceivedBySupplier::class, 'goods-received-by-supplier', true],
]);

it('names ranged exports with both inclusive dates', function () {
    $from = CarbonImmutable::parse('2026-11-01', 'Asia/Colombo');
    $to = CarbonImmutable::parse('2026-11-30', 'Asia/Colombo');
    $export = app(ExportReportCsv::class);

    expect($export->filename('sales-by-day', $from, $to))->toBe('sales-by-day-2026-11-01-to-2026-11-30.csv');
});

it('preserves negative numbers as numeric CSV cells while escaping every dangerous text prefix', function (string $text) {
    $report = new ReportResult(
        [['name' => $text, 'qty' => -2, 'value_cents' => -123456]],
        ['qty' => -2, 'value_cents' => -123456],
        ['name' => '=Header', 'qty' => 'Quantity', 'value_cents' => 'Value'],
        ['value_cents'],
    );
    $response = app(ExportReportCsv::class)->download($report, 'negative-values');

    ob_start();
    try {
        $response->sendContent();
        $content = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $handle = fopen('php://temp', 'w+b');
    expect($handle)->not->toBeFalse();
    try {
        fwrite($handle, substr($content, 3));
        rewind($handle);
        $header = fgetcsv($handle, null, ',', '"', '');
        $row = fgetcsv($handle, null, ',', '"', '');
        $totals = fgetcsv($handle, null, ',', '"', '');
        $end = fgetcsv($handle, null, ',', '"', '');
    } finally {
        fclose($handle);
    }

    expect($header)->toBe(["'=Header", 'Quantity', 'Value']);
    expect($row)->toBe(["'".$text, '-2', '-1234.56']);
    expect($totals)->toBe(['Totals', '-2', '-1234.56']);
    expect($end)->toBeFalse();
})->with([
    'equals' => ['=SUM(A1)'],
    'plus' => ['+SUM(A1)'],
    'minus text' => ['-12'],
    'at sign' => ['@SUM(A1)'],
    'tab' => ["\tSUM(A1)"],
    'carriage return' => ["\rSUM(A1)"],
]);
