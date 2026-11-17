<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\SalesByProduct as BuildSalesByProduct;
use App\Domain\Reports\ReportResult;

class SalesByProduct extends ReportPage
{
    protected static ?string $title = 'Sales by product';

    public function reportNote(): ?string
    {
        return 'Product values use the recorded line totals. Delivery and order-level discounts are not allocated to products. Unknown costs leave net cost and margin unknown.';
    }

    protected function buildReport(): ReportResult
    {
        return app(BuildSalesByProduct::class)->run($this->rangeFrom(), $this->rangeTo());
    }

    protected function exportSlug(): string
    {
        return 'sales-by-product';
    }
}
