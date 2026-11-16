<?php

use App\Actions\Customers\DeleteCustomer;
use App\Actions\Customers\ResolveCustomer;
use App\Actions\Customers\SearchCustomers;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('partially updates customer details normalizes blanks and logs create and update', function () {
    $actor = User::factory()->sales()->create();
    $customer = app(ResolveCustomer::class)->handle('0771234567', 'Original', $actor, ['city' => 'Kandy', 'email' => 'old@example.com'])->customer;
    app(UpdateCustomer::class)->handle($customer, ['name' => ' Changed ', 'email' => ' ', 'phone_e164' => '011 234 5678', 'is_walk_in' => true], $actor);
    $customer->refresh();
    expect($customer->name)->toBe('Changed');
    expect($customer->name_edited_by_staff)->toBeTrue();
    expect($customer->city)->toBe('Kandy');
    expect($customer->email)->toBeNull();
    expect($customer->phone_e164)->toBe('+94112345678');
    expect($customer->is_walk_in)->toBeFalse();
    expect(Activity::query()->where('subject_type', $customer->getMorphClass())->where('subject_id', $customer->id)->pluck('event')->all())->toBe(['created', 'updated']);
});

it('rejects another customers phone with its name', function () {
    Customer::factory()->create(['name' => 'Nimal', 'phone_e164' => '+94771234567']);
    $customer = Customer::factory()->create();
    try {
        app(UpdateCustomer::class)->handle($customer, ['phone_e164' => '0771234567'], User::factory()->sales()->create());
        $this->fail('Expected validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['phone_e164'])->toBe(['That number already belongs to Nimal.']);
    }
});

it('refuses updates to protected customers before authorization', function (string $state) {
    $attributes = match ($state) {
        'walk-in' => ['is_walk_in' => true],
        'merged' => ['merged_into_id' => Customer::factory()->create()->id],
        'anonymised' => ['anonymised_at' => now()],
    };
    $customer = Customer::factory()->create($attributes);
    expect(fn () => app(UpdateCustomer::class)->handle($customer, ['name' => 'Changed'], User::factory()->stock()->create()))->toThrow(ValidationException::class);
})->with(['walk-in', 'merged', 'anonymised']);

it('allows only admins to delete customers without documents', function () {
    $customer = Customer::factory()->create();
    expect(fn () => app(DeleteCustomer::class)->handle($customer, User::factory()->sales()->create()))->toThrow(AuthorizationException::class);
    expect(app(DeleteCustomer::class)->handle($customer, User::factory()->admin()->create()))->toBeTrue();
    $this->assertModelMissing($customer);
});

it('refuses deletion of walk in customers and customers with orders or invoices', function (string $state) {
    $customer = Customer::factory()->create(['is_walk_in' => $state === 'walk-in']);
    if ($state === 'orders') {
        Order::factory()->create(['customer_id' => $customer->id]);
    }
    if ($state === 'invoices') {
        Invoice::factory()->create(['customer_id' => $customer->id]);
    }
    expect(fn () => app(DeleteCustomer::class)->handle($customer, User::factory()->admin()->create()))->toThrow(ValidationException::class);
    $this->assertModelExists($customer);
})->with(['walk-in', 'orders', 'invoices']);

it('sums only live orders with nonnegative individual balances in one query', function () {
    $customer = Customer::factory()->create();
    foreach (OrderStatus::cases() as $status) {
        Order::factory()->create(['customer_id' => $customer->id, 'status' => $status, 'total_cents' => 10000, 'paid_cents' => 3000]);
    }
    Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Confirmed, 'total_cents' => 1000, 'paid_cents' => 2000]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $balance = $customer->balanceCents();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($balance)->toBe(35000);
    expect($queries)->toHaveCount(1);
    expect(Customer::query()->withBalance()->findOrFail($customer->id)->balance_cents)->toBe(35000);
    expect(Customer::factory()->create()->balanceCents())->toBe(0);
});

it('retains the staff name flag for unchanged names and clears only supplied optional fields', function () {
    $customer = Customer::factory()->create(['name' => 'Nimal', 'email' => 'person@example.com', 'city' => 'Kandy']);
    app(UpdateCustomer::class)->handle($customer, ['name' => ' Nimal ', 'phone_e164' => ' ', 'email' => null], User::factory()->sales()->create());
    $customer->refresh();
    expect($customer->name_edited_by_staff)->toBeFalse();
    expect($customer->phone_e164)->toBeNull();
    expect($customer->email)->toBeNull();
    expect($customer->city)->toBe('Kandy');
});

