<?php

namespace App\Domain\Reports;

final readonly class ReportResult
{
    /**
     * @param  list<array<string, int|string|bool|null>>  $rows
     * @param  array<string, int|string|bool|null>  $totals
     * @param  array<string, string>  $columns
     * @param  list<string>  $moneyColumns
     */
    public function __construct(
        public array $rows,
        public array $totals,
        public array $columns,
        public array $moneyColumns = [],
    ) {}
}
