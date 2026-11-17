<?php

use App\Actions\Customers\ResolveCustomer;
use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderReferenceGenerator;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PriceTier;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use App\Settings\QuoteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->by = User::factory()->sales()->create();
    $this->customer = Customer::factory()->create(['price_tier' => PriceTier::Trade]);
    $this->location = Location::factory()->create(['is_default' => true]);
});

it('creates an unsent quote with customer defaults and Colombo end of day validity', function (string $utc, string $deadline) {
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    $settings = app(QuoteSettings::class);
    $settings->validity_days = 7;
    $settings->save();

    $quote = app(CreateQuote::class)->handle($this->customer, $this->by);

    expect($quote->reference)->toMatch('/^Q-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{6}$/D');
    expect($quote->status)->toBe(OrderStatus::Quoted);
    expect($quote->source)->toBe(OrderSource::Quote);
    expect($quote->quote_revision)->toBe(0);
    expect($quote->token_hash)->toBeNull();
    expect($quote->total_cents)->toBe(0);
    expect($quote->price_tier)->toBe(PriceTier::Trade);
    expect($quote->customer_name)->toBe($this->customer->name);
    expect($quote->phone_e164)->toBe($this->customer->phone_e164);
    expect($quote->email)->toBe($this->customer->email);
    expect($quote->location_id)->toBe($this->location->id);
    expect($quote->created_by_id)->toBe($this->by->id);
    expect($quote->locale)->toBe(config('app.locale'));
    expect($quote->valid_until->format('Y-m-d H:i:s'))->toBe($deadline);
    expect($quote->valid_until->timezoneName)->toBe('Asia/Colombo');
    expect($quote->valid_until->micro)->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $quote->id, 'valid_until' => $deadline]);
    $this->assertDatabaseHas('order_events', ['order_id' => $quote->id, 'from_status' => null, 'to_status' => 'quoted', 'note' => 'Quote created']);
    $this->assertDatabaseCount('stock_movements', 0);
})->with([
    ['2026-11-17 18:29:59', '2026-11-24 23:59:59'],
    ['2026-11-17 18:30:00', '2026-11-25 23:59:59'],
    ['2026-11-18 00:00:00', '2026-11-25 23:59:59'],
]);

it('refuses ineligible customers before authorization including stale caller models', function (string $state) {
    $customer = $this->customer;
    match ($state) {
        'walk-in' => $customer = app(ResolveCustomer::class)->walkIn(),
        'merged' => $customer->update(['merged_into_id' => Customer::factory()->create()->id]),
        'anonymised' => $customer->update(['anonymised_at' => now()]),
        'deleted' => $customer->delete(),
    };

    expect(fn () => app(CreateQuote::class)->handle($customer, User::factory()->stock()->create()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('orders', 0);
})->with(['walk-in', 'merged', 'anonymised', 'deleted']);

it('refuses stock staff creating a quote', function () {
    expect(fn () => app(CreateQuote::class)->handle($this->customer, User::factory()->stock()->create()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('orders', 0);
});

it('rechecks customer eligibility under the shared lock', function () {
    Gate::before(function (User $user, string $ability): ?bool {
        if ($ability === 'create') {
            $this->customer->update(['anonymised_at' => now()]);
        }

        return null;
    });

    expect(fn () => app(CreateQuote::class)->handle($this->customer, $this->by))->toThrow(ValidationException::class, 'anonymised');
    $this->assertDatabaseCount('orders', 0);
});

it('retries quote reference collisions in savepoints', function () {
    Order::factory()->create(['reference' => 'Q-222222']);
    $generator = new class extends OrderReferenceGenerator
    {
        public int $calls = 0;

        public function next(string $prefix): string
        {
            return ++$this->calls === 1 ? 'Q-222222' : 'Q-333333';
        }
    };
    app()->instance(OrderReferenceGenerator::class, $generator);

    expect(app(CreateQuote::class)->handle($this->customer, $this->by)->reference)->toBe('Q-333333');
    expect($generator->calls)->toBe(2);
});
