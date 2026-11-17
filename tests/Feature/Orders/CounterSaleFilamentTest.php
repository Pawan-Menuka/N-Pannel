<?php

use App\Actions\Customers\ResolveCustomer;
use App\Enums\OrderLineKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\NewSale;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
    $this->location = Location::factory()->create(['is_default' => true]);
    $this->variant = Variant::factory()->create(['price_cents' => 10000, 'trade_price_cents' => 8000]);
    StockLevel::factory()->create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 10]);
    $this->saleData = [
        'walk_in' => true, 'take_payment' => true,
        'lines' => [['kind' => 'product', 'variant_id' => $this->variant->id, 'qty' => 2, 'unit_price' => '100.00']],
        'payment' => ['method' => 'cash', 'amount' => '', 'reference' => null, 'note' => null],
    ];
});

it('allows admin and sales to list view and open new sales', function (string $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));
    $order = Order::factory()->create();
    foreach (['index', 'new-sale', 'view'] as $page) {
        $this->get(OrderResource::getUrl($page, ['record' => $order]))->assertOk();
    }
    Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order])->assertActionVisible('newSale');
    Livewire::test(NewSale::class)->assertOk()->assertSchemaComponentHidden('stock_override_reason');
})->with(['admin', 'sales']);

it('forbids stock staff all order pages', function () {
    $order = Order::factory()->create();
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    expect(OrderResource::canAccess())->toBeFalse();
    foreach (['index', 'new-sale', 'view'] as $page) {
        $this->get(OrderResource::getUrl($page, ['record' => $order]))->assertForbidden();
    }
    Livewire::test(NewSale::class)->assertForbidden();
});

it('completes a walk in cash sale defaults blank payment to total and redirects to its read only view', function () {
    $page = Livewire::test(NewSale::class)->fillForm($this->saleData)->call('completeSale')->assertHasNoFormErrors();
    $order = Order::sole();

    expect($order->payment_state)->toBe(PaymentState::Paid);
    expect($order->paid_cents)->toBe(20000);
    $page->assertNotified('Sale '.$order->reference.' recorded — receipt '.$order->payments()->sole()->receipt_number)
        ->assertRedirect(OrderResource::getUrl('view', ['record' => $order]));
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertOk()
        ->assertDontSee('Phone')->assertDontSee('Email')
        ->assertSchemaComponentHidden('phone_e164', 'infolist')->assertSchemaComponentHidden('email', 'infolist')
        ->assertSee($this->variant->sku)->assertSee($order->payments()->sole()->receipt_number)
        ->assertSee('Counter sale')->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete');
});

it('shows order contact entries when populated', function () {
    $order = Order::factory()->create(['phone_e164' => '+94771234567', 'email' => 'customer@example.com']);

    Livewire::test(ViewOrder::class, ['record' => $order->id])
        ->assertSchemaComponentVisible('phone_e164', 'infolist')->assertSchemaComponentVisible('email', 'infolist')
        ->assertSee('Phone')->assertSee('+94771234567')->assertSee('Email')->assertSee('customer@example.com');
});

it('renders and submits line and payment state supplied as strings or enum cases', function (OrderLineKind|string $kind, PaymentMethod|string $method) {
    $data = $this->saleData;
    $data['lines'][0]['kind'] = $kind;
    $data['payment']['method'] = $method;
    $page = Livewire::test(NewSale::class)->fillForm($data)
        ->assertSeeHtml('wire:submit="completeSale"')->assertSee('Complete sale')
        ->assertSchemaComponentStateSet('totals', 'Subtotal: Rs 200.00 | Delivery: Rs 0.00 | Discount: Rs 0.00 | Total: Rs 200.00');
    $key = array_key_first($page->get('data.lines'));
    $page->assertFormFieldVisible('lines.'.$key.'.variant_id')
        ->assertFormFieldHidden('lines.'.$key.'.description')
        ->call('completeSale')->assertHasNoFormErrors();

    expect(Order::sole()->payments()->sole()->method)->toBe(PaymentMethod::Cash);
})->with([
    'strings' => ['product', 'cash'],
    'enum cases' => [OrderLineKind::Product, PaymentMethod::Cash],
]);

