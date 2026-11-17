<?php

use App\Actions\Invoices\GetInvoiceLink;
use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\RotateInvoiceToken;
use App\Actions\Invoices\VoidInvoice;
use App\Enums\CustomerType;
use App\Enums\DocumentStatus;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\DocumentSettings;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Colombo'));
    $this->by = User::factory()->sales()->create();
    $this->customer = Customer::factory()->create([
        'name' => 'Nimal', 'type' => CustomerType::Company, 'company_name' => 'Nimal Building Ltd', 'vat_number' => 'BUYER-VAT',
        'phone_e164' => '+94771234567', 'email' => 'nimal@example.test',
        'address_line1' => '24 Temple Road', 'address_line2' => 'Floor 2', 'city' => 'Colombo',
    ]);
    $this->order = Order::factory()->create([
        'customer_id' => $this->customer->id, 'created_by_id' => $this->by->id,
        'status' => OrderStatus::Confirmed, 'customer_name' => 'Old name', 'phone_e164' => '+94770000000',
        'subtotal_cents' => 12000000, 'delivery_cents' => 100000, 'discount_cents' => 300000,
        'total_cents' => 11800000, 'paid_cents' => 1000000,
    ]);
    $this->line = OrderLine::factory()->create([
        'order_id' => $this->order->id, 'qty' => 2, 'unit_price_cents' => 6000000,
        'line_total_cents' => 12000000, 'unit_cost_cents' => 999,
    ]);
    $tax = app(TaxSettings::class);
    $tax->vat_number = '  SELLER-VAT  ';
    $tax->vat_rate_bp = 1800;
    $tax->save();
    $documents = app(DocumentSettings::class);
    $documents->invoice_due_days = 7;
    $documents->save();
});

it('issues an immutable tax snapshot and a hashed public bearer link', function () {
    $result = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $invoice = $result->invoice->refresh();

    expect($invoice->number)->toBe('INV-2026-00001');
    expect($invoice->status)->toBe(DocumentStatus::Issued);
    expect($invoice->customer_id)->toBe($this->customer->id);
    expect($invoice->customer_snapshot)->toEqual([
        'name' => 'Nimal', 'company_name' => 'Nimal Building Ltd', 'phone_e164' => '+94771234567',
        'email' => 'nimal@example.test', 'address_line1' => '24 Temple Road', 'address_line2' => 'Floor 2',
        'city' => 'Colombo', 'vat_number' => 'BUYER-VAT',
    ]);
    expect($invoice->lines)->toEqual([[
        'kind' => $this->line->kind->value, 'sku' => $this->line->sku, 'description' => $this->line->description,
        'sale_unit' => $this->line->sale_unit->value, 'qty' => 2, 'unit_price_cents' => 6000000,
        'line_total_cents' => 12000000, 'sort_order' => 0,
    ]]);
    expect($invoice->subtotal_cents)->toBe(12000000);
    expect($invoice->delivery_cents)->toBe(100000);
    expect($invoice->discount_cents)->toBe(300000);
    expect($invoice->total_cents)->toBe(11800000);
    expect($invoice->tax_cents)->toBe(1800000);
    expect($invoice->vat_rate_bp)->toBe(1800);
    expect($invoice->seller_vat_number)->toBe('SELLER-VAT');
    expect($invoice->issued_by_id)->toBe($this->by->id);
    expect($invoice->locale)->toBe($this->order->locale);
    expect($invoice->issued_on->format('Y-m-d'))->toBe('2026-11-17');
    expect($invoice->due_on->format('Y-m-d'))->toBe('2026-11-24');
    expect($this->order->fresh()->tax_cents)->toBe(1800000);
    expect($this->order->activeInvoice()->id)->toBe($invoice->id);
    expect($result->token)->toMatch('/^[a-f0-9]{64}$/D');
    expect($invoice->token_hash)->toBe(hash('sha256', $result->token));
    expect($result->url)->toBe(route('invoices.show', ['number' => $invoice->number, 'token' => $result->token]));
    expect($result->whatsAppText)->toContain('Hello Nimal', app(BusinessSettings::class)->name, $invoice->number, 'Rs 118,000.00', 'balance due Rs 108,000.00', $result->url);
    expect($result->whatsAppUrl)->toBe('https://wa.me/94771234567?text='.rawurlencode($result->whatsAppText));
    $this->get($result->url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($this->get($result->url)->headers->get('Cache-Control'))->toContain('no-store');
    $this->get(route('invoices.show', ['number' => $invoice->number, 'token' => 'wrong']))->assertNotFound();
    $this->get(route('invoices.show', ['number' => 'INV-2026-99999', 'token' => $result->token]))->assertNotFound();
    $this->assertDatabaseHas('order_events', ['order_id' => $this->order->id, 'type' => 'note', 'note' => 'Invoice INV-2026-00001 issued']);
    expect(Activity::query()->get()->toJson())->not->toContain($result->token, 'token_hash', 'unit_cost_cents":999');
    $this->assertDatabaseCount('stock_movements', 0);
});

it('issues plain invoices when both VAT settings are empty', function (?string $number) {
    $tax = app(TaxSettings::class);
    $tax->vat_number = $number;
    $tax->vat_rate_bp = null;
    $tax->save();
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    expect($invoice->vat_rate_bp)->toBeNull();
    expect($invoice->seller_vat_number)->toBeNull();
    expect($invoice->tax_cents)->toBe(0);
    expect($this->order->fresh()->tax_cents)->toBe(0);
})->with([[null], [''], ['  ']]);

it('refuses incomplete or invalid tax settings before authorization', function (?string $number, ?int $rate) {
    $tax = app(TaxSettings::class);
    $tax->vat_number = $number;
    $tax->vat_rate_bp = $rate;
    $tax->save();

    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'Tax settings are incomplete: set both the VAT number and rate, or neither.');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('document_sequences', 0);
    expect($this->order->fresh()->tax_cents)->toBe(0);
})->with([[null, 1800], ['', 1800], ['  ', 1800], ['VAT', null], ['VAT', 0], ['VAT', -1], ['VAT', 10001], [null, 0]]);

