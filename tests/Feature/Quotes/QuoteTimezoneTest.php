<?php

use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Actions\Orders\SweepExpired;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use App\Models\Variant;
use App\Settings\QuoteSettings;
use Carbon\CarbonImmutable;

it('keeps Colombo validity boundaries when the storage timezone changes', function (string $timezone, string $storedCreated, string $storedSaved) {
    $previousTimezone = date_default_timezone_get();
    $previousAppTimezone = config('app.timezone');
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);
    try {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 18:30:00', 'UTC'));
        $settings = app(QuoteSettings::class);
        $settings->validity_days = 7;
        $settings->save();
        $by = User::factory()->sales()->create();
        $customer = Customer::factory()->create();
        Location::factory()->create(['is_default' => true]);
        $variant = Variant::factory()->create(['track_stock' => false]);

        $quote = app(CreateQuote::class)->handle($customer, $by);

        $this->assertDatabaseHas('orders', ['id' => $quote->id, 'valid_until' => $storedCreated]);
        expect($quote->valid_until->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i:s'))->toBe('2026-11-25 23:59:59');
        $quote = app(SaveQuoteLines::class)->handle($quote, [new OrderLineInput(OrderLineKind::Product, $variant->id, null, 1, 100)], null, null, CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $by);
        $this->assertDatabaseHas('orders', ['id' => $quote->id, 'valid_until' => $storedSaved]);
        expect($quote->valid_until->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i:s'))->toBe('2026-11-20 23:59:59');
        $link = app(SendQuote::class)->handle($quote, $by);
        expect($link->whatsAppText)->toContain('valid until 20 Nov 2026');
        $this->assertDatabaseHas('quote_snapshots', ['order_id' => $quote->id, 'valid_until' => $storedSaved]);

        expect(app(SweepExpired::class)->handle(CarbonImmutable::parse('2026-11-20 18:29:59', 'UTC'))->expiredQuotes)->toBe(0);
        expect(app(SweepExpired::class)->handle(CarbonImmutable::parse('2026-11-20 18:30:00', 'UTC'))->expiredQuotes)->toBe(1);
        expect($quote->fresh()->status)->toBe(OrderStatus::Expired);
    } finally {
        config(['app.timezone' => $previousAppTimezone]);
        date_default_timezone_set($previousTimezone);
    }
})->with([
    ['Asia/Colombo', '2026-11-25 23:59:59', '2026-11-20 23:59:59'],
    ['UTC', '2026-11-25 18:29:59', '2026-11-20 18:29:59'],
    ['America/New_York', '2026-11-25 13:29:59', '2026-11-20 13:29:59'],
]);
