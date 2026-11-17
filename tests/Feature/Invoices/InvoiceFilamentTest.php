<?php

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\VoidInvoice;
use App\Actions\Payments\RecordPayment;
use App\Enums\DocumentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\User;
use App\Settings\DocumentSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Colombo'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    $this->actingAs($this->by);
    $this->customer = Customer::factory()->create(['name' => 'Invoice Customer']);
    $this->order = Order::factory()->create(['customer_id' => $this->customer->id, 'status' => OrderStatus::Confirmed, 'created_by_id' => $this->by->id]);
    OrderLine::factory()->create(['order_id' => $this->order->id, 'description' => 'Invoice panel']);
});

it('allows sales and admin invoice pages while denying stock staff', function (string $role) {
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));
    $list = Livewire::test(ListInvoices::class);
    $view = Livewire::test(ViewInvoice::class, ['record' => $issued->invoice->id]);
    if ($role === 'stock') {
        $list->assertForbidden();
        $view->assertForbidden();
        expect(InvoiceResource::canAccess())->toBeFalse();
    } else {
        $list->assertOk()->assertCanSeeTableRecords([$issued->invoice]);
        $view->assertOk()->assertSee($issued->invoice->number)->assertSee('Invoice Customer')->assertSee('Invoice panel');
    }
})->with(['admin', 'sales', 'stock']);

it('hides empty optional invoice entries and their buyer labels', function (?string $empty) {
    $invoice = Invoice::factory()->create([
        'order_id' => $this->order->id, 'due_on' => null, 'vat_rate_bp' => null, 'seller_vat_number' => null,
        'customer_snapshot' => [
            'name' => 'Invoice Customer', 'company_name' => $empty, 'address_line1' => $empty,
            'address_line2' => $empty, 'city' => $empty, 'phone_e164' => $empty, 'email' => $empty, 'vat_number' => $empty,
        ],
    ]);
    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertOk()->assertSee('Invoice Customer');
    foreach (['Company', 'Address', 'City', 'Phone', 'Email', 'Buyer VAT number', 'Seller VAT number', 'Due on', 'Voided at', 'Void reason'] as $label) {
        $page->assertDontSee($label);
    }
    foreach (['company_name', 'address_line1', 'address_line2', 'city', 'phone_e164', 'email', 'vat_number'] as $field) {
        $page->assertSchemaComponentHidden('customer_snapshot.'.$field, 'infolist');
    }
    foreach (['due_on', 'seller_vat_number', 'voided_at', 'void_reason'] as $field) {
        $page->assertSchemaComponentHidden($field, 'infolist');
    }
})->with([[null], ['']]);

it('shows populated buyer entries and optional invoice details', function () {
    $invoice = Invoice::factory()->create([
        'order_id' => $this->order->id, 'due_on' => '2026-11-30', 'vat_rate_bp' => 1800, 'seller_vat_number' => 'SELLER-VAT',
        'customer_snapshot' => [
            'name' => 'Invoice Customer', 'company_name' => 'Buyer Company', 'address_line1' => 'Buyer Street',
            'address_line2' => 'Floor 2', 'city' => 'Colombo', 'phone_e164' => '+94771234567', 'email' => 'buyer@example.test', 'vat_number' => 'BUYER-VAT',
        ],
    ]);
    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertOk();
    foreach (['Company', 'Address', 'City', 'Phone', 'Email', 'Buyer VAT number', 'Seller VAT number', 'Due on', 'Buyer Company', 'Buyer Street', 'Floor 2', 'Colombo', '+94771234567', 'buyer@example.test', 'BUYER-VAT', 'SELLER-VAT'] as $text) {
        $page->assertSee($text);
    }
    foreach (['company_name', 'address_line1', 'address_line2', 'city', 'phone_e164', 'email', 'vat_number'] as $field) {
        $page->assertSchemaComponentVisible('customer_snapshot.'.$field, 'infolist');
    }
    $page->assertSchemaComponentVisible('due_on', 'infolist')->assertSchemaComponentVisible('seller_vat_number', 'infolist');
});

it('lists searches and filters invoices without mutation routes', function () {
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $otherCustomer = Customer::factory()->create(['name' => 'Different Buyer']);
    $other = Invoice::factory()->create(['customer_id' => $otherCustomer->id, 'customer_snapshot' => ['name' => 'Different Buyer'], 'status' => DocumentStatus::Void, 'issued_on' => '2026-10-01']);
    $page = Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$issued, $other], inOrder: true)
        ->assertTableColumnStateSet('customer_snapshot.name', 'Invoice Customer', $issued)
        ->assertTableColumnStateSet('order.reference', $this->order->reference, $issued)
        ->searchTable('Invoice Customer')->assertCanSeeTableRecords([$issued])->assertCanNotSeeTableRecords([$other]);
    $page->searchTable($issued->number)->assertCanSeeTableRecords([$issued])->assertCanNotSeeTableRecords([$other]);
    Livewire::test(ListInvoices::class)->filterTable('status', 'void')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$issued]);
    Livewire::test(ListInvoices::class)->filterTable('customer', $this->customer->id)->assertCanSeeTableRecords([$issued])->assertCanNotSeeTableRecords([$other]);
    Livewire::test(ListInvoices::class)->filterTable('issued_on', ['from' => '2026-11-01', 'until' => '2026-11-30'])->assertCanSeeTableRecords([$issued])->assertCanNotSeeTableRecords([$other]);
    $this->get(InvoiceResource::getUrl('index').'/create')->assertNotFound();
    $this->get(InvoiceResource::getUrl('index').'/not-a-record')->assertNotFound();
    $this->get(InvoiceResource::getUrl('view', ['record' => $issued]).'/edit')->assertNotFound();
});