it('requires a reference for bank transfer state supplied as a string or enum case', function (PaymentMethod|string $method) {
    $data = $this->saleData;
    $data['payment']['method'] = $method;
    Livewire::test(NewSale::class)->fillForm($data)->call('completeSale')->assertHasFormErrors(['payment.reference']);

    $this->assertDatabaseCount('orders', 0);
})->with(['bank_transfer', PaymentMethod::BankTransfer]);

it('reveals the stock override after shortage and succeeds when resubmitted with a reason', function () {
    StockLevel::query()->where('variant_id', $this->variant->id)->update(['qty' => 1]);
    $page = Livewire::test(NewSale::class)->fillForm($this->saleData)->call('completeSale')
        ->assertHasFormErrors(['lines'])->assertSet('showStockOverride', true)
        ->assertSchemaComponentVisible('stock_override_reason');
    $this->assertDatabaseCount('orders', 0);
    $page->set('data.stock_override_reason', 'Counted stock awaiting entry')->call('completeSale')->assertHasNoFormErrors();
    expect(StockMovement::sole()->is_override)->toBeTrue();
    $this->assertDatabaseCount('orders', 1);
});

it('updates totals live when quantities prices and line kinds change', function () {
    $page = Livewire::test(NewSale::class)->fillForm($this->saleData)
        ->assertSchemaComponentStateSet('totals', 'Subtotal: Rs 200.00 | Delivery: Rs 0.00 | Discount: Rs 0.00 | Total: Rs 200.00');
    $lines = $page->get('data.lines');
    $key = array_key_first($lines);
    $page->set('data.lines.'.$key.'.qty', 3)
        ->assertSchemaComponentStateSet('totals', 'Subtotal: Rs 300.00 | Delivery: Rs 0.00 | Discount: Rs 0.00 | Total: Rs 300.00');
    $page->set('data.lines.'.$key.'.unit_price', '125.50')
        ->assertSchemaComponentStateSet('totals', 'Subtotal: Rs 376.50 | Delivery: Rs 0.00 | Discount: Rs 0.00 | Total: Rs 376.50');
    $page->set('data.lines.'.$key.'.unit_price', 'invalid')->assertSee('—');
    $page->fillForm(['lines' => [
        ['kind' => 'product', 'variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => '100.00'],
        ['kind' => 'delivery', 'description' => 'Delivery', 'qty' => 1, 'unit_price' => '25.50'],
        ['kind' => 'discount', 'description' => 'Discount', 'qty' => 1, 'unit_price' => '5.00'],
    ]])->assertSchemaComponentStateSet('totals', 'Subtotal: Rs 100.00 | Delivery: Rs 25.50 | Discount: Rs 5.00 | Total: Rs 120.50');
    $this->assertDatabaseCount('orders', 0);
});

it('prefills the selected customers trade price and keeps decimal rupee input exact', function () {
    $customer = Customer::factory()->create(['price_tier' => 'trade']);
    $page = Livewire::test(NewSale::class)->fillForm(['walk_in' => false, 'customer_id' => $customer->id, 'take_payment' => false]);
    $key = array_key_first($page->get('data.lines'));
    $page->set('data.lines.'.$key.'.variant_id', $this->variant->id)
        ->assertSet('data.lines.'.$key.'.unit_price', '80.00')->assertSee('Price tier: Trade');
    $page->set('data.lines.'.$key.'.unit_price', '125.50')->call('completeSale')->assertHasNoFormErrors();

    expect(Order::sole()->lines()->sole()->unit_price_cents)->toBe(12550);
});

it('reprices default product prices when switching customers and preserves manual edits', function (bool $manual) {
    $customer = Customer::factory()->create(['price_tier' => 'trade']);
    $page = Livewire::test(NewSale::class);
    $key = array_key_first($page->get('data.lines'));
    $page->set('data.lines.'.$key.'.variant_id', $this->variant->id)->assertSet('data.lines.'.$key.'.unit_price', '100.00');
    if ($manual) {
        $page->set('data.lines.'.$key.'.unit_price', '95.50');
    }
    $page->set('data.walk_in', false)->set('data.customer_id', $customer->id)
        ->assertSet('data.lines.'.$key.'.unit_price', $manual ? '95.50' : '80.00');
    $page->set('data.walk_in', true)->assertSet('data.lines.'.$key.'.unit_price', $manual ? '95.50' : '100.00');
})->with([false, true]);

it('reprices defaults when changing between named customers', function () {
    $retail = Customer::factory()->create(['price_tier' => 'retail']);
    $trade = Customer::factory()->create(['price_tier' => 'trade']);
    $page = Livewire::test(NewSale::class)->fillForm(['walk_in' => false, 'customer_id' => $retail->id]);
    $key = array_key_first($page->get('data.lines'));
    $page->set('data.lines.'.$key.'.variant_id', $this->variant->id)
        ->set('data.customer_id', $trade->id)->assertSet('data.lines.'.$key.'.unit_price', '80.00')
        ->set('data.customer_id', $retail->id)->assertSet('data.lines.'.$key.'.unit_price', '100.00');
});

it('invalidates a shortage override when lines or the customer change', function (string $field) {
    StockLevel::query()->where('variant_id', $this->variant->id)->update(['qty' => 1]);
    $customer = Customer::factory()->create(['price_tier' => 'retail']);
    $data = $this->saleData;
    if ($field === 'customer_id') {
        $data['walk_in'] = false;
        $data['customer_id'] = Customer::factory()->create(['price_tier' => 'retail'])->id;
    }
    $page = Livewire::test(NewSale::class)->fillForm($data)->call('completeSale')
        ->assertHasFormErrors(['lines'])->assertSet('showStockOverride', true)
        ->set('data.stock_override_reason', 'Approve the original shortage');
    $key = array_key_first($page->get('data.lines'));
    if ($field === 'qty') {
        $page->set('data.lines.'.$key.'.qty', 3);
    } elseif ($field === 'walk_in') {
        $page->set('data.walk_in', false)->set('data.customer_id', $customer->id);
    } else {
        $page->set('data.customer_id', $customer->id);
    }
    $page->assertSet('showStockOverride', false)->assertSet('data.stock_override_reason', null)
        ->assertSchemaComponentHidden('stock_override_reason')
        ->call('completeSale')->assertHasFormErrors(['lines'])->assertSet('showStockOverride', true)
        ->assertSee($this->variant->sku.': wanted '.($field === 'qty' ? 3 : 2).', in stock 1');
    $this->assertDatabaseCount('orders', 0);
})->with(['qty', 'walk_in', 'customer_id']);

it('loads line variants customer location and preview totals once per render', function () {
    $customer = Customer::factory()->create(['price_tier' => 'trade']);
    $variants = Variant::factory()->count(3)->create(['track_stock' => false]);
    $lines = [];
    foreach ($variants as $variant) {
        $lines[] = ['kind' => 'product', 'variant_id' => $variant->id, 'qty' => 1, 'unit_price' => null];
    }
    $page = Livewire::test(NewSale::class)->fillForm(['walk_in' => false, 'customer_id' => $customer->id, 'lines' => $lines]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_starts_with($query->sql, 'select')) {
            $queries[] = $query->sql;
        }
    });
    $page->call('$refresh')->assertOk();

    foreach (['customers', 'locations', 'variants'] as $table) {
        expect(count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"'))))->toBe(1, $table);
    }
    $this->assertDatabaseCount('orders', 0);
});

