<?php

use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Enums\OrderLineKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use App\Models\Variant;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Colombo'));
    $business = app(BusinessSettings::class);
    $business->name = 'Quote Test Business';
    $business->address = '24 Temple Road, Colombo';
    $business->phone = '+94112345678';
    $business->email = 'quotes@example.test';
    $business->whatsapp_digits = '94771234567';
    $business->save();
    $documents = app(DocumentSettings::class);
    $documents->quote_footer = 'Quote test footer: prices include materials only.';
    $documents->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->by = User::factory()->sales()->withAppAuthentication()->create();
    Location::factory()->create(['is_default' => true]);
    $this->customer = Customer::factory()->create(['name' => 'Quote Customer', 'phone_e164' => '+94771234567']);
    $this->variant = Variant::factory()->create(['sku' => 'QUOTE-PANEL']);
    $this->quote = app(CreateQuote::class)->handle($this->customer, $this->by);
    $this->lines = [
        new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 2, 10000),
        new OrderLineInput(OrderLineKind::Delivery, null, 'Delivery charge', 1, 2000),
        new OrderLineInput(OrderLineKind::Labour, null, 'Fitting labour', 1, 3000),
        new OrderLineInput(OrderLineKind::Discount, null, 'Special discount', 1, 500),
    ];
    app(SaveQuoteLines::class)->handle($this->quote, $this->lines, 5000, 'Painting excluded', CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);
    $this->link = app(SendQuote::class)->handle($this->quote, $this->by);
});

it('renders the public snapshot with document terms business details and private headers', function () {
    $business = app(BusinessSettings::class);
    $footer = app(DocumentSettings::class)->quote_footer;

    $response = $this->get($this->link->url)->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee($this->quote->reference)->assertSee('Revision 1')->assertSee('QUOTE-PANEL')
        ->assertSee('Quote Customer')->assertSee('+94771234567')->assertSee('Fitting labour')->assertSee('Delivery charge')
        ->assertSee('Rs 230.00')->assertSee('Rs 20.00')->assertSee('Rs 5.00')->assertSee('Rs -5.00')->assertSee('Rs 245.00')
        ->assertSee('Required advance')->assertSee('Rs 50.00')->assertSee('Painting excluded')->assertSee('20 Nov 2026')
        ->assertSee($business->name)->assertSee($business->address)->assertSee($business->phone)->assertSee($business->email)->assertSee($footer)
        ->assertSee('Reply on WhatsApp to accept')->assertSee('window.print()', false)->assertSee('<meta name="robots" content="noindex">', false);
    expect($response->viewData('document')->whatsAppUrl)->toBe('https://wa.me/'.$business->whatsapp_digits.'?text='.rawurlencode('Hello, I would like to accept quote '.$this->quote->reference.' (revision 1).'));
});

it('keeps the sent values when live quote lines and terms change', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Other, null, 'UNSENT REPLACEMENT', 1, 99999)], null, 'UNSENT TERMS', CarbonImmutable::parse('2026-11-25', 'Asia/Colombo'), $this->by);

    $this->get($this->link->url)->assertOk()->assertSee('QUOTE-PANEL')->assertSee('Rs 245.00')->assertSee('Painting excluded')
        ->assertSee('20 Nov 2026')->assertDontSee('UNSENT REPLACEMENT')->assertDontSee('UNSENT TERMS')->assertDontSee('Rs 999.99');
});

it('omits empty business contact lines and the address without a stray separator', function () {
    $business = app(BusinessSettings::class);
    $business->address = '';
    $business->phone = '';
    $business->email = '';
    $business->save();

    $this->get($this->link->url)->assertOk()
        ->assertDontSee('<p> · </p>', false)
        ->assertDontSee('<p></p>', false)
        ->assertDontSee('<p class="multiline"></p>', false);
});

it('joins only populated business contact details', function (string $phone, string $email, string $expected) {
    $business = app(BusinessSettings::class);
    $business->phone = $phone;
    $business->email = $email;
    $business->save();

    $this->get($this->link->url)->assertOk()->assertSee('<p>'.$expected.'</p>', false);
})->with([
    ['+94112345678', '', '+94112345678'],
    ['', 'quotes@example.test', 'quotes@example.test'],
    ['+94112345678', 'quotes@example.test', '+94112345678 · quotes@example.test'],
]);

