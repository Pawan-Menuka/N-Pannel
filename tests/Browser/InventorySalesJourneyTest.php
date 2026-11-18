<?php

use App\Enums\GoodsReceiptStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Tests\Support\BrowserJourney;

it('receives fifty tracked panels and records their last cost through the UI', function () {
    $journey = new BrowserJourney;

    $receipt = $journey->receive();

    expect($receipt->status)->toBe(GoodsReceiptStatus::Posted);
    expect($receipt->number)->toMatch('/^GRN-\d{4}-00001$/');
    expect($receipt->lines()->sole()->qty)->toBe(50);
    expect($journey->variant->refresh()->last_cost_cents)->toBe(5000);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $journey->variant->id, 'location_id' => $journey->location->id, 'qty' => 50]);
});

it('confirms a named counter sale with a partial cash payment through the UI', function () {
    $journey = new BrowserJourney;
    $journey->receive();

    $sale = $journey->sell();

    expect($sale->status)->toBe(OrderStatus::Confirmed);
    expect($sale->balanceCents())->toBe(100000);
    expect($sale->customer_name)->toBe('Journey Retail Customer');
    $payment = $sale->payments()->sole();
    expect($payment->method)->toBe(PaymentMethod::Cash);
    expect($payment->amount_cents)->toBe(20000);
    expect($payment->receipt_number)->toMatch('/^RCP-\d{4}-00001$/');
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $journey->variant->id, 'qty' => 38]);
});

it('prefills trade prices shares a public quote and accepts thirty panels through the UI', function () {
    $journey = new BrowserJourney;
    $journey->receive();
    $journey->sell();

    $quote = $journey->acceptTradeQuote();

    expect($quote->status)->toBe(OrderStatus::Confirmed);
    expect($quote->total_cents)->toBe(240000);
    expect($quote->lines()->sole()->unit_price_cents)->toBe(8000);
    expect($quote->quote_revision)->toBe(1);
    expect($quote->quoteSnapshots()->count())->toBe(1);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $journey->variant->id, 'qty' => 8]);
});

it('collects the counter balance issues an invoice and opens its print page through the UI', function () {
    $journey = new BrowserJourney;
    $journey->receive();
    $sale = $journey->sell();
    $journey->acceptTradeQuote();

    $journey->invoice($sale);

    expect($sale->refresh()->balanceCents())->toBe(0);
    expect($sale->activeInvoice()->number)->toMatch('/^INV-\d{4}-00001$/');
    $year = now('Asia/Colombo')->year;
    expect(Payment::query()->orderBy('id')->pluck('receipt_number')->all())
        ->toBe(["RCP-{$year}-00001", "RCP-{$year}-00002"]);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $journey->variant->id, 'qty' => 8]);
});

it('returns one usable and one damaged invoiced panel with refunds and finishes at nine through the UI', function () {
    $journey = new BrowserJourney;
    $journey->receive();
    $sale = $journey->sell();
    $journey->acceptTradeQuote();
    $journey->invoice($sale);

    $journey->returnPanel($sale, restock: true);
    $journey->returnPanel($sale, restock: false);

    expect(StockLevel::query()->sole()->qty)->toBe(9);
    expect(StockMovement::query()->where('variant_id', $journey->variant->id)->sum('delta'))->toBe(9);
    expect($sale->refresh()->returned_cents)->toBe(20000);
    expect($sale->balanceCents())->toBe(0);
    expect((int) SalesReturn::query()->sum('refund_cents'))->toBe(20000);
    expect((int) Payment::query()->where('kind', PaymentKind::Refund)->sum('amount_cents'))->toBe(20000);
    $year = now('Asia/Colombo')->year;
    expect(CreditNote::query()->orderBy('id')->pluck('number')->all())
        ->toBe(["CRN-{$year}-00001", "CRN-{$year}-00002"]);
    $this->assertDatabaseHas('sales_return_lines', ['restock' => true, 'qty' => 1]);
    $this->assertDatabaseHas('sales_return_lines', ['restock' => false, 'qty' => 1]);
});
