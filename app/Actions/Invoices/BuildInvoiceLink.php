<?php

namespace App\Actions\Invoices;

use App\Domain\Invoice\InvoiceMessage;
use App\Domain\Money\Lkr;
use App\Models\Invoice;
use App\Models\Order;
use App\Settings\BusinessSettings;

final class BuildInvoiceLink
{
    public function __construct(private BusinessSettings $business) {}

    public function handle(Invoice $invoice, Order $order, string $token): IssuedInvoice
    {
        $url = route('invoices.show', ['number' => $invoice->number, 'token' => $token]);
        $snapshot = $invoice->customer_snapshot;
        $balance = $order->balanceCents();
        $text = InvoiceMessage::whatsApp(
            $snapshot['name'], $this->business->name, $invoice->number,
            Lkr::formatWithCurrency($invoice->total_cents), $url,
            $balance > 0 ? Lkr::formatWithCurrency($balance) : null,
        );

        return new IssuedInvoice($invoice, $token, $url, $text, InvoiceMessage::whatsAppUrl($snapshot['phone_e164'], $text));
    }
}
