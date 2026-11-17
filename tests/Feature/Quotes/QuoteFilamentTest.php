<?php

use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\PriceTier;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\EditQuote;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\QuoteSnapshotsRelationManager;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Settings\QuoteSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Colombo'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    $this->actingAs($this->by);
    $this->location = Location::factory()->create(['is_default' => true]);
    $this->customer = Customer::factory()->create(['price_tier' => PriceTier::Trade]);
    $this->variant = Variant::factory()->create(['price_cents' => 10000, 'trade_price_cents' => 8000]);
    StockLevel::factory()->create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 10]);
    $this->quote = app(CreateQuote::class)->handle($this->customer, $this->by);
    $this->formData = [
        'lines' => [['kind' => 'product', 'variant_id' => $this->variant->id, 'description' => null, 'qty' => 2, 'unit_price' => '80.00']],
        'required_advance' => '50.00', 'exclusions' => 'No installation', 'valid_until' => '2026-11-20',
    ];
});

it('creates a quote through the list action and redirects to its editor', function () {
    $page = Livewire::test(ListOrders::class)->assertActionVisible('newQuote')
        ->callAction('newQuote', data: ['customer_id' => $this->customer->id])->assertHasNoActionErrors();
    $quote = Order::query()->latest('id')->firstOrFail();

    expect($quote->id)->not->toBe($this->quote->id);
    expect($quote->customer_id)->toBe($this->customer->id);
    expect($quote->status)->toBe(OrderStatus::Quoted);
    $page->assertRedirect(OrderResource::getUrl('quote', ['record' => $quote]));
});

it('hides creation from users without create permission and denies stock access to the list', function () {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'create' ? false : null);
    Livewire::test(ListOrders::class)->assertActionHidden('newQuote');
    $this->actingAs(User::factory()->stock()->withAppAuthentication()->create());
    Livewire::test(ListOrders::class)->assertForbidden();
});

it('renders the quote editor with the customer summary and saves the form', function () {
    Livewire::test(EditQuote::class, ['record' => $this->quote->id])->assertOk()
        ->assertSee('Quote '.$this->quote->reference)->assertSee($this->customer->name)->assertSee($this->customer->phone_e164)
        ->fillForm($this->formData)->call('saveQuote')->assertHasNoFormErrors()->assertNotified('Quote saved');

    $quote = $this->quote->fresh();
    expect($quote->lines()->sole()->unit_price_cents)->toBe(8000);
    expect($quote->total_cents)->toBe(16000);
    expect($quote->required_advance_cents)->toBe(5000);
    expect($quote->exclusions)->toBe('No installation');
    expect($quote->valid_until->format('Y-m-d H:i:s'))->toBe('2026-11-20 23:59:59');
    $this->assertDatabaseCount('stock_movements', 0);
});

it('redirects closed quotes or denied updates with a warning', function (bool $closed) {
    if ($closed) {
        $this->quote->update(['status' => OrderStatus::Confirmed]);
    } else {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);
    }

    Livewire::test(EditQuote::class, ['record' => $this->quote->id])
        ->assertNotified('This quote cannot be edited.')->assertRedirect(OrderResource::getUrl('view', ['record' => $this->quote]));
})->with([true, false]);

it('prefills the trade price when an item is picked', function () {
    $page = Livewire::test(EditQuote::class, ['record' => $this->quote->id])->fillForm([
        ...$this->formData, 'lines' => [['kind' => 'product', 'variant_id' => null, 'qty' => 1, 'unit_price' => null]],
    ]);
    $key = array_key_first($page->get('data.lines'));
    $page->set('data.lines.'.$key.'.variant_id', $this->variant->id)->assertSet('data.lines.'.$key.'.unit_price', '80.00');
});

