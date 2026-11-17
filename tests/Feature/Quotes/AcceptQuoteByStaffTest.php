<?php

use App\Actions\Orders\AcceptQuoteByStaff;
use App\Actions\Orders\CreateQuote;
use App\Actions\Orders\OrderLineInput;
use App\Actions\Orders\SaveQuoteLines;
use App\Actions\Orders\SendQuote;
use App\Enums\AcceptedVia;
use App\Enums\OrderLineKind;
use App\Enums\OrderStatus;
use App\Enums\StockReason;
use App\Models\Customer;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'));
    $this->by = User::factory()->sales()->create();
    $this->customer = Customer::factory()->create();
    $this->location = Location::factory()->create(['is_default' => true]);
    $this->variant = Variant::factory()->create(['price_cents' => 10000]);
    StockLevel::factory()->create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'qty' => 10]);
    $this->quote = app(CreateQuote::class)->handle($this->customer, $this->by);
    $this->date = CarbonImmutable::parse('2026-11-20', 'Asia/Colombo');
    $this->lines = [new OrderLineInput(OrderLineKind::Product, $this->variant->id, null, 2, null)];
    app(SaveQuoteLines::class)->handle($this->quote, $this->lines, null, null, $this->date, $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);
});

it('accepts the sent prices without repricing and reduces stock with an auditable source', function () {
    $this->variant->update(['price_cents' => 99999]);

    $quote = app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by);

    expect($quote->status)->toBe(OrderStatus::Confirmed);
    expect($quote->source->value)->toBe('quote');
    expect($quote->accepted_via)->toBe(AcceptedVia::Staff);
    expect($quote->accepted_at->equalTo(now()))->toBeTrue();
    expect($quote->confirmed_at->equalTo(now()))->toBeTrue();
    expect($quote->lines->sole()->unit_price_cents)->toBe(10000);
    expect($quote->total_cents)->toBe(20000);
    $movement = StockMovement::sole();
    expect($movement->reason)->toBe(StockReason::QuoteAccepted);
    expect($movement->source->is($quote))->toBeTrue();
    expect($movement->delta)->toBe(-2);
    expect($movement->qty_after)->toBe(8);
    expect($movement->user_id)->toBe($this->by->id);
    expect($movement->is_override)->toBeFalse();
    $this->assertDatabaseHas('order_events', ['order_id' => $quote->id, 'type' => 'status', 'from_status' => 'quoted', 'to_status' => 'confirmed', 'note' => 'Accepted by staff']);
    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class, 'no longer open');
    $this->assertDatabaseCount('stock_movements', 1);
});

it('refuses invalid acceptance before the gate', function (string $invalid, string $message) {
    $this->quote->refresh();
    match ($invalid) {
        'unsent' => $this->quote->update(['quote_revision' => 0]),
        'expired' => $this->quote->update(['valid_until' => now()->subSecond()]),
        'closed' => $this->quote->update(['status' => OrderStatus::Expired]),
        'no-product' => $this->quote->lines()->update(['kind' => OrderLineKind::Labour]),
        'missing-variant' => $this->quote->lines()->update(['variant_id' => null]),
        'changed' => $this->quote->update(['exclusions' => 'Changed after sending']),
        'missing-snapshot' => $this->quote->quoteSnapshots()->delete(),
    };

    if ($invalid === 'unsent') {
        $this->assertDatabaseHas('orders', ['id' => $this->quote->id, 'quote_revision' => 0]);
    }
    $acceptGateReached = false;
    Gate::before(function (User $user, string $ability) use (&$acceptGateReached): ?bool {
        if ($ability === 'accept') {
            $acceptGateReached = true;
        }

        return null;
    });

    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, User::factory()->stock()->create()))->toThrow(ValidationException::class, $message);
    expect($acceptGateReached)->toBeFalse();
    $this->assertDatabaseCount('stock_movements', 0);
    expect(StockLevel::sole()->qty)->toBe(10);
})->with([
    ['unsent', 'Send the quote before marking it accepted.'], ['expired', 'Change the validity date and send it again.'],
    ['closed', 'This quote is no longer open.'], ['no-product', 'At least one product line'], ['missing-variant', 'no longer exists'],
    ['changed', 'The quote has changed since revision 1 was sent. Send it again first.'],
    ['missing-snapshot', 'The quote has changed since revision 1 was sent. Send it again first.'],
]);

