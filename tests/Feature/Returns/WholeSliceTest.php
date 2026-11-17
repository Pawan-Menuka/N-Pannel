<?php

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Orders\AcceptQuoteByStaff;
use App\Actions\Orders\CounterSalePayment;
use App\Actions\Orders\CreateCounterSale;
use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Actions\Payments\RecordPayment;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\ReturnLineInput;
use App\Actions\Returns\ReturnRefundInput;
use App\Enums\OrderLineKind;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Models\Payment;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;

it('receives sells accepts a trade quote invoices and returns one good and one damaged panel', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Colombo'));
    $by = User::factory()->admin()->create();
    $location = Location::factory()->create(['is_default' => true]);
    $variant = Variant::factory()->create(['is_active' => true, 'track_stock' => true, 'price_cents' => 10000, 'trade_price_cents' => 8000]);
    $receipt = GoodsReceipt::factory()->create(['location_id' => $location->id]);
    GoodsReceiptLine::factory()->for($receipt)->create(['variant_id' => $variant->id, 'qty' => 50, 'unit_cost_cents' => 5000, 'line_total_cents' => 250000]);
    $posted = app(PostGoodsReceipt::class)->handle($receipt, $by);
    $customer = Customer::factory()->create();
    $sale = app(CreateCounterSale::class)->handle(
        $customer, [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 12, null)], $by,
        new CounterSalePayment(PaymentMethod::Cash, 20000, null, null),
    );
    $contractor = Customer::factory()->create(['price_tier' => 'trade']);
    $quote = app(CreateQuote::class)->handle($contractor, $by);
    $quote = app(SaveQuoteLines::class)->handle($quote, [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 30, null)], null, null, CarbonImmutable::today()->addDays(7), $by);
    expect($quote->total_cents)->toBe(240000);
    app(SendQuote::class)->handle($quote, $by);
    app(AcceptQuoteByStaff::class)->handle($quote, $by);
    app(RecordPayment::class)->handle($sale, PaymentMethod::Cash, 100000, null, null, $by);
    $issued = app(IssueInvoice::class)->handle($sale, null, $by);
    $line = $sale->lines()->sole();
    $return = app(CreateReturn::class)->handle($sale, [new ReturnLineInput($line->id, 1, 10000, true), new ReturnLineInput($line->id, 1, 10000, false)], 'One unused, one damaged panel', null, new ReturnRefundInput(PaymentMethod::Cash, 20000), $by);

    expect(StockLevel::query()->where('variant_id', $variant->id)->where('location_id', $location->id)->sole()->qty)->toBe(9);
    expect(StockMovement::query()->where('variant_id', $variant->id)->sum('delta'))->toBe(9);
    expect($sale->refresh()->balanceCents())->toBe(0);
    expect($sale->total_cents)->toBe(120000);
    expect($sale->returned_cents)->toBe(20000);
    expect($posted->number)->toBe('GRN-2026-00001');
    expect($issued->invoice->number)->toBe('INV-2026-00001');
    expect(Payment::query()->whereNotNull('receipt_number')->orderBy('id')->pluck('receipt_number')->all())->toBe(['RCP-2026-00001', 'RCP-2026-00002']);
    expect($return->creditNote->number)->toBe('CRN-2026-00001');
    expect($return->lines)->toHaveCount(2);
    $this->get($issued->url)->assertOk()->assertSee('CRN-2026-00001');
});
