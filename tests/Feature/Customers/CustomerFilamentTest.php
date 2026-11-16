<?php

use App\Actions\Customers\ResolveCustomer;
use App\Enums\OrderStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\PaymentsRelationManager;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->sales()->withAppAuthentication()->create());
});

it('creates and edits customers through the resource and displays field errors', function () {
    Livewire::test(CreateCustomer::class)->fillForm(['name' => 'Nimal', 'phone_e164' => '0771234567'])
        ->call('create')->assertHasNoFormErrors();
    $customer = Customer::sole();
    expect($customer->phone_e164)->toBe('+94771234567');
    Livewire::test(EditCustomer::class, ['record' => $customer->id])->fillForm(['name' => 'Changed', 'city' => 'Kandy'])
        ->call('save')->assertHasNoFormErrors();
    expect($customer->fresh()->name)->toBe('Changed');
    expect($customer->fresh()->city)->toBe('Kandy');
    Livewire::test(EditCustomer::class, ['record' => $customer->id])->fillForm(['phone_e164' => 'bad'])
        ->call('save')->assertHasFormErrors(['phone_e164']);
    Livewire::test(CreateCustomer::class)->fillForm(['name' => 'Name', 'phone_e164' => 'bad'])
        ->call('create')->assertHasFormErrors(['phone_e164']);
});

it('redirects duplicate creation to the existing customer with an information notification', function () {
    $customer = Customer::factory()->create(['phone_e164' => '+94771234567', 'name' => 'Nimal', 'city' => 'Kandy']);
    Livewire::test(CreateCustomer::class)->fillForm(['name' => 'Other', 'phone_e164' => '077 123 4567', 'city' => 'Colombo'])
        ->call('create')->assertHasNoFormErrors()->assertNotified('Already a customer: Nimal')
        ->assertRedirect(CustomerResource::getUrl('view', ['record' => $customer]));
    $this->assertDatabaseCount('customers', 1);
    expect($customer->fresh()->name)->toBe('Nimal');
    expect($customer->fresh()->city)->toBe('Kandy');
});

it('finds and adds customers from the header action', function () {
    Livewire::test(ListCustomers::class)->callAction('findOrAdd', data: ['phone_e164' => '0771234567', 'name' => 'Nimal', 'type' => 'homeowner', 'price_tier' => 'retail'])
        ->assertHasNoActionErrors()->assertNotified('Added Nimal');
    $customer = Customer::sole();
    Livewire::test(ListCustomers::class)->callAction('findOrAdd', data: ['phone_e164' => '+94 77 123 4567', 'name' => 'Nimal', 'type' => 'homeowner', 'price_tier' => 'retail'])
        ->assertHasNoActionErrors()->assertNotified('Found Nimal')
        ->assertRedirect(CustomerResource::getUrl('view', ['record' => $customer]));
    $this->assertDatabaseCount('customers', 1);
    Livewire::test(ListCustomers::class)->callAction('findOrAdd', data: ['phone_e164' => 'invalid', 'name' => 'Name', 'type' => 'homeowner', 'price_tier' => 'retail'])
        ->assertHasActionErrors(['phone_e164']);
});

it('searches local phone formats names company names and partial digits', function () {
    $customer = Customer::factory()->create(['phone_e164' => '+94771234567', 'name' => 'Nimal Perera', 'company_name' => 'Ceiling Supplies']);
    $other = Customer::factory()->create(['name' => 'Other', 'phone_e164' => '+94779876543', 'company_name' => null]);
    foreach (['0771234567', '+94 77 123 4567', 'Nimal', 'Ceiling', '12345'] as $search) {
        Livewire::test(ListCustomers::class)->searchTable($search)->assertCanSeeTableRecords([$customer])->assertCanNotSeeTableRecords([$other]);
    }
});

it('sorts and filters customers by their aggregate balance without counting quotes', function () {
    $debtor = Customer::factory()->create();
    $largerDebtor = Customer::factory()->create();
    $paid = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $debtor->id, 'status' => OrderStatus::Confirmed, 'total_cents' => 10000, 'paid_cents' => 5000]);
    Order::factory()->create(['customer_id' => $largerDebtor->id, 'status' => OrderStatus::Completed, 'total_cents' => 20000, 'paid_cents' => 5000]);
    Order::factory()->create(['customer_id' => $paid->id, 'status' => OrderStatus::Quoted]);
    Livewire::test(ListCustomers::class)->sortTable('balance_cents', 'desc')->assertCanSeeTableRecords([$largerDebtor, $debtor, $paid], inOrder: true);
    Livewire::test(ListCustomers::class)->filterTable('owes_money')->assertCanSeeTableRecords([$debtor, $largerDebtor])->assertCanNotSeeTableRecords([$paid]);
});

