<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\DashboardData;
use App\Domain\Money\Lkr;
use App\Filament\Widgets\Concerns\DashboardAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayStats extends StatsOverviewWidget
{
    use DashboardAccess;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Today';

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $today = app(DashboardData::class)->today();

        return [
            Stat::make('Net sales today', Lkr::formatWithCurrency($today['net_cents'])),
            Stat::make('Orders today', $today['orders_count']),
            Stat::make('Payments received today', Lkr::formatWithCurrency($today['payments_cents'])),
        ];
    }
}
