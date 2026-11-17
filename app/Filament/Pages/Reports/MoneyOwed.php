<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\MoneyOwed as BuildMoneyOwed;
use App\Domain\Reports\ReportResult;

class MoneyOwed extends ReportPage
{
    protected static ?string $title = 'Money owed';

    public function usesDateRange(): bool
    {
        return false;
    }

    public function reportNote(): ?string
    {
        return 'Current balances, aged by each owing order\'s confirmed date. Walk-in customers are included.';
    }

    protected function buildReport(): ReportResult
    {
        return app(BuildMoneyOwed::class)->run();
    }

    protected function exportSlug(): string
    {
        return 'money-owed';
    }
}
