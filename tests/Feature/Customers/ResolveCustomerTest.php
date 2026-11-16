<?php

use App\Actions\Customers\ResolveCustomer;
use App\Enums\CustomerType;
use App\Enums\PriceTier;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('creates a normalized customer and finds alternate phone formats without renaming', function () {
    $actor = User::factory()->sales()->create();
    $action = app(ResolveCustomer::class);
    $result = $action->handle('0771234567', '  Nimal  ', $actor, ['type' => CustomerType::Contractor, 'price_tier' => PriceTier::Trade]);
    expect($result->created)->toBeTrue();
    expect($result->customer->phone_e164)->toBe('+94771234567');
    expect($result->customer->name)->toBe('Nimal');
    expect($result->customer->last_activity_at)->not->toBeNull();
    $found = $action->handle('+94 77 123 4567', 'nimal', $actor);
    expect($found->created)->toBeFalse();
    expect($found->customer->id)->toBe($result->customer->id);
    expect($found->customer->name)->toBe('Nimal');
    expect($found->customer->notes)->toBeNull();
    $this->travel(1)->days();
    $found = $action->handle('0771234567', 'Nimal', $actor);
    expect($found->customer->last_activity_at->greaterThan($result->customer->last_activity_at))->toBeTrue();
});

it('appends an alternate name once and preserves staff edits and notes', function () {
    $this->travelTo(Carbon::parse('2026-01-01 20:00:00', 'UTC'));
    $customer = Customer::factory()->create(['phone_e164' => '+94771234567', 'name' => 'Staff name', 'name_edited_by_staff' => true, 'notes' => 'Original']);
    $actor = User::factory()->sales()->create();
    $action = app(ResolveCustomer::class);
    $action->handle('0771234567', ' Other name ', $actor, ['notes' => 'Overwrite']);
    $this->travelTo(Carbon::parse('2026-01-03 20:00:00', 'UTC'));
    $action->handle('0771234567', 'OTHER NAME', $actor);
    expect($customer->fresh()->name)->toBe('Staff name');
    expect($customer->fresh()->notes)->toBe('Original'."\n".'Also gave the name "Other name" on 2026-01-02.');
});

it('follows merge chains and refuses cycles without modifying records', function () {
    $actor = User::factory()->sales()->create();
    $survivor = Customer::factory()->create();
    $middle = Customer::factory()->create(['merged_into_id' => $survivor->id]);
    $old = Customer::factory()->create(['phone_e164' => '+94771234567', 'merged_into_id' => $middle->id]);
    expect(app(ResolveCustomer::class)->handle('0771234567', $survivor->name, $actor)->customer->id)->toBe($survivor->id);
    $survivor->update(['merged_into_id' => $old->id]);
    expect(fn () => app(ResolveCustomer::class)->handle('0771234567', 'Name', $actor))->toThrow(ValidationException::class);
});

it('always creates without a phone and ignores protected attributes', function () {
    $actor = User::factory()->sales()->create();
    $action = app(ResolveCustomer::class);
    $first = $action->handle(null, 'Email only', $actor, ['is_walk_in' => true, 'merged_into_id' => 123, 'anonymised_at' => now(), 'email' => ' person@example.com ']);
    $second = $action->handle(' ', 'Email only', $actor);
    expect($first->created)->toBeTrue();
    expect($second->created)->toBeTrue();
    expect($first->customer->id)->not->toBe($second->customer->id);
    expect($first->customer->is_walk_in)->toBeFalse();
    expect($first->customer->merged_into_id)->toBeNull();
    expect($first->customer->anonymised_at)->toBeNull();
    expect($first->customer->email)->toBe('person@example.com');
});

