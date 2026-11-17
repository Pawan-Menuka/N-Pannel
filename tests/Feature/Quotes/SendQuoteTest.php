<?php

use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use App\Models\Variant;
use App\Settings\BusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'));
    $this->by = User::factory()->sales()->create();
    $this->customer = Customer::factory()->create(['name' => 'Nimal', 'phone_e164' => '+94771234567']);
    Location::factory()->create(['is_default' => true]);
    $this->variant = Variant::factory()->create(['price_cents' => 10000]);
    $this->quote = app(CreateQuote::class)->handle($this->customer, $this->by);
    app(SaveQuoteLines::class)->handle($this->quote, [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 2, null)], 5000, 'Installation', CarbonImmutable::parse('2026-11-20', 'Asia/Colombo'), $this->by);
});

it('snapshots revisions rotates bearer tokens and builds a WhatsApp link without logging the secret', function () {
    $first = app(SendQuote::class)->handle($this->quote, $this->by);
    $plain = basename($first->url);

    expect($first->revision)->toBe(1);
    expect($plain)->toMatch('/^[a-f0-9]{64}$/D');
    expect($this->quote->fresh()->token_hash)->toBe(hash('sha256', $plain));
    $snapshot = $this->quote->quoteSnapshots()->sole();
    expect($snapshot->revision)->toBe(1);
    expect($snapshot->lines)->toEqual([[
        'kind' => 'product', 'variant_id' => $this->variant->id, 'sku' => $this->variant->sku,
        'description' => $this->variant->displayName(), 'sale_unit' => $this->variant->sale_unit->value,
        'qty' => 2, 'unit_price_cents' => 10000, 'line_total_cents' => 20000, 'sort_order' => 0,
    ]]);
    expect($snapshot->total_cents)->toBe(20000);
    expect($snapshot->required_advance_cents)->toBe(5000);
    expect($snapshot->exclusions)->toBe('Installation');
    expect($snapshot->valid_until->format('Y-m-d H:i:s'))->toBe('2026-11-20 23:59:59');
    $this->assertDatabaseHas('quote_snapshots', ['id' => $snapshot->id, 'valid_until' => '2026-11-20 23:59:59']);
    expect($snapshot->created_by_id)->toBe($this->by->id);
    expect($first->whatsAppText)->toContain('Hello Nimal', app(BusinessSettings::class)->name, $this->quote->reference, 'revision 1', 'Rs 200.00', '20 Nov 2026', $first->url);
    expect($first->whatsAppUrl)->toBe('https://wa.me/94771234567?text='.rawurlencode($first->whatsAppText));
    $this->get($first->url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $second = app(SendQuote::class)->handle($this->quote, $this->by);

    expect($second->revision)->toBe(2);
    expect($second->url)->not->toBe($first->url);
    $this->get($first->url)->assertNotFound();
    $this->get($second->url)->assertOk();
    $this->get(route('quotes.show', ['reference' => $this->quote->reference, 'token' => str_repeat('0', 64)]))->assertNotFound();
    $this->get(route('quotes.show', ['reference' => 'Q-XXXXXX', 'token' => basename($second->url)]))->assertNotFound();
    expect($this->quote->quoteSnapshots()->count())->toBe(2);
    expect($this->quote->quoteSnapshots()->where('revision', 1)->sole()->lines)->toBe($snapshot->lines);
    $this->assertDatabaseHas('order_events', ['order_id' => $this->quote->id, 'type' => 'note', 'note' => 'Quote sent (revision 2)']);
    expect(Activity::query()->get()->toJson())->not->toContain($plain, basename($second->url), 'token_hash');
    $this->assertDatabaseCount('stock_movements', 0);
});

it('refuses empty expired and closed quotes before authorization', function (string $invalid, string $message) {
    match ($invalid) {
        'empty' => $this->quote->lines()->delete(),
        'expired' => $this->quote->update(['valid_until' => now()->subSecond()]),
        'closed' => $this->quote->update(['status' => OrderStatus::Confirmed]),
    };

    expect(fn () => app(SendQuote::class)->handle($this->quote, User::factory()->stock()->create()))->toThrow(ValidationException::class, $message);
    $this->assertDatabaseCount('quote_snapshots', 0);
    expect($this->quote->fresh()->token_hash)->toBeNull();
})->with([['empty', 'Add at least one line'], ['expired', "This quote's validity date has passed. Change it before sending."], ['closed', 'Only an open quote']]);

it('authorizes sending through the send ability', function () {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'send' ? false : null);

    expect(fn () => app(SendQuote::class)->handle($this->quote, $this->by))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('quote_snapshots', 0);
});

