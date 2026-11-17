<?php

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Payments\RecordPayment;
use App\Actions\Returns\CreateReturn;
use App\Actions\Returns\ReturnLineInput;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SalesReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Colombo'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    $this->actingAs($this->by);
    $this->order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'subtotal_cents' => 4000, 'total_cents' => 4000]);
    $this->line = OrderLine::factory()->for($this->order)->create(['sku' => 'UI-PANEL', 'description' => 'UI panel', 'qty' => 4, 'unit_price_cents' => 1000, 'line_total_cents' => 4000]);
});

it('prefills only returnable product lines with remaining quantities and original prices', function () {
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 1, 1000, false)], 'First', null, null, $this->by);
    OrderLine::factory()->for($this->order)->create(['kind' => OrderLineKind::Labour, 'description' => 'Excluded labour']);
    $page = Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertActionVisible('recordReturn')->mountAction('recordReturn');
    $rows = array_values($page->get('mountedActions.0.data.lines'));
    expect($rows)->toHaveCount(1);
    expect($rows[0]['order_line_id'])->toBe($this->line->id);
    expect($rows[0]['item'])->toBe('UI-PANEL — UI panel (sold 4, returnable 3)');
    expect($rows[0]['qty'])->toBe(0);
    expect($rows[0]['restock'])->toBeTrue();
    expect($rows[0]['unit_value'])->toBe('10.00');
});

it('submits a return ignoring zero quantity rows and displays its credit note on order and invoice', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $other = OrderLine::factory()->for($this->order)->create(['qty' => 1, 'unit_price_cents' => 0, 'line_total_cents' => 0]);
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->callAction('recordReturn', data: [
        'lines' => [
            ['order_line_id' => $this->line->id, 'returnable_qty' => 4, 'qty' => 2, 'unit_value' => '10.00', 'restock' => false],
            ['order_line_id' => $other->id, 'returnable_qty' => 1, 'qty' => 0, 'unit_value' => '0.00', 'restock' => true],
        ], 'reason' => 'UI surplus', 'note' => '', 'refund' => false,
    ])->assertHasNoActionErrors()->assertNotified('Return recorded — credit note CRN-2026-00001');
    $return = SalesReturn::query()->sole();
    expect($return->lines)->toHaveCount(1);
    $url = route('filament.admin.credit-notes.print', ['creditNote' => $return->creditNote]);
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertOk()
        ->assertSee('Returns')->assertSee('Returned')->assertSee('UI surplus')->assertSee('Damaged')->assertSee('CRN-2026-00001')->assertSee('Print credit note')->assertSeeHtml($url);
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertOk()->assertSee('Credit notes')->assertSee('CRN-2026-00001')->assertSeeHtml($url);
    Livewire::test(ListOrders::class)->assertTableColumnStateSet('balance', 2000, $this->order);
});

it('updates the refund helper live and records a linked refund', function () {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 4000, null, null, $this->by);
    $page = Livewire::test(ViewOrder::class, ['record' => $this->order->id])->mountAction('recordReturn');
    $rows = $page->get('mountedActions.0.data.lines');
    $key = array_key_first($rows);
    $page->set('mountedActions.0.data.refund', true)
        ->set('mountedActions.0.data.lines.'.$key.'.qty', 2)->assertMountedActionModalSee('Up to Rs 20.00 can be refunded')
        ->set('mountedActions.0.data.lines.'.$key.'.unit_value', '8.00')->assertMountedActionModalSee('Up to Rs 16.00 can be refunded')
        ->set('mountedActions.0.data.refund_amount', '16.00')->set('mountedActions.0.data.reason', 'Refund from UI')->callMountedAction()
        ->assertHasNoActionErrors()->assertNotified('Return recorded');
    $return = SalesReturn::query()->sole();
    expect($return->refund_cents)->toBe(1600);
    expect($return->refund?->amount_cents)->toBe(1600);
});

it('prefills the discounted unit value and refuses a higher value before accepting all remaining units', function () {
    $this->order->update(['subtotal_cents' => 500000, 'discount_cents' => 100000, 'total_cents' => 400000]);
    $this->line->update(['qty' => 5, 'unit_price_cents' => 100000, 'line_total_cents' => 500000]);
    $page = Livewire::test(ViewOrder::class, ['record' => $this->order->id])->mountAction('recordReturn');
    $rows = $page->get('mountedActions.0.data.lines');
    $key = array_key_first($rows);
    expect($rows[$key]['unit_value'])->toBe('800.00');
    $page->set('mountedActions.0.data.lines.'.$key.'.qty', 1)
        ->set('mountedActions.0.data.lines.'.$key.'.restock', false)
        ->set('mountedActions.0.data.lines.'.$key.'.unit_value', '800.01')
        ->set('mountedActions.0.data.reason', 'Discounted return')
        ->callMountedAction()->assertNotified('The return unit value cannot exceed the sale price.')
        ->assertActionMounted('recordReturn');
    $this->assertDatabaseCount('sales_returns', 0);
    $page->set('mountedActions.0.data.lines.'.$key.'.qty', 5)
        ->set('mountedActions.0.data.lines.'.$key.'.unit_value', '800.00')
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Return recorded');
    expect($this->order->refresh()->returned_cents)->toBe(400000);
});

it('shows domain validation in a danger notification and keeps the modal open', function () {
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->callAction('recordReturn', data: [
        'lines' => [['order_line_id' => $this->line->id, 'returnable_qty' => 4, 'qty' => 1, 'unit_value' => '10.01', 'restock' => false]],
        'reason' => 'Too high', 'refund' => false,
    ])->assertNotified('The return unit value cannot exceed the sale price.')->assertActionMounted('recordReturn');
    $this->assertDatabaseCount('sales_returns', 0);
});

it('can defer the refund and later refund the credited overpayment through the existing modal', function () {
    app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 4000, null, null, $this->by);
    app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 4, 1000, false)], 'Full return', null, null, $this->by);
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertActionHidden('recordReturn')->assertActionVisible('recordRefund')
        ->callAction('recordRefund', data: ['method' => 'cash', 'amount' => '40.00', 'note' => 'Deferred refund'])
        ->assertHasNoActionErrors()->assertNotified('Refund recorded');
    expect($this->order->refresh()->paid_cents)->toBe(0);
    expect($this->order->balanceCents())->toBe(0);
});

it('hides the return action for quotes cancelled expired and fully returned orders', function (string $case) {
    if ($case === 'returned') {
        app(CreateReturn::class)->handle($this->order, [new ReturnLineInput($this->line->id, 4, 1000, false)], 'All returned', null, null, $this->by);
    } else {
        $this->order->update(['status' => $case]);
    }
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertActionHidden('recordReturn');
})->with(['quoted', 'cancelled', 'expired', 'returned']);

it('hides empty returns and returned totals', function () {
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertDontSee('Returns')->assertSchemaComponentHidden('returned_cents', 'infolist');
});