it('denies stock staff direct updates to ordinary customers', function () {
    $customer = Customer::factory()->create(['name' => 'Original']);
    expect(fn () => app(UpdateCustomer::class)->handle($customer, ['name' => 'Changed'], User::factory()->stock()->create()))->toThrow(AuthorizationException::class);
    expect($customer->fresh()->name)->toBe('Original');
});

it('scopes owing money to customers with an unpaid counted order', function () {
    $overpaid = Customer::factory()->create();
    $quoted = Customer::factory()->create();
    $owing = Customer::factory()->create();
    $settled = Customer::factory()->create();
    $withoutOrders = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $overpaid->id, 'status' => OrderStatus::Confirmed, 'total_cents' => 1000, 'paid_cents' => 2000]);
    Order::factory()->create(['customer_id' => $quoted->id, 'status' => OrderStatus::Quoted, 'total_cents' => 1000, 'paid_cents' => 0]);
    Order::factory()->create(['customer_id' => $owing->id, 'status' => OrderStatus::Confirmed, 'total_cents' => 1000, 'paid_cents' => 500]);
    Order::factory()->create(['customer_id' => $owing->id, 'status' => OrderStatus::Completed, 'total_cents' => 1000, 'paid_cents' => 2000]);
    Order::factory()->create(['customer_id' => $settled->id, 'status' => OrderStatus::Completed, 'total_cents' => 1000, 'paid_cents' => 1000]);

    $customers = Customer::query()->whereKey([$overpaid->id, $quoted->id, $owing->id, $settled->id, $withoutOrders->id])->owingMoney()->get();

    expect($customers->modelKeys())->toBe([$owing->id]);
});

it('refuses to delete the survivor of merged customer records', function () {
    $survivor = Customer::factory()->create();
    $merged = Customer::factory()->create(['merged_into_id' => $survivor->id]);

    expect(fn () => app(DeleteCustomer::class)->handle($survivor, User::factory()->admin()->create()))
        ->toThrow(ValidationException::class, 'Other customer records are merged into this customer; it cannot be deleted.');

    $this->assertModelExists($survivor);
    expect($merged->fresh()->merged_into_id)->toBe($survivor->id);
});

it('names the surviving customer when a duplicate phone belongs to a merged record', function () {
    $survivor = Customer::factory()->create(['name' => 'Surviving name', 'phone_e164' => null]);
    $middle = Customer::factory()->create(['merged_into_id' => $survivor->id]);
    Customer::factory()->create(['name' => 'Hidden name', 'phone_e164' => '+94771234567', 'merged_into_id' => $middle->id]);
    $customer = Customer::factory()->create();
    $originalPhone = $customer->phone_e164;

    expect(fn () => app(UpdateCustomer::class)->handle($customer, ['phone_e164' => '0771234567'], User::factory()->sales()->create()))
        ->toThrow(ValidationException::class, 'That number already belongs to Surviving name.');

    expect($customer->fresh()->phone_e164)->toBe($originalPhone);
});

it('searches phone shaped partial numbers in local and international formats', function (string $search, string $phone) {
    $customer = Customer::factory()->create(['phone_e164' => $phone, 'name' => 'Nimal']);
    $other = Customer::factory()->create(['phone_e164' => '+94112345678', 'name' => 'Other']);

    $matches = app(SearchCustomers::class)->handle(Customer::query()->whereKey([$customer->id, $other->id]), $search)->get();

    expect($matches->modelKeys())->toBe([$customer->id]);
})->with([
    ['0775', '+94775123456'], ['077 123', '+94771234567'], ['+94 77', '+94771234567'],
    ['0094 77', '+94771234567'], ['9477', '+94771234567'],
]);

it('does not extract phone digits from names or short search terms', function (string $search) {
    $named = Customer::factory()->create(['name' => $search, 'phone_e164' => '+94771234567']);
    $phoneOnly = Customer::factory()->create(['name' => 'Other', 'phone_e164' => '+94772000077', 'company_name' => null]);

    $matches = app(SearchCustomers::class)->handle(Customer::query()->whereKey([$named->id, $phoneOnly->id]), $search)->get();

    expect($matches->modelKeys())->toBe([$named->id]);
})->with(['Lanka Ceilings 2000', '77']);

it('treats search wildcards and backslashes as literal text', function () {
    $search = '%_'.chr(92);
    $literal = Customer::factory()->create(['name' => 'Company '.$search.' Supplies']);
    $other = Customer::factory()->create(['name' => 'Other company', 'company_name' => null]);

    $matches = app(SearchCustomers::class)->handle(Customer::query()->whereKey([$literal->id, $other->id]), $search)->get();

    expect($matches->modelKeys())->toBe([$literal->id]);
});
