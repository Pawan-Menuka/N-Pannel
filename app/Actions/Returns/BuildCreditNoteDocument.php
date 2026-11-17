<?php

namespace App\Actions\Returns;

use App\Domain\Invoice\VatRate;
use App\Domain\Money\Lkr;
use App\Models\CreditNote;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;

final class BuildCreditNoteDocument
{
    public function __construct(private BusinessSettings $business, private DocumentSettings $documents) {}

    public function handle(CreditNote $creditNote): CreditNoteDocument
    {
        $creditNote->loadMissing(['invoice', 'salesReturn']);
        $lines = [];
        foreach ($creditNote->lines as $line) {
            $lines[] = [
                'description' => $line['description'], 'sku' => $line['sku'], 'qty' => $line['qty'], 'saleUnit' => $line['sale_unit'],
                'unitPrice' => Lkr::formatWithCurrency($line['unit_price_cents']), 'lineTotal' => Lkr::formatWithCurrency($line['line_total_cents']),
            ];
        }

        return new CreditNoteDocument(
            ['name' => $this->business->name, 'address' => $this->business->address, 'phone' => $this->business->phone, 'email' => $this->business->email],
            $creditNote->seller_vat_number, $creditNote->number, $creditNote->issued_on?->format('d M Y') ?? '',
            $creditNote->invoice->number ?? '', $creditNote->customer_snapshot, $lines, Lkr::formatWithCurrency($creditNote->total_cents),
            $creditNote->vat_rate_bp === null ? null : 'VAT included at '.VatRate::format($creditNote->vat_rate_bp).'%: '.Lkr::formatWithCurrency($creditNote->tax_cents),
            $creditNote->salesReturn->reason ?? '', $this->documents->invoice_footer,
        );
    }
}