it('rechecks tax registration after authorization before allocating a number', function () {
    Gate::before(function (User $user, string $ability): ?bool {
        if ($ability === 'create') {
            $tax = app(TaxSettings::class);
            $tax->vat_number = null;
            $tax->save();
        }

        return null;
    });
    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, $this->by))
        ->toThrow(ValidationException::class, 'Tax settings are incomplete');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('document_sequences', 0);
});

it('accepts each invoicable order status', function (OrderStatus $status) {
    $this->order->update(['status' => $status]);
    expect(app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice->exists)->toBeTrue();
})->with([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyForCollection, OrderStatus::Dispatched, OrderStatus::Completed]);

it('refuses unaccepted orders before the Gate', function (OrderStatus $status) {
    $this->order->update(['status' => $status]);
    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'Only an accepted order can be invoiced.');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('document_sequences', 0);
})->with([OrderStatus::Quoted, OrderStatus::Placed, OrderStatus::Cancelled, OrderStatus::Expired]);

it('refuses empty orders before the Gate', function () {
    $this->order->lines()->delete();
    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'Add at least one line');
});

it('keeps void numbers and permits reissue with the next number', function () {
    $first = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'This order already has invoice INV-2026-00001; void it first.');
    $voided = app(VoidInvoice::class)->handle($first->invoice, '  Wrong details  ', $this->by);
    expect($voided->status)->toBe(DocumentStatus::Void);
    expect($voided->void_reason)->toBe('Wrong details');
    expect($voided->voided_at->equalTo(now()))->toBeTrue();
    expect($voided->token_hash)->toBeNull();
    expect($voided->tax_cents)->toBe(1800000);
    expect($this->order->fresh()->tax_cents)->toBe(0);
    expect($this->order->activeInvoice())->toBeNull();
    $this->get($first->url)->assertNotFound();
    $this->assertDatabaseHas('order_events', ['order_id' => $this->order->id, 'type' => 'note', 'note' => 'Invoice INV-2026-00001 voided: Wrong details']);
    expect(fn () => app(VoidInvoice::class)->handle($first->invoice, 'Again', User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'This invoice is already void.');
    expect(app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice->number)->toBe('INV-2026-00002');
    expect(Invoice::query()->count())->toBe(2);
});

it('validates the void reason before authorization without changing anything', function (string $reason) {
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect(fn () => app(VoidInvoice::class)->handle($issued->invoice, $reason, User::factory()->stock()->create()))->toThrow(ValidationException::class);
    expect($issued->invoice->fresh()->status)->toBe(DocumentStatus::Issued);
    $this->get($issued->url)->assertOk();
})->with(['', '   ', str_repeat('x', 256)]);

it('applies default and explicit due dates as local calendar dates', function (int $days, ?string $due, string $expected) {
    $settings = app(DocumentSettings::class);
    $settings->invoice_due_days = $days;
    $settings->save();
    $date = $due === null ? null : CarbonImmutable::parse($due, 'Asia/Colombo');
    expect(app(IssueInvoice::class)->handle($this->order, $date, $this->by)->invoice->due_on->format('Y-m-d'))->toBe($expected);
})->with([[0, null, '2026-11-17'], [7, null, '2026-11-24'], [7, '2026-11-17', '2026-11-17'], [7, '2026-12-01', '2026-12-01']]);

