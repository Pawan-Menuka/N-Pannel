<?php

namespace App\Actions\Payments;

use App\Domain\Money\Lkr;
use App\Models\Order;
use App\Models\Payment;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;

final class BuildReceiptDocument
{
    public function __construct(private BusinessSettings $business, private DocumentSettings $documents) {}

    public function handle(Payment $payment): ReceiptDocument
    {
        abort_unless(CanPrintReceipt::check($payment), 404);
        $order = Order::query()->findOrFail($payment->order_id);

        return new ReceiptDocument(
            ['name' => $this->business->name, 'address' => $this->business->address, 'phone' => $this->business->phone, 'email' => $this->business->email],
            $payment->receipt_number ?? '', ($payment->confirmed_at ?? $payment->created_at)->copy()->setTimezone(config('app.timezone'))->format('d M Y'),
            $order->customer_name, $order->reference, $order->activeInvoice()?->number,
            $payment->method->getLabel(), $payment->reference, Lkr::formatWithCurrency($payment->amount_cents),
            Lkr::formatWithCurrency($order->total_cents), Lkr::formatWithCurrency($order->paid_cents), Lkr::formatWithCurrency($order->balanceCents()),
            $this->documents->invoice_footer,
            $order->returned_cents > 0 ? Lkr::formatWithCurrency($order->returned_cents) : null,
        );
    }
}