it('requires resending after each change to sent content', function (string $field) {
    if (in_array($field, ['qty', 'unit_price_cents', 'description', 'sort_order'], true)) {
        $this->quote->lines()->update([$field => $field === 'description' ? 'Changed' : 3]);
    } else {
        $this->quote->update([$field => match ($field) {
            'exclusions' => 'Changed', 'valid_until' => $this->date->addDay(), default => 100,
        }]);
    }

    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class, 'The quote has changed since revision 1 was sent. Send it again first.');
    $this->assertDatabaseCount('stock_movements', 0);
})->with(['qty', 'unit_price_cents', 'description', 'sort_order', 'exclusions', 'valid_until', 'required_advance_cents', 'total_cents']);

it('accepts after changed content is sent as a new revision', function () {
    app(SaveQuoteLines::class)->handle($this->quote, $this->lines, 100, 'Changed', $this->date, $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);

    expect(app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by, '  Customer phoned  ')->status)->toBe(OrderStatus::Confirmed);
    $this->assertDatabaseHas('order_events', ['order_id' => $this->quote->id, 'note' => 'Customer phoned']);
});

it('reports all shortages and rolls back every reservation and acceptance event', function () {
    $second = Variant::factory()->create(['sku' => 'SHORT-SECOND']);
    $this->lines[] = new OrderLineInput(OrderLineKind::Product, $second->id, null, 3, 100);
    StockLevel::query()->update(['qty' => 1]);
    app(SaveQuoteLines::class)->handle($this->quote, $this->lines, null, null, $this->date, $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);
    $events = $this->quote->events()->count();

    try {
        app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by);
        $this->fail('Expected both shortages.');
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['lines']))->toContain($this->variant->sku.': wanted 2, in stock 1', 'SHORT-SECOND: wanted 3, in stock 0');
    }
    expect($this->quote->fresh()->status)->toBe(OrderStatus::Quoted);
    expect($this->quote->fresh()->accepted_at)->toBeNull();
    expect($this->quote->events()->count())->toBe($events);
    $this->assertDatabaseCount('stock_movements', 0);
    expect(StockLevel::query()->where('variant_id', $this->variant->id)->sole()->qty)->toBe(1);
});

it('overrides only short variants and records the reason and skus', function () {
    $short = Variant::factory()->create();
    $this->lines[] = new OrderLineInput(OrderLineKind::Product, $short->id, null, 1, 100);
    app(SaveQuoteLines::class)->handle($this->quote, $this->lines, null, null, $this->date, $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);

    app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by, stockOverrideReason: '  Delivery due  ');

    $movements = StockMovement::query()->orderBy('variant_id')->get();
    expect($movements[0]->is_override)->toBeFalse();
    expect($movements[1]->is_override)->toBeTrue();
    expect($movements[1]->note)->toBe('Delivery due');
    expect($movements[1]->qty_after)->toBe(-1);
    $this->assertDatabaseHas('order_events', ['order_id' => $this->quote->id, 'note' => 'Stock override: Delivery due — '.$short->sku]);
});

it('refuses stock staff and honors accept and override gates', function (string $ability) {
    $by = $ability === 'stock-role' ? User::factory()->stock()->create() : $this->by;
    if ($ability !== 'stock-role') {
        Gate::before(fn (User $user, string $requested): ?bool => $requested === $ability ? false : null);
    }
    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $by, stockOverrideReason: $ability === 'overrideStock' ? 'Reason' : null))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('stock_movements', 0);
})->with(['stock-role', 'accept', 'overrideStock']);

it('refuses customers that were merged anonymised or deleted after sending', function (string $state) {
    match ($state) {
        'merged' => $this->customer->update(['merged_into_id' => Customer::factory()->create()->id]),
        'anonymised' => $this->customer->update(['anonymised_at' => now()]),
        'deleted' => $this->customer->delete(),
    };

    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('stock_movements', 0);
})->with(['merged', 'anonymised', 'deleted']);

