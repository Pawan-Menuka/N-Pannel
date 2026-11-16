<?php

use App\Actions\Purchasing\DeleteSupplier;
use App\Actions\Purchasing\SaveSupplier;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('saves normalized supplier details and logs creation and updates', function () {
    $actor = User::factory()->stock()->create();
    $supplier = app(SaveSupplier::class)->handle(null, [
        'name' => '  Building Supplies  ', 'phone_e164' => '077 123 4567',
        'email' => ' orders@example.com ', 'address' => ' Colombo ', 'notes' => ' Morning deliveries ',
    ], $actor);

    expect($supplier->name)->toBe('Building Supplies');
    expect($supplier->phone_e164)->toBe('+94771234567');
    expect($supplier->email)->toBe('orders@example.com');
    expect($supplier->address)->toBe('Colombo');
    expect($supplier->notes)->toBe('Morning deliveries');
    expect($supplier->is_active)->toBeTrue();
    app(SaveSupplier::class)->handle($supplier, ['name' => 'Updated', 'phone_e164' => '011-2345678', 'is_active' => false], $actor);
    expect($supplier->fresh()->phone_e164)->toBe('+94112345678');
    expect($supplier->fresh()->is_active)->toBeFalse();
    expect(Activity::query()->where('subject_type', $supplier->getMorphClass())->where('subject_id', $supplier->id)->pluck('event')->all())->toBe(['created', 'updated']);
});

it('allows blank optional supplier fields and rejects invalid inputs', function (array $data, string $field) {
    $actor = User::factory()->admin()->create();
    try {
        app(SaveSupplier::class)->handle(null, $data, $actor);
        $this->fail('Expected validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('suppliers', 0);
})->with([
    [['name' => '  '], 'name'], [['name' => str_repeat('a', 256)], 'name'],
    [['name' => 'Supplier', 'email' => 'invalid'], 'email'],
    [['name' => 'Supplier', 'phone_e164' => 'abc'], 'phone_e164'],
]);

it('stores blank optional contact details as null', function () {
    $supplier = app(SaveSupplier::class)->handle(null, ['name' => 'Supplier', 'phone_e164' => ' ', 'email' => '', 'address' => '', 'notes' => ' '], User::factory()->stock()->create());
    expect($supplier->phone_e164)->toBeNull();
    expect($supplier->email)->toBeNull();
    expect($supplier->address)->toBeNull();
    expect($supplier->notes)->toBeNull();
});

it('refuses to delete a supplier with any goods receipts and permits deactivation', function () {
    $actor = User::factory()->stock()->create();
    $supplier = Supplier::factory()->create([
        'phone_e164' => '+94771234567', 'email' => 'orders@example.com',
        'address' => 'Colombo', 'notes' => 'Morning deliveries',
    ]);
    GoodsReceipt::factory()->create(['supplier_id' => $supplier->id]);

    expect(fn () => app(DeleteSupplier::class)->handle($supplier, $actor))->toThrow(ValidationException::class, 'Deactivate the supplier instead.');
    $this->assertModelExists($supplier);
    app(SaveSupplier::class)->handle($supplier, ['name' => $supplier->name, 'is_active' => false], $actor);
    expect($supplier->fresh()->is_active)->toBeFalse();
    expect($supplier->fresh()->phone_e164)->toBe('+94771234567');
    expect($supplier->fresh()->email)->toBe('orders@example.com');
    expect($supplier->fresh()->address)->toBe('Colombo');
    expect($supplier->fresh()->notes)->toBe('Morning deliveries');
});

it('deletes suppliers without receipts and logs the deletion', function () {
    $supplier = Supplier::factory()->create();
    expect(app(DeleteSupplier::class)->handle($supplier, User::factory()->stock()->create()))->toBeTrue();
    $this->assertModelMissing($supplier);
    expect(Activity::query()->where('subject_type', $supplier->getMorphClass())->where('subject_id', $supplier->id)->where('event', 'deleted')->exists())->toBeTrue();
});

it('denies sales staff direct supplier writes', function () {
    $sales = User::factory()->sales()->create();
    $supplier = Supplier::factory()->create();
    expect(fn () => app(SaveSupplier::class)->handle(null, ['name' => 'New'], $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveSupplier::class)->handle($supplier, ['name' => 'Changed'], $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeleteSupplier::class)->handle($supplier, $sales))->toThrow(AuthorizationException::class);
    expect($supplier->fresh()->name)->toBe($supplier->name);
});

it('defaults absent supplier contact fields to null on creation', function () {
    $supplier = app(SaveSupplier::class)->handle(null, ['name' => 'Supplier'], User::factory()->stock()->create());

    expect($supplier->phone_e164)->toBeNull();
    expect($supplier->email)->toBeNull();
    expect($supplier->address)->toBeNull();
    expect($supplier->notes)->toBeNull();
});

it('clears contact fields only when explicitly supplied as blank or null', function () {
    $supplier = Supplier::factory()->create([
        'phone_e164' => '+94771234567', 'email' => 'orders@example.com',
        'address' => 'Colombo', 'notes' => 'Morning deliveries',
    ]);
    $actor = User::factory()->stock()->create();

    $supplier = app(SaveSupplier::class)->handle($supplier, ['name' => $supplier->name, 'phone_e164' => ' ', 'email' => null], $actor);

    expect($supplier->phone_e164)->toBeNull();
    expect($supplier->email)->toBeNull();
    expect($supplier->address)->toBe('Colombo');
    expect($supplier->notes)->toBe('Morning deliveries');
});
