<?php

use App\Actions\Orders\TransitionOrder;
use App\Actions\Payments\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    $this->actingAs($this->by);
});

it('shows zero balance for refunded cancelled and expired orders across order and customer screens', function (OrderStatus $status) {
    $order = Order::factory()->create(['status' => $status, 'total_cents' => 16250000, 'paid_cents' => 0, 'refunded_cents' => 16250000]);
    Livewire::test(ListOrders::class)->assertTableColumnStateSet('balance', 0, $order);
    $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->assertOk();
    $balance = $page->instance()->getSchema('infolist')->getComponentByStatePath('balance');
    expect($balance)->toBeInstanceOf(TextEntry::class);
    expect($balance->getState())->toBe(0);
    expect($balance->formatState($balance->getState()))->toBe('Rs 0.00');
    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $order->customer, 'pageClass' => ViewCustomer::class])
        ->assertTableColumnStateSet('balance', 0, $order);
    expect($order->customer->balanceCents())->toBe(0);
    expect($order->customer->newQuery()->withBalance()->findOrFail($order->customer_id)->balance_cents)->toBe(0);
})->with([OrderStatus::Cancelled, OrderStatus::Expired]);

it('filters list tabs and counts all flagged orders', function () {
    $orders = collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => Order::factory()->create(['status' => $status, 'needs_attention' => in_array($status, [OrderStatus::Cancelled, OrderStatus::Dispatched], true)])]);
    $page = Livewire::test(ListOrders::class)->assertCanSeeTableRecords($orders);
    foreach ([
        'quotes' => ['quoted'], 'accepted' => ['confirmed'],
        'in_progress' => ['processing', 'ready_for_collection', 'dispatched'],
        'done' => ['completed', 'cancelled', 'expired'], 'needs_attention' => ['cancelled', 'dispatched'],
    ] as $tab => $statuses) {
        $page->set('activeTab', $tab)->assertCanSeeTableRecords($orders->only($statuses))->assertCanNotSeeTableRecords($orders->except($statuses));
    }
    expect($page->instance()->getTabs()['needs_attention']->getBadge())->toBe('2');
});

it('offers only the supported targets for each active order status', function (string $status, array $expected) {
    $order = Order::factory()->create(['status' => $status]);
    $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionVisible('moveTo')->mountAction('moveTo');
    $component = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('to');
    expect($component)->toBeInstanceOf(Select::class);
    expect(array_keys($component->getOptions()))->toBe($expected);
})->with([
    ['quoted', ['cancelled']], ['confirmed', ['processing', 'cancelled']],
    ['processing', ['ready_for_collection', 'dispatched', 'cancelled']],
    ['ready_for_collection', ['completed', 'cancelled']], ['dispatched', ['completed', 'cancelled']],
]);

it('hides move actions for terminal and placed orders', function (OrderStatus $status) {
    $order = Order::factory()->create(['status' => $status]);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionHidden('moveTo');
})->with([OrderStatus::Placed, OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Expired]);

it('moves an order through its header action', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('moveTo', data: ['to' => 'processing', 'note' => 'Start cutting'])
        ->assertHasNoActionErrors()->assertNotified('Moved to Processing');
    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
    expect($order->events()->sole()->note)->toBe('Start cutting');
});

it('requires a late cancellation reason and shows its labelled result', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched]);
    $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('moveTo', data: ['to' => 'cancelled'])
        ->assertHasActionErrors(['late_cancel_reason' => 'required']);
    expect($order->fresh()->status)->toBe(OrderStatus::Dispatched);
    $page->fillForm(['late_cancel_reason' => 'delivery_refused'], 'mountedActionSchema0')->callMountedAction()
        ->assertHasNoActionErrors()->assertNotified('Moved to Cancelled')->assertSee('Needs attention')->assertSee('Delivery refused');
    expect($order->fresh()->needs_attention)->toBeTrue();
});

it('offers the completion override only to an admin completing with a balance', function (bool $admin) {
    if ($admin) {
        $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    }
    $order = Order::factory()->create(['status' => OrderStatus::Dispatched]);
    $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('moveTo')->fillForm(['to' => 'completed'], 'mountedActionSchema0');
    if ($admin) {
        $page->assertSchemaComponentVisible('complete_override_reason', 'mountedActionSchema0')->assertMountedActionModalSee('Rs 1,250.00 is still owed.')
            ->fillForm(['complete_override_reason' => 'Approved credit'], 'mountedActionSchema0')->callMountedAction()->assertNotified('Moved to Completed');
    } else {
        $page->assertSchemaComponentHidden('complete_override_reason', 'mountedActionSchema0')->callMountedAction()
            ->assertNotified('Rs 1,250.00 is still owed. Record the payment first.')->assertActionMounted('moveTo');
    }
    expect($order->fresh()->status)->toBe($admin ? OrderStatus::Completed : OrderStatus::Dispatched);
})->with([false, true]);

