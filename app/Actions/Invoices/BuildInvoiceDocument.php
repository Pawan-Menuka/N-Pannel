<?php

namespace App\Actions\Invoices;

use App\Actions\Payments\CanPrintReceipt;
use App\Domain\Invoice\VatRate;
use App\Domain\Money\Lkr;
use App\Enums\DocumentStatus;
use App\Enums\OrderLineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Settings\BankSettings;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;

final class BuildInvoiceDocument
{
    public function __construct(private BusinessSettings $business, private DocumentSettings $documents, private BankSettings $bank) {}

    public function handle(Invoice $invoice): InvoiceDocument
    {
        $order = Order::query()->findOrFail($invoice->order_id);
        $lines = [];
        foreach ($invoice->lines as $line) {
            $discount = $line['kind'] === OrderLineKind::Discount->value;
            $lines[] = [
                'description' => $line['description'], 'sku' => $line['sku'], 'qty' => $line['qty'], 'saleUnit' => $line['sale_unit'],
                'unitPrice' => Lkr::formatWithCurrency($discount ? -abs($line['unit_price_cents']) : $line['unit_price_cents']),
                'lineTotal' => Lkr::formatWithCurrency($discount ? -abs($line['line_total_cents']) : $line['line_total_cents']),
            ];
        }
        $payments = [];
        foreach ($order->payments()->where('status', PaymentStatus::Confirmed)->orderBy('confirmed_at')->orderBy('id')->get() as $payment) {
            $payments[] = [
                'receiptNumber' => $payment->receipt_number,
                'date' => ($payment->confirmed_at ?? $payment->created_at)->copy()->setTimezone(config('app.timezone'))->format('d M Y'),
                'method' => $payment->method->getLabel(),
                'amount' => Lkr::formatWithCurrency($payment->kind === PaymentKind::Refund ? -$payment->amount_cents : $payment->amount_cents),
                'receiptUrl' => CanPrintReceipt::check($payment) ? route('filament.admin.payments.receipt', ['payment' => $payment]) : null,
            ];
        }
        $bank = $this->documents->show_bank_details_on_invoice && trim($this->bank->account_number) !== '' ? [
            'account_name' => $this->bank->account_name, 'bank_name' => $this->bank->bank_name,
            'branch' => $this->bank->branch, 'account_number' => $this->bank->account_number,
        ] : null;

        return new InvoiceDocument(
            ['name' => $this->business->name, 'address' => $this->business->address, 'phone' => $this->business->phone, 'email' => $this->business->email],
            $invoice->vat_rate_bp === null ? 'INVOICE' : 'TAX INVOICE', $invoice->seller_vat_number,
            $invoice->number, $invoice->issued_on?->format('d M Y') ?? '', $invoice->due_on?->format('d M Y'),
            $invoice->customer_snapshot, $lines, Lkr::formatWithCurrency($invoice->subtotal_cents),
            Lkr::formatWithCurrency($invoice->delivery_cents), Lkr::formatWithCurrency($invoice->discount_cents), Lkr::formatWithCurrency($invoice->total_cents),
            $invoice->vat_rate_bp === null ? null : 'VAT included at '.VatRate::format($invoice->vat_rate_bp).'%: '.Lkr::formatWithCurrency($invoice->tax_cents),
            $payments, Lkr::formatWithCurrency($order->paid_cents), Lkr::formatWithCurrency($order->balanceCents()),
            $bank, $this->documents->invoice_footer,
            $invoice->status === DocumentStatus::Void ? 'VOID — '.$invoice->void_reason : null,
            $invoice->creditNotes()->orderBy('issued_on')->orderBy('id')->get()->map(fn (CreditNote $credit): array => [
                'number' => $credit->number, 'date' => $credit->issued_on?->format('d M Y') ?? '', 'amount' => Lkr::formatWithCurrency($credit->total_cents),
            ])->all(),
            $order->returned_cents > 0 ? Lkr::formatWithCurrency($order->returned_cents) : null,
        );
    }
}
