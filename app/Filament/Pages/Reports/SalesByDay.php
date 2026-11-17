<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\SalesByDay as BuildSalesByDay;
use App\Domain\Reports\ReportResult;

class SalesByDay extends ReportPage
{
    protected static ?string $title = 'Sales by day';

    protected function buildReport(): ReportResult
    {
        return app(BuildSalesByDay::class)->run($this->rangeFrom(), $this->rangeTo());
    }

    protected function exportSlug(): string
    {
        return 'sales-by-day';
    }
}