it('rechecks status content and customer eligibility under the locks', function (string $changed) {
    Gate::before(function (User $user, string $ability) use ($changed): ?bool {
        if ($ability === 'accept') {
            match ($changed) {
                'status' => $this->quote->update(['status' => OrderStatus::Expired]),
                'content' => $this->quote->update(['exclusions' => 'Changed']),
                'customer' => $this->customer->update(['anonymised_at' => now()]),
                'unsent' => $this->quote->refresh()->update(['quote_revision' => 0]),
                'expired' => $this->quote->update(['valid_until' => now()->subSecond()]),
                'no-product' => $this->quote->lines()->update(['kind' => OrderLineKind::Labour]),
            };
        }

        return null;
    });

    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class, match ($changed) {
        'status' => 'no longer open', 'content' => 'changed since revision', 'customer' => 'anonymised',
        'unsent' => 'Send the quote before marking it accepted.', 'expired' => 'Change the validity date and send it again.', 'no-product' => 'At least one product line',
    });
    $this->assertDatabaseCount('stock_movements', 0);
})->with(['status', 'content', 'customer', 'unsent', 'expired', 'no-product']);

it('names the product when its variant was deleted after sending', function () {
    $description = $this->quote->lines()->sole()->description;
    $this->variant->delete();

    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class, 'The variant for '.$description.' no longer exists.');
    $this->assertDatabaseCount('stock_movements', 0);
});

it('allows the exact validity deadline and refuses one second afterwards', function (bool $after) {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 23:59:59', 'Asia/Colombo')->addSeconds($after ? 1 : 0));

    if ($after) {
        expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by))->toThrow(ValidationException::class, 'This quote expired on 20 Nov 2026.');
        $this->assertDatabaseCount('stock_movements', 0);
    } else {
        expect(app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by)->status)->toBe(OrderStatus::Confirmed);
    }
})->with([true, false]);

it('validates the override reason and acceptance note before authorization', function (bool $override) {
    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, User::factory()->stock()->create(), note: $override ? null : str_repeat('x', 256), stockOverrideReason: $override ? str_repeat('x', 256) : null))->toThrow(ValidationException::class, 'at most 255 characters');
    $this->assertDatabaseCount('stock_movements', 0);
})->with([true, false]);

it('accepts untracked products without creating a stock movement', function () {
    $this->variant->update(['track_stock' => false]);

    expect(app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by)->status)->toBe(OrderStatus::Confirmed);
    $this->assertDatabaseCount('stock_movements', 0);
    expect(StockLevel::sole()->qty)->toBe(10);
});

it('aggregates duplicate quote variants in one stock movement', function () {
    app(SaveQuoteLines::class)->handle($this->quote, [$this->lines[0], $this->lines[0]], null, null, $this->date, $this->by);
    app(SendQuote::class)->handle($this->quote, $this->by);

    app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by);

    expect(StockMovement::sole()->delta)->toBe(-4);
    expect(StockLevel::sole()->qty)->toBe(6);
});

it('maps an override stock limit failure to validation and rolls back acceptance', function () {
    StockLevel::query()->update(['qty' => -2147483648]);

    expect(fn () => app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by, stockOverrideReason: 'Correction'))
        ->toThrow(ValidationException::class, 'minimum stock quantity');
    expect($this->quote->fresh()->status)->toBe(OrderStatus::Quoted);
    expect(StockLevel::sole()->qty)->toBe(-2147483648);
    $this->assertDatabaseCount('stock_movements', 0);
});

it('accepts a sent product even if its variant was later deactivated', function () {
    $this->variant->update(['is_active' => false]);

    $quote = app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by);

    expect($quote->status)->toBe(OrderStatus::Confirmed);
    expect(StockLevel::sole()->qty)->toBe(8);
    expect(StockMovement::sole()->delta)->toBe(-2);
    expect(StockMovement::sole()->reason)->toBe(StockReason::QuoteAccepted);
});

it('accepts identical sent content re saved with new line ids', function () {
    $oldIds = $this->quote->lines()->pluck('id')->all();
    app(SaveQuoteLines::class)->handle($this->quote, $this->lines, null, null, $this->date, $this->by);
    expect($this->quote->lines()->pluck('id')->all())->not->toBe($oldIds);

    $quote = app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by);

    expect($quote->quote_revision)->toBe(1);
    expect($quote->status)->toBe(OrderStatus::Confirmed);
    expect(StockLevel::sole()->qty)->toBe(8);
});

it('ignores internal cost changes when comparing the sent quote', function () {
    $this->quote->lines()->update(['unit_cost_cents' => 123456]);

    $quote = app(AcceptQuoteByStaff::class)->handle($this->quote, $this->by);

    expect($quote->status)->toBe(OrderStatus::Confirmed);
    expect($quote->lines->sole()->unit_cost_cents)->toBe(123456);
    expect(StockLevel::sole()->qty)->toBe(8);
});