it('hides the override when moving elsewhere or completing a paid order', function (bool $paid) {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $order = Order::factory()->create(['status' => $paid ? OrderStatus::Dispatched : OrderStatus::Processing, 'total_cents' => $paid ? 0 : 125000]);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('moveTo')
        ->fillForm(['to' => $paid ? 'completed' : 'dispatched'], 'mountedActionSchema0')
        ->assertSchemaComponentHidden('complete_override_reason', 'mountedActionSchema0');
})->with([false, true]);

it('defaults payment to the balance and displays its issued receipt', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Quoted]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 25000, null, null, $this->by);
    $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('recordPayment')
        ->assertActionDataSet(['method' => 'cash', 'amount' => '1000.00']);
    $method = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('method');
    expect($method)->toBeInstanceOf(Select::class);
    expect($method->getOptions())->not->toHaveKey('payhere');
    $page->callMountedAction()->assertHasNoActionErrors();
    $receipt = $order->payments()->latest('id')->firstOrFail()->receipt_number;
    $page->assertNotified('Payment recorded — receipt '.$receipt);
    expect($order->fresh()->balanceCents())->toBe(0);
});

it('requires references for transfer and cheque payments', function (string $method) {
    $order = Order::factory()->create();
    Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('recordPayment', data: ['method' => $method, 'amount' => '10.00'])
        ->assertHasActionErrors(['reference' => 'required']);
    expect($order->payments()->count())->toBe(0);
})->with(['bank_transfer', 'cheque']);

it('requires an explicit amount for a cancelled order even when a balance remains', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('recordPayment')
        ->assertActionDataSet(['amount' => null])->callMountedAction()->assertHasActionErrors(['amount' => 'required']);
    expect($order->payments()->count())->toBe(0);
});

it('hides payment for fully paid active orders', function (OrderStatus $status) {
    $order = Order::factory()->create(['status' => $status]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 125000, null, null, $this->by);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionHidden('recordPayment');
})->with([OrderStatus::Quoted, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched]);

it('keeps payment available with a blank required amount on a cancelled order with no balance', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'total_cents' => 0]);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionVisible('recordPayment')->mountAction('recordPayment')
        ->assertActionDataSet(['amount' => null])->callMountedAction()->assertHasActionErrors(['amount' => 'required']);
});

it('records refunds and clears attention through header actions', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000, null, null, $this->by);
    app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $this->by, cancelReason: 'Changed mind');
    $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionVisible('recordRefund')
        ->callAction('recordRefund', data: ['method' => 'cash', 'amount' => '100.00', 'note' => 'Returned advance'])
        ->assertHasNoActionErrors()->assertNotified('Refund recorded');
    expect($order->fresh()->paid_cents)->toBe(0);
    $page->assertActionHidden('recordRefund')->assertActionVisible('clearAttention')->callAction('clearAttention', data: ['note' => 'Refund checked'])
        ->assertHasNoActionErrors()->assertNotified('Attention cleared')->assertActionHidden('clearAttention');
    expect($order->fresh()->needs_attention)->toBeFalse();
});

it('keeps active refund validation in the refund action', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    app(RecordPayment::class)->handle($order, PaymentMethod::Cash, 10000, null, null, $this->by);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('recordRefund', data: ['method' => 'cash', 'amount' => '100.00', 'note' => 'Refund'])
        ->assertNotified('Nothing has been overpaid on this order. Cancel the order or record a return first.')->assertActionMounted('recordRefund');
    expect($order->fresh()->paid_cents)->toBe(10000);
});

it('hides payment on completed and expired orders but allows cancelled late payments', function (string $status, bool $visible) {
    $order = Order::factory()->create(['status' => $status]);
    $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
    if ($visible) {
        $page->assertActionVisible('recordPayment')->callAction('recordPayment', data: ['method' => 'cash', 'amount' => '10.00'])->assertHasNoActionErrors();
        expect($order->fresh()->needs_attention)->toBeTrue();
        expect($order->events()->where('type', 'attention')->sole()->note)->toContain('received after the order was cancelled');
    } else {
        $page->assertActionHidden('recordPayment');
    }
})->with([['completed', false], ['expired', false], ['cancelled', true]]);

it('denies stock staff the order page and hides mutation actions if view alone is granted', function () {
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'needs_attention' => true, 'paid_cents' => 100]);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertForbidden();
    Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, ['view', 'viewAny'], true) ? true : null);
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionHidden('moveTo')->assertActionHidden('recordPayment')
        ->assertActionHidden('recordRefund')->assertActionHidden('clearAttention');
});

it('renders attention and event history newest first with optional fulfilment details', function () {
    $this->freezeSecond();
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    app(TransitionOrder::class)->handle($order, OrderStatus::Processing, $this->by, 'First operation');
    $this->travel(1)->minutes();
    app(TransitionOrder::class)->handle($order, OrderStatus::Dispatched, $this->by, 'Second operation');
    $this->travel(1)->minutes();
    app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, $this->by, cancelReason: 'not_collected');
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertSee('Needs attention')->assertSee('Delivery')
        ->assertSee('Cancelled at')->assertSee('Not collected')->assertSeeInOrder(['Second operation', 'First operation']);
});