it('gets a replacement invoice link in the transient modal and revokes the previous link', function () {
    $first = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $page = Livewire::test(ViewInvoice::class, ['record' => $first->invoice->id])->assertSee('Get new invoice link')
        ->mountAction('getInvoiceLink')->assertActionMounted('invoiceLink');
    $url = $page->get('mountedActions.0.data.url');
    $text = $page->get('mountedActions.0.data.whatsAppText');
    expect($url)->toContain('/i/'.$first->invoice->number.'/');
    expect($text)->toContain($first->invoice->number, $url);
    $page->assertActionDataSet(['url' => $url, 'whatsAppText' => $text])
        ->assertMountedActionModalSee('This link replaces any earlier link for this invoice.');
    $this->get($first->url)->assertNotFound();
    $this->get($url)->assertOk();
    expect($first->invoice->fresh()->token_hash)->toBe(hash('sha256', basename($url)));
});

it('voids invoices through the header action and shows the void information', function () {
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    Livewire::test(ViewInvoice::class, ['record' => $issued->invoice->id])->assertActionVisible('voidInvoice')
        ->callAction('voidInvoice', data: ['reason' => 'Wrong buyer'])->assertHasNoActionErrors()->assertNotified('Invoice '.$issued->invoice->number.' voided')
        ->assertActionHidden('voidInvoice')->assertActionHidden('getInvoiceLink')->assertSee('Wrong buyer');
    expect($issued->invoice->fresh()->status)->toBe(DocumentStatus::Void);
    $this->get($issued->url)->assertNotFound();
});

it('requires a void reason and obeys the void permission', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->callAction('voidInvoice', data: ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'void' ? false : null);
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertActionHidden('voidInvoice');
});

it('issues from the order with the configured due date and shows the same link modal', function () {
    $settings = app(DocumentSettings::class);
    $settings->invoice_due_days = 14;
    $settings->save();
    $page = Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertActionVisible('issueInvoice')->assertActionHidden('viewInvoice')
        ->mountAction('issueInvoice')->assertActionDataSet(['due_on' => '2026-12-01'])->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('invoiceLink');
    $invoice = $this->order->activeInvoice();
    expect($invoice)->not->toBeNull();
    expect($invoice->due_on->format('Y-m-d'))->toBe('2026-12-01');
    $url = $page->get('mountedActions.0.data.url');
    $page->assertActionDataSet(['url' => $url])->assertNotified('Invoice '.$invoice->number.' issued');
    $this->get($url)->assertOk();
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertActionHidden('issueInvoice')->assertActionVisible('viewInvoice')->assertSee($invoice->number)
        ->assertSee(InvoiceResource::getUrl('view', ['record' => $invoice]), false);
});

it('hides issue on refused statuses', function (OrderStatus $status) {
    $this->order->update(['status' => $status]);
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertActionHidden('issueInvoice');
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Cancelled, OrderStatus::Expired]);

it('rejects past due dates and permits explicit due dates', function () {
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->callAction('issueInvoice', data: ['due_on' => '2026-11-16'])->assertHasActionErrors(['due_on']);
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->callAction('issueInvoice', data: ['due_on' => '2026-12-02'])->assertHasNoActionErrors();
    expect($this->order->activeInvoice()->due_on->format('Y-m-d'))->toBe('2026-12-02');
});

it('hides issuance when create is denied', function () {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'create' ? false : null);
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertActionHidden('issueInvoice');
});

it('shows receipt links for confirmed payments on invoice and order pages', function () {
    $payment = app(RecordPayment::class)->handle($this->order, PaymentMethod::Cash, 10000, null, null, $this->by);
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $pending = Payment::factory()->create(['order_id' => $this->order->id]);
    foreach ([[ViewOrder::class, $this->order->id], [ViewInvoice::class, $invoice->id]] as [$page, $record]) {
        Livewire::test($page, ['record' => $record])->assertSee('Print receipt')
            ->assertSee(route('filament.admin.payments.receipt', ['payment' => $payment]), false)
            ->assertDontSee(route('filament.admin.payments.receipt', ['payment' => $pending]), false);
    }
});

it('shows only the customers invoices in their read only panel', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $other = Invoice::factory()->create();
    Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$invoice])->assertCanNotSeeTableRecords([$other])
        ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')
        ->assertSee(InvoiceResource::getUrl('view', ['record' => $invoice]), false);
});

it('notifies and halts issuance when action validation fails', function () {
    $this->order->lines()->delete();
    Livewire::test(ViewOrder::class, ['record' => $this->order->id])->callAction('issueInvoice', data: ['due_on' => null])
        ->assertNotified('Add at least one line before issuing an invoice.');
    expect($this->order->activeInvoice())->toBeNull();
});

it('notifies and halts voiding when the invoice has already become void', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->mountAction('voidInvoice')
        ->assertActionMounted('voidInvoice')->fillForm(['reason' => 'Another reason']);
    app(VoidInvoice::class)->handle($invoice, 'Concurrent void', $this->by);
    $page->callMountedAction()->assertNotified('This invoice is already void.')->assertActionMounted('voidInvoice');
    expect($invoice->fresh()->void_reason)->toBe('Concurrent void');
});