it('omits the acceptance link when no business WhatsApp number is configured', function () {
    $business = app(BusinessSettings::class);
    $business->whatsapp_digits = '';
    $business->save();

    $this->get($this->link->url)->assertOk()->assertDontSee('Reply on WhatsApp to accept');
});

it('does not expose non quote sources through a matching quote token', function (OrderSource $source) {
    $this->quote->update(['source' => $source]);

    $this->get($this->link->url)->assertNotFound();
})->with([OrderSource::Counter, OrderSource::WebCheckout]);

it('rejects invalid references tokens and orders without a snapshot', function (string $invalid) {
    $url = $this->link->url;
    if ($invalid === 'token') {
        $url = route('quotes.show', ['reference' => $this->quote->reference, 'token' => 'wrong']);
    } elseif ($invalid === 'reference') {
        $url = route('quotes.show', ['reference' => 'Q-WRONG', 'token' => basename($this->link->url)]);
    } else {
        $this->quote->quoteSnapshots()->delete();
    }

    $this->get($url)->assertNotFound();
})->with(['token', 'reference', 'unsent']);

it('shows a status notice and removes acceptance links for closed or expired quotes', function (string $status, string $notice) {
    $this->quote->update(['status' => OrderStatus::from($status)]);

    $this->get($this->link->url)->assertOk()->assertSee($notice)->assertDontSee('Reply on WhatsApp to accept');
})->with([
    ['expired', 'This quote has expired.'], ['cancelled', 'This quote has been withdrawn.'], ['confirmed', 'This quote has been accepted.'],
    ['processing', 'This quote has been accepted.'], ['completed', 'This quote has been accepted.'],
]);

it('shows an expiry notice at Colombo midnight before the scheduled sweep runs', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 00:00:00', 'Asia/Colombo'));

    $this->get($this->link->url)->assertOk()->assertSee('This quote has expired.')->assertDontSee('Reply on WhatsApp to accept');
    expect($this->quote->fresh()->status)->toBe(OrderStatus::Quoted);
});

it('escapes document content instead of executing user supplied markup', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Other, null, '<script>alert(1)</script>', 1, 100)], null, '<img src=x onerror=alert(1)>', CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);
    $link = app(SendQuote::class)->handle($this->quote, $this->by);

    $this->get($link->url)->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false);
});

it('prints an earlier revision after another revision is sent', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Other, null, 'Revision two content', 1, 99999)], null, null, CarbonImmutable::parse('2026-11-22', 'Asia/Colombo'), $this->by);
    $second = app(SendQuote::class)->handle($this->quote, $this->by);
    $this->actingAs($this->by);

    $this->get(route('filament.admin.quotes.print', ['order' => $this->quote, 'revision' => 1]))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Revision 1')->assertSee('QUOTE-PANEL')->assertDontSee('Revision two content');
    $this->get($this->link->url)->assertNotFound();
    $this->get($second->url)->assertOk()->assertSee('Revision 2')->assertSee('Revision two content');
});

it('protects admin printing with panel authentication authorization and revision scoping', function (string $case) {
    if ($case === 'guest') {
        $this->get(route('filament.admin.quotes.print', ['order' => $this->quote, 'revision' => 1]))->assertRedirect(route('filament.admin.auth.login'));

        return;
    }
    $this->actingAs($case === 'stock' ? User::factory()->stock()->withAppAuthentication()->create() : $this->by);
    $response = $this->get(route('filament.admin.quotes.print', ['order' => $this->quote, 'revision' => $case === 'unknown' ? 99 : 1]));
    if ($case === 'stock') {
        $response->assertForbidden();
    } elseif ($case === 'unknown') {
        $response->assertNotFound();
    } else {
        $response->assertOk();
    }
})->with(['guest', 'stock', 'unknown', 'sales']);

it('requires MFA for the admin print route', function () {
    $this->actingAs(User::factory()->sales()->create());

    $this->get(route('filament.admin.quotes.print', ['order' => $this->quote, 'revision' => 1]))
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});