it('fills the default advance with integer half up rounding', function () {
    $settings = app(QuoteSettings::class);
    $settings->default_advance_pct = 50;
    $settings->save();
    Livewire::test(EditQuote::class, ['record' => $this->quote->id])->fillForm([
        ...$this->formData, 'lines' => [['kind' => 'product', 'variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => '1.01']],
    ])->callAction(TestAction::make('useDefaultAdvance')->schemaComponent('required_advance'))
        ->assertSet('data.required_advance', '0.51');
});

it('maps advance and inactive product failures to form fields', function (bool $badAdvance) {
    if (! $badAdvance) {
        $this->variant->update(['is_active' => false]);
    }
    $page = Livewire::test(EditQuote::class, ['record' => $this->quote->id])->fillForm([
        ...$this->formData, 'required_advance' => $badAdvance ? '999.99' : null,
    ]);
    $key = array_key_first($page->get('data.lines'));
    $page->call('saveQuote')->assertHasFormErrors([$badAdvance ? 'required_advance' : 'lines.'.$key.'.variant_id']);
    expect($this->quote->fresh()->total_cents)->toBe(0);
})->with([true, false]);

it('maps action validation to the lines field when a product is deactivated during save', function () {
    $page = Livewire::test(EditQuote::class, ['record' => $this->quote->id])->fillForm($this->formData);
    $changed = false;
    DB::listen(function (QueryExecuted $query) use (&$changed): void {
        if (! $changed && str_contains($query->sql, 'from "orders"') && str_contains($query->sql, 'for update')) {
            $changed = true;
            $this->variant->update(['is_active' => false]);
        }
    });

    $page->call('saveQuote')->assertHasFormErrors(['lines'])->assertNotified('Choose an active variant for every product line.');
    expect($changed)->toBeTrue();
    expect($this->quote->fresh()->total_cents)->toBe(0);
});

it('saves edited form values before sending and displays the generated link and WhatsApp text', function () {
    $page = Livewire::test(EditQuote::class, ['record' => $this->quote->id])->fillForm($this->formData)->mountAction('getQuoteLink');
    $url = $page->get('mountedActions.0.data.url');
    $text = $page->get('mountedActions.0.data.whatsAppText');

    expect($url)->toContain('/q/'.$this->quote->reference.'/');
    expect($text)->toContain($this->quote->reference, 'revision 1', 'Rs 160.00');
    expect($this->quote->fresh()->quote_revision)->toBe(1);
    expect($this->quote->quoteSnapshots()->sole()->exclusions)->toBe('No installation');
    $whatsAppUrl = 'https://wa.me/'.ltrim($this->customer->phone_e164, '+').'?text='.rawurlencode($text);
    $page->assertActionMounted('quoteLink')->assertActionDataSet(['url' => $url, 'whatsAppText' => $text, 'whatsAppUrl' => $whatsAppUrl])
        ->assertMountedActionModalSee('This link replaces any earlier link for this quote.');
    $buttons = $page->instance()->getMountedAction()->getExtraModalFooterActions();
    expect($buttons['openQuote']->getLabel())->toBe('Open quote');
    expect($buttons['openQuote']->getUrl())->toBe($url);
    expect($buttons['openQuote']->isVisible())->toBeTrue();
    expect($buttons['openWhatsApp']->getLabel())->toBe('Open WhatsApp');
    expect($buttons['openWhatsApp']->getUrl())->toBe($whatsAppUrl);
    expect($buttons['openWhatsApp']->isVisible())->toBeTrue();
    $this->get($url)->assertOk();
    $page->unmountAction()->mountAction('getQuoteLink');
    $nextUrl = $page->get('mountedActions.0.data.url');
    expect($this->quote->fresh()->quote_revision)->toBe(2);
    $this->get($url)->assertNotFound();
    $this->get($nextUrl)->assertOk()->assertSee('Revision 2');
});

it('does not send when saving the form fails', function () {
    Livewire::test(EditQuote::class, ['record' => $this->quote->id])->fillForm([...$this->formData, 'required_advance' => '9999.00'])
        ->mountAction('getQuoteLink')->assertHasFormErrors(['required_advance']);
    expect($this->quote->fresh()->quote_revision)->toBe(0);
    $this->assertDatabaseCount('quote_snapshots', 0);
});

it('does not send a quote by following a URL that names the link action', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 8000)], null, null, CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);

    $this->get(OrderResource::getUrl('view', ['record' => $this->quote]).'?action=getQuoteLink')->assertOk();

    expect($this->quote->fresh()->quote_revision)->toBe(0);
    $this->assertDatabaseCount('quote_snapshots', 0);
});