it('validates customer input', function (?string $phone, string $name, array $attributes, string $field) {
    try {
        app(ResolveCustomer::class)->handle($phone, $name, User::factory()->sales()->create(), $attributes);
        $this->fail('Expected validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('customers', 0);
})->with([
    ['bad', 'Name', [], 'phone_e164'], [null, ' ', [], 'name'],
    [null, str_repeat('n', 256), [], 'name'], [null, 'Name', ['email' => 'bad'], 'email'],
    [null, 'Name', ['type' => 'other'], 'type'], [null, 'Name', ['price_tier' => 'free'], 'price_tier'],
    [null, 'Name', ['district_id' => 999999], 'district_id'], [null, 'Name', ['notes' => str_repeat('a', 2001)], 'notes'],
    [null, 'Name', ['city' => str_repeat('a', 256)], 'city'],
]);

it('denies stock staff both existing and new customers', function () {
    Customer::factory()->create(['phone_e164' => '+94771234567']);
    $actor = User::factory()->stock()->create();
    foreach (['0771234567', '0777654321', null] as $phone) {
        expect(fn () => app(ResolveCustomer::class)->handle($phone, 'Name', $actor))->toThrow(AuthorizationException::class);
    }
});

it('recovers a unique conflict inside a transaction through createOrFirst', function () {
    $existing = Customer::factory()->create(['phone_e164' => '+94771234567']);
    DB::transaction(function () use ($existing) {
        $winner = Customer::query()->createOrFirst(['phone_e164' => '+94771234567'], ['name' => 'Losing name']);
        expect($winner->id)->toBe($existing->id);
        expect($winner->wasRecentlyCreated)->toBeFalse();
        expect(Customer::query()->count())->toBe(1);
    });
});

it('returns the single walk in customer', function () {
    $action = app(ResolveCustomer::class);
    $first = $action->walkIn();
    expect($action->walkIn()->id)->toBe($first->id);
    expect($first->name)->toBe('Walk-in customer');
    expect(Customer::query()->where('is_walk_in', true)->count())->toBe(1);
});

it('skips alias notes that would exceed the editable notes limit', function () {
    $customer = Customer::factory()->create(['phone_e164' => '+94771234567', 'name' => 'Original', 'notes' => str_repeat('a', 1990)]);

    app(ResolveCustomer::class)->handle('0771234567', 'Alternate', User::factory()->sales()->create());

    expect($customer->fresh()->notes)->toBe(str_repeat('a', 1990));
});

it('creates a fresh contact instead of writing personal details onto an anonymised customer', function (bool $merged) {
    $actor = User::factory()->sales()->create();
    $removed = Customer::factory()->create([
        'phone_e164' => $merged ? null : '+94771234567', 'name' => 'Removed customer',
        'anonymised_at' => now(), 'notes' => 'Retained note', 'last_activity_at' => now()->subDays(5),
    ]);
    $removed->refresh();
    $before = $removed->getAttributes();
    $middle = $merged ? Customer::factory()->create(['phone_e164' => null, 'merged_into_id' => $removed->id]) : null;
    $holder = $merged ? Customer::factory()->create(['phone_e164' => '+94771234567', 'merged_into_id' => $middle->id, 'notes' => 'Old contact']) : $removed;

    $result = app(ResolveCustomer::class)->handle('0771234567', 'New person', $actor, ['city' => 'Kandy']);

    expect($result->created)->toBeTrue();
    expect($result->customer->id)->not->toBe($removed->id);
    expect($result->customer->phone_e164)->toBe('+94771234567');
    expect($result->customer->name)->toBe('New person');
    expect($result->customer->city)->toBe('Kandy');
    expect($result->customer->anonymised_at)->toBeNull();
    expect($holder->fresh()->phone_e164)->toBeNull();
    expect($removed->fresh()->notes)->toBe('Retained note');
    expect($removed->fresh()->last_activity_at->equalTo($removed->last_activity_at))->toBeTrue();
    if ($merged) {
        expect($removed->fresh()->getAttributes())->toBe($before);
        expect($holder->fresh()->notes)->toBe('Old contact');
        expect($holder->fresh()->merged_into_id)->toBe($middle->id);
    }
})->with([false, true]);

it('handles a competitor inserted after its lookup and follows the returned winners merge chain', function (bool $merged) {
    $actor = User::factory()->sales()->create();
    $survivor = $merged ? Customer::factory()->create(['name' => 'Winner', 'phone_e164' => null]) : null;
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $this->assertNotNull($dispatcher);
    $connection->setEventDispatcher(clone $dispatcher);
    $inserted = false;
    $winnerId = null;
    try {
        $connection->listen(function (QueryExecuted $query) use ($connection, $survivor, &$inserted, &$winnerId): void {
            if ($inserted || ! str_starts_with($query->sql, 'select')
                || ! str_contains($query->sql, '"customers"') || ! str_contains($query->sql, '"phone_e164" = ?')
                || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $inserted = true;
            $winnerId = $connection->table('customers')->insertGetId([
                'phone_e164' => '+94771234567', 'name' => 'Winner', 'merged_into_id' => $survivor?->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $result = app(ResolveCustomer::class)->handle('0771234567', 'Alternate', $actor);

        expect($inserted)->toBeTrue();
        expect($result->created)->toBeFalse();
        expect($result->customer->id)->toBe($survivor?->id ?? $winnerId);
        expect($result->customer->name)->toBe('Winner');
        expect($result->customer->notes)->toBe('Also gave the name "Alternate" on '.now('Asia/Colombo')->toDateString().'.');
        expect(Customer::query()->count())->toBe($merged ? 2 : 1);
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
})->with([false, true]);
