<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\StockValuation;
use App\Domain\Reports\ReportResult;

class StockValue extends ReportPage
{
    protected static ?string $title = 'Stock value';

    public function usesDateRange(): bool
    {
        return false;
    }

    public function reportNote(): ?string
    {
        return 'Current stock across all locations. Negative quantities are shown and valued as negative amounts. Items with unknown last cost are excluded from the total value.';
    }

    protected function buildReport(): ReportResult
    {
        return app(StockValuation::class)->run();
    }

    protected function exportSlug(): string
    {
        return 'stock-value';
    }
}