it('refuses past due dates before the Gate', function () {
    expect(fn () => app(IssueInvoice::class)->handle($this->order, CarbonImmutable::parse('2026-11-16', 'Asia/Colombo'), User::factory()->stock()->create()))
        ->toThrow(ValidationException::class, 'The due date cannot be before the issue date.');
});

it('denies stock staff all invoice actions', function () {
    $stock = User::factory()->stock()->create();
    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, $stock))->toThrow(AuthorizationException::class);
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect(fn () => app(VoidInvoice::class)->handle($issued->invoice, 'Wrong', $stock))->toThrow(AuthorizationException::class);
    expect(fn () => app(GetInvoiceLink::class)->handle($issued->invoice, $stock))->toThrow(AuthorizationException::class);
    $this->get($issued->url)->assertOk();
});

it('rotates the link without changing the invoice snapshot and refuses void links', function () {
    $first = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    $snapshot = $first->invoice->customer_snapshot;
    $this->customer->update(['name' => 'Changed']);
    $second = app(GetInvoiceLink::class)->handle($first->invoice, $this->by);
    expect($second->token)->not->toBe($first->token);
    expect($second->invoice->token_hash)->toBe(hash('sha256', $second->token));
    expect($second->invoice->customer_snapshot)->toEqual($snapshot);
    expect($second->whatsAppText)->toContain('Hello Nimal');
    $this->get($first->url)->assertNotFound();
    $this->get($second->url)->assertOk();
    app(VoidInvoice::class)->handle($second->invoice, 'Wrong', $this->by);
    expect(fn () => app(GetInvoiceLink::class)->handle($second->invoice, User::factory()->stock()->create()))->toThrow(ValidationException::class, 'This invoice is void.');
});

it('protects invoice contents from model updates', function (string $field, mixed $value) {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    expect(fn () => $invoice->update([$field => $value]))->toThrow(LogicException::class, 'Issued invoices cannot be edited.');
})->with([['number', 'INV-2026-99999'], ['total_cents', 1], ['lines', []], ['customer_snapshot', []], ['tax_cents', 1], ['issued_on', '2026-11-16'], ['seller_vat_number', 'changed']]);

it('refuses model deletion even after voiding', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    expect(fn () => $invoice->delete())->toThrow(LogicException::class, 'Invoices cannot be deleted.');
    app(VoidInvoice::class)->handle($invoice, 'Wrong', $this->by);
    expect(fn () => $invoice->refresh()->delete())->toThrow(LogicException::class);
    expect(fn () => $invoice->refresh()->update(['total_cents' => 1]))->toThrow(LogicException::class);
});

it('requires a transaction for token rotation', function () {
    $connection = DB::getDefaultConnection();
    config(['database.connections.invoice_token' => config('database.connections.'.$connection)]);
    DB::setDefaultConnection('invoice_token');
    try {
        expect(fn () => app(RotateInvoiceToken::class)->handle(new Invoice))->toThrow(LogicException::class, 'requires a database transaction');
    } finally {
        DB::setDefaultConnection($connection);
        DB::purge('invoice_token');
    }
});

it('uses safe customer names and never revives copied personal details', function (string $kind, string $name) {
    $this->customer->update($kind === 'walk-in' ? ['is_walk_in' => true] : ['anonymised_at' => now()]);
    $result = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect($result->invoice->customer_snapshot['name'])->toBe($name);
    if ($kind === 'anonymised') {
        expect($result->invoice->customer_snapshot)->toEqual([
            'name' => 'Removed customer', 'company_name' => null, 'phone_e164' => null, 'email' => null,
            'address_line1' => null, 'address_line2' => null, 'city' => null, 'vat_number' => null,
        ]);
        expect($result->whatsAppUrl)->toBeNull();
    }
})->with([['walk-in', 'Walk-in customer'], ['anonymised', 'Removed customer']]);

it('never changes a void invoice including its status token or void details', function (string $field, mixed $value) {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    app(VoidInvoice::class)->handle($invoice, 'Original reason', $this->by);
    $invoice->refresh();

    expect(fn () => $invoice->update([$field => $value]))->toThrow(LogicException::class);
    $invoice->refresh();
    expect($invoice->status)->toBe(DocumentStatus::Void);
    expect($invoice->void_reason)->toBe('Original reason');
    expect($invoice->token_hash)->toBeNull();
})->with([
    ['status', DocumentStatus::Issued], ['token_hash', str_repeat('a', 64)], ['void_reason', 'Changed reason'],
    ['voided_at', null], ['updated_at', '2026-12-01 10:00:00'],
]);