it('offers editing linking accepting and printing on the quoted order view', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 2, 8000)], null, null, CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);
    $page = Livewire::test(ViewOrder::class, ['record' => $this->quote->id])->assertActionVisible('editQuote')->assertActionHidden('printQuote')
        ->assertActionVisible('getQuoteLink')->assertActionVisible('markAccepted')
        ->mountAction('getQuoteLink')->assertActionMounted('quoteLink')
        ->assertMountedActionModalSee('This link replaces any earlier link for this quote.');
    $url = $page->get('mountedActions.0.data.url');
    $text = $page->get('mountedActions.0.data.whatsAppText');
    $page->assertActionDataSet([
        'url' => $url, 'whatsAppText' => $text,
        'whatsAppUrl' => 'https://wa.me/'.ltrim($this->customer->phone_e164, '+').'?text='.rawurlencode($text),
    ]);
    $buttons = $page->instance()->getMountedAction()->getExtraModalFooterActions();
    expect($buttons['openQuote']->getLabel())->toBe('Open quote');
    expect($buttons['openQuote']->getUrl())->toBe($url);
    expect($buttons['openQuote']->isVisible())->toBeTrue();
    expect($this->quote->fresh()->quote_revision)->toBe(1);
    Livewire::test(ViewOrder::class, ['record' => $this->quote->id])->assertActionVisible('printQuote')
        ->callAction('markAccepted', data: ['note' => 'Confirmed by phone'])->assertNotified('Quote '.$this->quote->reference.' accepted')
        ->assertRedirect(OrderResource::getUrl('view', ['record' => $this->quote]));

    expect($this->quote->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect(StockLevel::sole()->qty)->toBe(8);
    expect(StockMovement::sole()->reason->value)->toBe('quote_accepted');
});

it('keeps the acceptance modal open after shortage and allows an authorized override', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 11, 8000)], null, null, CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);
    $message = 'Stock is short. '.$this->variant->sku.': wanted 11, in stock 10';
    $page = Livewire::test(ViewOrder::class, ['record' => $this->quote->id])->callAction('markAccepted', data: [])
        ->assertNotified($message)->assertActionMounted('markAccepted');
    expect($this->quote->fresh()->status)->toBe(OrderStatus::Quoted);
    $this->assertDatabaseCount('stock_movements', 0);
    $page->fillForm(['stock_override_reason' => 'Delivery due'], 'mountedActionSchema0')->callMountedAction()
        ->assertRedirect(OrderResource::getUrl('view', ['record' => $this->quote]));
    expect(StockMovement::sole()->is_override)->toBeTrue();
    expect(StockLevel::sole()->qty)->toBe(-1);
});

it('accepts a saved and sent quote directly from its editor', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 2, 8000)], null, null, CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);

    Livewire::test(EditQuote::class, ['record' => $this->quote->id])->callAction('markAccepted', data: ['note' => 'Customer agreed'])
        ->assertNotified('Quote '.$this->quote->reference.' accepted')->assertRedirect(OrderResource::getUrl('view', ['record' => $this->quote]));
    expect($this->quote->fresh()->status)->toBe(OrderStatus::Confirmed);
    expect(StockLevel::sole()->qty)->toBe(8);
});

it('only offers the override field to users with overrideStock', function () {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'overrideStock' ? false : null);
    Livewire::test(ViewOrder::class, ['record' => $this->quote->id])->mountAction('markAccepted')
        ->assertSchemaComponentHidden('stock_override_reason', 'mountedActionSchema0');
});

it('lists sent revisions newest first with read only print actions', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 1, 8000)], null, null, CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);
    $quote = $this->quote->fresh();
    $snapshots = $quote->quoteSnapshots()->orderByDesc('revision')->get();

    Livewire::test(QuoteSnapshotsRelationManager::class, ['ownerRecord' => $quote, 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords($snapshots, inOrder: true)->assertTableActionExists('print', record: $snapshots->last())
        ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')->assertActionDoesNotExist(TestAction::make('create')->table());
    expect(QuoteSnapshotsRelationManager::canViewForRecord($quote, ViewOrder::class))->toBeTrue();
    $unsent = app(CreateQuote::class)->handle($this->customer, $this->by);
    expect(QuoteSnapshotsRelationManager::canViewForRecord($unsent, ViewOrder::class))->toBeFalse();
});