it('filters customer and variant searches and labels stock only at the default location', function () {
    $customer = Customer::factory()->create(['name' => 'Picker customer', 'phone_e164' => '+94771234567']);
    $merged = Customer::factory()->create(['name' => 'Picker merged', 'merged_into_id' => $customer->id]);
    $removed = Customer::factory()->create(['name' => 'Picker removed', 'anonymised_at' => now()]);
    $walkIn = app(ResolveCustomer::class)->walkIn();
    $inactive = Variant::factory()->create(['is_active' => false]);
    $untracked = Variant::factory()->create(['track_stock' => false]);
    StockLevel::factory()->create(['variant_id' => $this->variant->id, 'qty' => 999]);
    $page = Livewire::test(NewSale::class)->set('data.walk_in', false);
    $customerSelect = $page->instance()->form->getComponentByStatePath('customer_id');
    expect($customerSelect)->toBeInstanceOf(Select::class);
    $results = $customerSelect->getSearchResults('');
    expect(array_keys($results))->toContain($customer->id)->not->toContain($merged->id, $removed->id, $walkIn->id);
    expect($results[$customer->id])->toBe('Picker customer — +94771234567');
    expect(array_keys($customerSelect->getSearchResults('0771234567')))->toBe([$customer->id]);

    $key = array_key_first($page->get('data.lines'));
    $variantSelect = $page->instance()->form->getComponentByStatePath('lines.'.$key.'.variant_id');
    expect($variantSelect)->toBeInstanceOf(Select::class);
    $variants = $variantSelect->getSearchResults('ceiling');
    expect(array_keys($variants))->toContain($this->variant->id, $untracked->id)->not->toContain($inactive->id);
    expect($variants[$this->variant->id])->toContain('(in stock 10)');
    expect($variants[$untracked->id])->toContain('(not tracked)');
    expect(array_keys($variantSelect->getSearchResults($this->variant->sku)))->toBe([$this->variant->id]);
});