it('casts invoice dates and payment confirmation dates to Carbon', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice->refresh();
    expect($invoice->issued_on)->toBeInstanceOf(Carbon::class);
    expect($invoice->due_on)->toBeInstanceOf(Carbon::class);
    expect($invoice->voided_at)->toBeNull();
    app(VoidInvoice::class)->handle($invoice, 'Wrong details', $this->by);
    expect($invoice->refresh()->voided_at)->toBeInstanceOf(Carbon::class);
    $payment = Payment::factory()->create(['order_id' => $this->order->id, 'confirmed_at' => now()]);
    expect($payment->refresh()->confirmed_at)->toBeInstanceOf(Carbon::class);
});

it('rejects a void invoice save whose only dirty attribute is its timestamp', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $invoice = app(VoidInvoice::class)->handle($invoice, 'Original reason', $this->by)->refresh();
    $originalTimestamp = $invoice->updated_at->toDateTimeString();
    $invoice->updated_at = now()->addDay();

    expect(array_keys($invoice->getDirty()))->toBe(['updated_at']);
    expect(fn () => $invoice->save())->toThrow(LogicException::class, 'Void invoices cannot be changed.');
    expect($invoice->refresh()->updated_at->toDateTimeString())->toBe($originalTimestamp);
});

it('allows the issued to void action to set the update timestamp', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $this->travelTo(now()->addDay());

    $voided = app(VoidInvoice::class)->handle($invoice, 'Original reason', $this->by)->refresh();

    expect($voided->status)->toBe(DocumentStatus::Void);
    expect($voided->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());
    expect($voided->voided_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('refuses direct timestamp touches on void invoices even without model events', function (string $method) {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $invoice = app(VoidInvoice::class)->handle($invoice, 'Original reason', $this->by)->refresh();
    $originalTimestamp = $invoice->updated_at->toDateTimeString();
    $this->travelTo(now()->addDay());

    expect(fn () => $invoice->{$method}())->toThrow(LogicException::class, 'Void invoices cannot be changed.');
    expect($invoice->refresh()->updated_at->toDateTimeString())->toBe($originalTimestamp);
})->with(['touch', 'touchQuietly']);

it('does not change void invoice timestamps through relationship touching', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $invoice = app(VoidInvoice::class)->handle($invoice, 'Original reason', $this->by)->refresh();
    $originalTimestamp = $invoice->updated_at->toDateTimeString();
    $this->travelTo(now()->addDay());

    $this->order->invoices()->touch();

    expect($invoice->refresh()->updated_at->toDateTimeString())->toBe($originalTimestamp);
    expect($invoice->status)->toBe(DocumentStatus::Void);
});

it('falls back to the order name and phone when no customer exists', function () {
    $this->order->update(['customer_id' => null]);
    $result = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect($result->invoice->customer_snapshot['name'])->toBe('Old name');
    expect($result->invoice->customer_snapshot['phone_e164'])->toBe('+94770000000');
});

it('rechecks issuance conditions under the lock after authorization', function (string $change) {
    Gate::before(function (User $user, string $ability) use ($change): ?bool {
        if ($ability === 'create') {
            if ($change === 'status') {
                $this->order->update(['status' => OrderStatus::Cancelled]);
            } else {
                $this->order->lines()->delete();
            }
        }

        return null;
    });
    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, $this->by))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('document_sequences', 0);
})->with(['status', 'lines']);

it('locks customer then order then sequence on issue and order then invoice on void', function () {
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        foreach (['customers', 'orders', 'document_sequences', 'invoices'] as $table) {
            if (str_contains($query->sql, 'from "'.$table.'"') && (str_contains($query->sql, 'for update') || str_contains($query->sql, 'for share'))) {
                $locks[] = $table;
            }
        }
    });
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    expect($locks)->toBe(['customers', 'orders', 'document_sequences']);
    $locks = [];
    app(VoidInvoice::class)->handle($issued->invoice, 'Wrong', $this->by);
    expect($locks)->toBe(['orders', 'invoices']);
});

it('throttles public invoice access', function () {
    $url = app(IssueInvoice::class)->handle($this->order, null, $this->by)->url;
    for ($request = 0; $request < 30; $request++) {
        $this->get($url)->assertOk();
    }
    $this->get($url)->assertTooManyRequests();
});

