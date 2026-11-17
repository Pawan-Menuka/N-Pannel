<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\GoodsReceivedBySupplier as BuildGoodsReceivedBySupplier;
use App\Domain\Reports\ReportResult;

class GoodsReceivedBySupplier extends ReportPage
{
    protected static ?string $title = 'Goods received by supplier';

    protected function buildReport(): ReportResult
    {
        return app(BuildGoodsReceivedBySupplier::class)->run($this->rangeFrom(), $this->rangeTo());
    }

    protected function exportSlug(): string
    {
        return 'goods-received-by-supplier';
    }
}
