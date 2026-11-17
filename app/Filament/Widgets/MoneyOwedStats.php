<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\DashboardData;
use App\Domain\Money\Lkr;
use App\Filament\Pages\Reports\MoneyOwed;
use App\Filament\Widgets\Concerns\DashboardAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MoneyOwedStats extends StatsOverviewWidget
{
    use DashboardAccess;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $owed = app(DashboardData::class)->moneyOwed();

        return [Stat::make('Money owed', Lkr::formatWithCurrency($owed['balance_cents']))
            ->description($owed['customers_count'].' customers owing')
            ->url(MoneyOwed::canAccess() ? MoneyOwed::getUrl() : null)];
    }
}