it('preserves a maximum length void reason and fits its timeline note', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;
    $reason = str_repeat('x', 255);
    $voided = app(VoidInvoice::class)->handle($invoice, $reason, $this->by);

    expect($voided->void_reason)->toBe($reason);
    $this->assertDatabaseHas('order_events', [
        'order_id' => $this->order->id, 'type' => 'note',
        'note' => mb_substr('Invoice '.$invoice->number.' voided: '.$reason, 0, 255),
    ]);
});

it('omits the balance due message for settled orders', function () {
    $this->order->update(['paid_cents' => $this->order->total_cents]);
    expect(app(IssueInvoice::class)->handle($this->order, null, $this->by)->whatsAppText)->not->toContain('balance due');
});

it('uses the app calendar date around midnight for issue due date and number year', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 20:00:00', 'UTC'));
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice;

    expect($invoice->number)->toBe('INV-2027-00001');
    expect($invoice->issued_on->format('Y-m-d'))->toBe('2027-01-01');
    expect($invoice->due_on->format('Y-m-d'))->toBe('2027-01-08');
});

it('refuses an invoice that became void after link or void authorization', function (string $ability) {
    $issued = app(IssueInvoice::class)->handle($this->order, null, $this->by);
    Gate::before(function (User $user, string $requested) use ($ability, $issued): ?bool {
        if ($requested === $ability) {
            $issued->invoice->update(['status' => DocumentStatus::Void, 'token_hash' => null]);
        }

        return null;
    });

    expect(function () use ($ability, $issued): void {
        if ($ability === 'view') {
            app(GetInvoiceLink::class)->handle($issued->invoice, $this->by);
        } else {
            app(VoidInvoice::class)->handle($issued->invoice, 'Wrong', $this->by);
        }
    })->toThrow(ValidationException::class, $ability === 'view' ? 'This invoice is void.' : 'This invoice is already void.');
    expect($this->order->events()->count())->toBe(1);
})->with(['view', 'void']);

it('rechecks for an active invoice after issuance authorization', function () {
    Gate::before(function (User $user, string $ability): ?bool {
        if ($ability === 'create') {
            Invoice::factory()->create(['order_id' => $this->order->id, 'issued_by_id' => $this->by->id, 'number' => 'INV-2026-99999']);
        }

        return null;
    });

    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, $this->by))
        ->toThrow(ValidationException::class, 'already has invoice INV-2026-99999');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('document_sequences', 0);
});

it('refuses a changed customer rather than taking locks in the wrong order', function () {
    $other = Customer::factory()->create();
    Gate::before(function (User $user, string $ability) use ($other): ?bool {
        if ($ability === 'create') {
            $this->order->update(['customer_id' => $other->id]);
        }

        return null;
    });

    expect(fn () => app(IssueInvoice::class)->handle($this->order, null, $this->by))
        ->toThrow(ValidationException::class, 'The order customer has changed.');
    $this->assertDatabaseCount('invoices', 0);
});

it('does not expose void invoices even if they retain a matching token', function () {
    $invoice = Invoice::factory()->create([
        'order_id' => $this->order->id, 'issued_by_id' => $this->by->id,
        'status' => DocumentStatus::Void, 'token_hash' => hash('sha256', 'secret'),
    ]);
    $this->get(route('invoices.show', ['number' => $invoice->number, 'token' => 'secret']))->assertNotFound();
});

it('does not expose invoices with no token', function () {
    $invoice = Invoice::factory()->create([
        'order_id' => $this->order->id, 'issued_by_id' => $this->by->id, 'token_hash' => null,
    ]);
    $this->get(route('invoices.show', ['number' => $invoice->number, 'token' => 'anything']))->assertNotFound();
});

it('keeps issued lines totals and VAT independent of subsequent order and settings changes', function () {
    $invoice = app(IssueInvoice::class)->handle($this->order, null, $this->by)->invoice->refresh();
    $lines = $invoice->lines;
    $this->line->update(['qty' => 5, 'description' => 'Changed', 'line_total_cents' => 30000000]);
    $this->order->update(['total_cents' => 30000000]);
    $tax = app(TaxSettings::class);
    $tax->vat_number = null;
    $tax->vat_rate_bp = null;
    $tax->save();
    $invoice->refresh();

    expect($invoice->lines)->toEqual($lines);
    expect($invoice->total_cents)->toBe(11800000);
    expect($invoice->tax_cents)->toBe(1800000);
    expect($invoice->vat_rate_bp)->toBe(1800);
    expect($invoice->seller_vat_number)->toBe('SELLER-VAT');
});