it('returns no WhatsApp URL when the quote has no phone', function () {
    $this->quote->update(['phone_e164' => null]);
    expect(app(SendQuote::class)->handle($this->quote, $this->by)->whatsAppUrl)->toBeNull();
});

it('does not grant access to an unsent quote', function () {
    $this->get(route('quotes.show', ['reference' => $this->quote->reference, 'token' => 'anything']))->assertNotFound();
});

it('rechecks send eligibility under the lock after authorization', function () {
    Gate::before(function (User $user, string $ability): ?bool {
        if ($ability === 'send') {
            $this->quote->update(['valid_until' => now()->subSecond()]);
        }

        return null;
    });

    expect(fn () => app(SendQuote::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class, 'validity date has passed');
    $this->assertDatabaseCount('quote_snapshots', 0);
    expect($this->quote->fresh()->token_hash)->toBeNull();
});

it('throttles the document route', function () {
    $url = app(SendQuote::class)->handle($this->quote, $this->by)->url;
    for ($request = 0; $request < 30; $request++) {
        $this->get($url)->assertOk();
    }
    $this->get($url)->assertTooManyRequests();
});

it('refuses sending for customers merged anonymised or deleted after the previous send before authorization', function (string $change, string $message) {
    app(SendQuote::class)->handle($this->quote, $this->by);
    $before = $this->quote->fresh();
    $events = $before->events()->count();
    match ($change) {
        'merged' => $this->customer->update(['merged_into_id' => Customer::factory()->create()->id]),
        'anonymised' => $this->customer->update(['anonymised_at' => now()]),
        'deleted' => $this->customer->delete(),
    };

    expect(fn () => app(SendQuote::class)->handle($this->quote, User::factory()->stock()->create()))->toThrow(ValidationException::class, $message);
    $after = $this->quote->fresh();
    expect($after->quote_revision)->toBe(1);
    expect($after->token_hash)->toBe($before->token_hash);
    expect($after->quoteSnapshots()->count())->toBe(1);
    expect($after->events()->count())->toBe($events);
})->with([['merged', 'has been merged'], ['anonymised', 'has been anonymised'], ['deleted', 'no longer exists']]);

it('rechecks customer eligibility under the shared lock after send authorization', function (string $change) {
    Gate::before(function (User $user, string $ability) use ($change): ?bool {
        if ($ability === 'send') {
            $this->customer->update($change === 'merged'
                ? ['merged_into_id' => Customer::factory()->create()->id]
                : ['anonymised_at' => now()]);
        }

        return null;
    });

    expect(fn () => app(SendQuote::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class, $change === 'merged' ? 'has been merged' : 'has been anonymised');
    expect($this->quote->fresh()->quote_revision)->toBe(0);
    expect($this->quote->fresh()->token_hash)->toBeNull();
    $this->assertDatabaseCount('quote_snapshots', 0);
})->with(['merged', 'anonymised']);

it('locks the customer before the order when sending', function () {
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'from "customers"') && str_contains($query->sql, 'for share')) {
            $locks[] = 'customer';
        } elseif (str_contains($query->sql, 'from "orders"') && str_contains($query->sql, 'for update')) {
            $locks[] = 'order';
        }
    });

    app(SendQuote::class)->handle($this->quote, $this->by);

    expect($locks)->toBe(['customer', 'order']);
});