it('adds or finds a customer inline by normalized phone without creating duplicates', function () {
    $action = TestAction::make('createOption')->schemaComponent('customer_id');
    $page = Livewire::test(NewSale::class)->set('data.walk_in', false)
        ->callAction($action, data: ['phone_e164' => '0771234567', 'name' => 'Nimal'])->assertHasNoActionErrors();
    $customer = Customer::query()->where('is_walk_in', false)->sole();
    expect($customer->phone_e164)->toBe('+94771234567');
    $page->assertSet('data.customer_id', $customer->id)
        ->callAction($action, data: ['phone_e164' => '+94 77 123 4567', 'name' => 'Nimal'])->assertHasNoActionErrors();
    expect(Customer::query()->where('is_walk_in', false)->count())->toBe(1);
    $page->callAction($action, data: ['phone_e164' => 'bad', 'name' => 'Other'])->assertHasActionErrors(['phone_e164']);
});

it('keeps new sales visible on the existing customer orders panel', function () {
    $customer = Customer::factory()->create();
    Livewire::test(NewSale::class)->fillForm([...$this->saleData, 'walk_in' => false, 'customer_id' => $customer->id, 'take_payment' => false])
        ->call('completeSale')->assertHasNoFormErrors();
    $order = Order::sole();
    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])->assertCanSeeTableRecords([$order]);
});

it('maps payment and customer validation to fields without exposing a stock override', function () {
    Livewire::test(NewSale::class)->fillForm([...$this->saleData, 'payment' => ['method' => 'cash', 'amount' => '1.00']])
        ->call('completeSale')->assertHasFormErrors(['payment.amount'])->assertSet('showStockOverride', false);
    $this->assertDatabaseCount('orders', 0);
    Livewire::test(NewSale::class)->fillForm([...$this->saleData, 'walk_in' => false, 'customer_id' => null])
        ->call('completeSale')->assertHasFormErrors(['customer_id']);
});

it('searches orders by reference customer and phone and filters status and source', function () {
    $order = Order::factory()->create(['reference' => 'O-234567', 'customer_name' => 'Counter Customer', 'phone_e164' => '+94771234567', 'source' => 'counter', 'status' => 'confirmed']);
    $quote = Order::factory()->create(['source' => 'quote', 'status' => 'quoted']);
    foreach (['O-234567', 'Counter Customer', '94771234567'] as $search) {
        Livewire::test(ListOrders::class)->searchTable($search)->assertCanSeeTableRecords([$order])->assertCanNotSeeTableRecords([$quote]);
    }
    Livewire::test(ListOrders::class)->filterTable('source', 'counter')->assertCanSeeTableRecords([$order])->assertCanNotSeeTableRecords([$quote]);
    Livewire::test(ListOrders::class)->filterTable('status', 'confirmed')->assertCanSeeTableRecords([$order])->assertCanNotSeeTableRecords([$quote]);
});