it('shows customer orders and payments as read only without unrelated payments', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);
    $payment = Payment::factory()->create(['order_id' => $order->id]);
    $unrelated = Payment::factory()->create();
    Livewire::test(ViewCustomer::class, ['record' => $customer->id])->assertOk()->assertSee($customer->name);
    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$order])->assertTableActionDoesNotExist('create')->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$payment])->assertCanNotSeeTableRecords([$unrelated])
        ->assertTableActionDoesNotExist('create')->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
});

it('hides merged customers and displays anonymised names without edit access', function () {
    $removed = Customer::factory()->create(['name' => 'Private old name', 'anonymised_at' => now()]);
    $merged = Customer::factory()->create(['merged_into_id' => $removed->id]);
    Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$removed])->assertCanNotSeeTableRecords([$merged])
        ->assertSee('Removed customer')->assertDontSee('Private old name');
    Livewire::test(ViewCustomer::class, ['record' => $removed->id])->assertSee('Removed customer')->assertDontSee('Private old name')->assertActionHidden('edit');
    $this->get(CustomerResource::getUrl('edit', ['record' => $removed]))->assertForbidden();
    $walkIn = app(ResolveCustomer::class)->walkIn();
    $this->get(CustomerResource::getUrl('edit', ['record' => $walkIn]))->assertForbidden();
});

it('hides deletion from sales and lets admins delete or see document refusals', function () {
    $customer = Customer::factory()->create();
    Livewire::test(ViewCustomer::class, ['record' => $customer->id])->assertActionHidden('delete');
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    Livewire::test(ViewCustomer::class, ['record' => $customer->id])->callAction('delete');
    $this->assertModelMissing($customer);
    $customer = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $customer->id]);
    Livewire::test(ViewCustomer::class, ['record' => $customer->id])->callAction('delete')->assertNotified('This customer has orders; it cannot be deleted.');
    $this->assertModelExists($customer);
});

it('refuses stock staff all customer pages and hides navigation', function () {
    $customer = Customer::factory()->create();
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    expect(CustomerResource::canAccess())->toBeFalse();
    foreach (['index', 'create', 'view', 'edit'] as $page) {
        $this->get(CustomerResource::getUrl($page, ['record' => $customer]))->assertForbidden();
    }
});

it('allows sales staff the list create view and edit routes', function () {
    $customer = Customer::factory()->create();
    foreach (['index', 'create', 'view', 'edit'] as $page) {
        $this->get(CustomerResource::getUrl($page, ['record' => $customer]))->assertOk();
    }
});

it('filters type and price tier and badges the walk in customer', function () {
    $contractor = Customer::factory()->create(['type' => 'contractor', 'price_tier' => 'trade']);
    $retail = Customer::factory()->create(['type' => 'homeowner', 'price_tier' => 'retail']);
    $walkIn = app(ResolveCustomer::class)->walkIn();
    Livewire::test(ListCustomers::class)->filterTable('type', 'contractor')->assertCanSeeTableRecords([$contractor])->assertCanNotSeeTableRecords([$retail, $walkIn]);
    Livewire::test(ListCustomers::class)->filterTable('price_tier', 'trade')->assertCanSeeTableRecords([$contractor])->assertCanNotSeeTableRecords([$retail, $walkIn]);
    Livewire::test(ListCustomers::class)->assertTableColumnStateSet('is_walk_in', 'Walk-in', $walkIn);
});

it('keeps long customer notes editable after resolving an alternate name', function () {
    $customer = Customer::factory()->create(['phone_e164' => '+94771234567', 'name' => 'Original', 'notes' => str_repeat('a', 1990)]);
    app(ResolveCustomer::class)->handle('0771234567', 'Alternate name', User::factory()->sales()->create());

    Livewire::test(EditCustomer::class, ['record' => $customer->id])->fillForm(['city' => 'Kandy'])
        ->call('save')->assertHasNoFormErrors();

    expect($customer->fresh()->notes)->toBe(str_repeat('a', 1990));
    expect($customer->fresh()->city)->toBe('Kandy');
});

it('shows the customer badge entry only for walk in customers', function () {
    $customer = Customer::factory()->create();
    $walkIn = app(ResolveCustomer::class)->walkIn();

    Livewire::test(ViewCustomer::class, ['record' => $customer->id])->assertSchemaComponentHidden('is_walk_in', 'infolist');
    Livewire::test(ViewCustomer::class, ['record' => $walkIn->id])->assertSchemaComponentVisible('is_walk_in', 'infolist')->assertSee('Walk-in');
});
