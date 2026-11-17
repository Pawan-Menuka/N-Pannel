<?php

namespace App\Actions\Reports;

use App\Domain\Catalog\CatalogFormat;
use App\Domain\Money\Lkr;
use App\Domain\Reports\DateRange;
use App\Domain\Reports\ReportResult;
use Carbon\CarbonImmutable;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportReportCsv
{
    public function download(ReportResult $report, string $slug, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                throw new RuntimeException('Cannot write report export.');
            }
            try {
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, array_map(CatalogFormat::exportCell(...), array_values($report->columns)), ',', '"', '', "\n");
                foreach ($report->rows as $row) {
                    $this->writeRow($handle, $report, $row);
                }
                $totals = $report->totals;
                $firstColumn = array_key_first($report->columns);
                if ($firstColumn !== null) {
                    $totals[$firstColumn] = 'Totals';
                }
                $this->writeRow($handle, $report, $totals);
            } finally {
                fclose($handle);
            }
        }, $this->filename($slug, $from, $to), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function filename(string $slug, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): string
    {
        if ($from !== null && $to !== null) {
            $range = new DateRange($from, $to);

            return $slug.'-'.$range->from->toDateString().'-to-'.$range->to->toDateString().'.csv';
        }

        return $slug.'-'.CarbonImmutable::now('Asia/Colombo')->toDateString().'.csv';
    }

    /**
     * @param  resource  $handle
     * @param  array<string, int|string|bool|null>  $row
     */
    private function writeRow($handle, ReportResult $report, array $row): void
    {
        $cells = [];
        foreach ($report->columns as $key => $label) {
            $value = $row[$key] ?? null;
            if (is_int($value)) {
                $cells[] = in_array($key, $report->moneyColumns, true)
                    ? str_replace(',', '', Lkr::format($value))
                    : $value;

                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }
            $cells[] = CatalogFormat::exportCell($value);
        }
        fputcsv($handle, $cells, ',', '"', '', "\n");
    }
}
