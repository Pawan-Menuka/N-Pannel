<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\DashboardData;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\Concerns\DashboardAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NeedsAttentionStats extends StatsOverviewWidget
{
    use DashboardAccess;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    /** @return array<Stat> */
    protected function getStats(): array
    {
        return [Stat::make('Needs attention', app(DashboardData::class)->needsAttention())
            ->url(OrderResource::getUrl('index', ['tab' => 'needs_attention']))];
    }
}
