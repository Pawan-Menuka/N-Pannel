<?php

use App\Actions\Purchasing\CreateGoodsReceipt;
use App\Actions\Purchasing\DeleteGoodsReceipt;
use App\Actions\Purchasing\DeleteGoodsReceiptLine;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\SaveGoodsReceiptLine;
use App\Actions\Purchasing\UpdateGoodsReceiptDetails;
use App\Actions\Purchasing\VoidGoodsReceipt;
use App\Actions\Stock\AdjustStock;
use App\Actions\Stock\StockLedger;
use App\Enums\GoodsReceiptStatus;
use App\Enums\StockReason;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->location = Location::factory()->create(['is_default' => true]);
    $this->actor = User::factory()->stock()->create();
    $this->supplier = Supplier::factory()->create();
    $this->receipt = app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, now('Asia/Colombo')->toDateString());
});

it('creates a draft at Main and normalizes its details', function () {
    expect($this->receipt->status)->toBe(GoodsReceiptStatus::Draft);
    expect($this->receipt->number)->toBeNull();
    expect($this->receipt->location_id)->toBe($this->location->id);
    expect($this->receipt->created_by_id)->toBe($this->actor->id);
    $updated = app(UpdateGoodsReceiptDetails::class)->handle($this->receipt, $this->actor, $this->supplier->id, '2026-01-01', ' ref ', ' note ');
    expect($updated->supplier_reference)->toBe('ref');
    expect($updated->note)->toBe('note');
    expect($updated->received_on->toDateString())->toBe('2026-01-01');
});

it('refuses future or invalid receipt dates', function (string $date) {
    expect(fn () => app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, $date))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateGoodsReceiptDetails::class)->handle($this->receipt, $this->actor, $this->supplier->id, $date))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('goods_receipts', 1);
})->with(['2999-01-01', 'not a date', '2026-02-30']);

it('requires active suppliers on creation and update', function () {
    $this->supplier->update(['is_active' => false]);
    expect(fn () => app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, '2026-01-01'))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateGoodsReceiptDetails::class)->handle($this->receipt, $this->actor, $this->supplier->id, '2026-01-01'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('goods_receipts', 1);
});

it('recalculates totals while appending editing and deleting lines', function () {
    $variant = Variant::factory()->create();
    $first = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 3, 12345, $this->actor);
    $second = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, Variant::factory()->create()->id, 2, 100, $this->actor);
    expect($first->line_total_cents)->toBe(37035);
    expect($second->sort_order)->toBeGreaterThan($first->sort_order);
    expect($this->receipt->fresh()->total_cost_cents)->toBe(37235);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, $first, $variant->id, 4, 100, $this->actor);
    expect($this->receipt->fresh()->total_cost_cents)->toBe(600);
    app(DeleteGoodsReceiptLine::class)->handle($second, $this->actor);
    expect($this->receipt->fresh()->total_cost_cents)->toBe(400);
    app(DeleteGoodsReceiptLine::class)->handle($first, $this->actor);
    expect($this->receipt->fresh()->total_cost_cents)->toBe(0);
});

it('enforces line quantity cost and tracked active variant rules', function (int $qty, int $cost, bool $tracked, bool $active, string $field) {
    $variant = Variant::factory()->create(['track_stock' => $tracked, 'is_active' => $active]);
    try {
        app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, $qty, $cost, $this->actor);
        $this->fail('Expected validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('goods_receipt_lines', 0);
    expect($this->receipt->fresh()->total_cost_cents)->toBe(0);
})->with([
    [0, 1, true, true, 'qty'], [-1, 1, true, true, 'qty'], [1000001, 1, true, true, 'qty'],
    [1, -1, true, true, 'unit_cost_cents'], [1, 10000000001, true, true, 'unit_cost_cents'],
    [1, 1, false, true, 'variant_id'], [1, 1, true, false, 'variant_id'],
]);

it('accepts the exact quantity and cost boundaries including zero cost', function (int $qty, int $cost, int $total) {
    $line = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, Variant::factory()->create()->id, $qty, $cost, $this->actor);
    expect($line->line_total_cents)->toBe($total);
})->with([[1, 0, 0], [1000000, 10000000000, 10000000000000000]]);

it('refuses missing variants and lines belonging to another receipt', function () {
    expect(fn () => app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, 999999999, 1, 100, $this->actor))->toThrow(ValidationException::class);
    $foreign = GoodsReceiptLine::factory()->create();
    expect(fn () => app(SaveGoodsReceiptLine::class)->handle($this->receipt, $foreign, $foreign->variant_id, 1, 100, $this->actor))
        ->toThrow(ModelNotFoundException::class);
    expect($foreign->fresh()->qty)->toBe(10);
});

it('refuses a duplicate variant with a useful SKU message', function () {
    $variant = Variant::factory()->create(['sku' => 'GYP-9-2400']);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 50, 100, $this->actor);
    expect(fn () => app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor))
        ->toThrow(ValidationException::class, 'SKU GYP-9-2400 is already on this receipt — edit that line');
    $this->assertDatabaseCount('goods_receipt_lines', 1);
});

it('posts lines in variant order with numbered audited stock movements and last costs', function () {
    $this->travelTo(now()->setDate(2026, 11, 17));
    $first = Variant::factory()->create();
    $second = Variant::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $second->id, 2, 34567, $this->actor);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $first->id, 50, 12345, $this->actor);
    StockLevel::factory()->create(['variant_id' => $first->id, 'location_id' => $this->location->id, 'qty' => 3]);

    $receipt = app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);

    expect($receipt->number)->toBe('GRN-2026-00001');
    expect($receipt->status)->toBe(GoodsReceiptStatus::Posted);
    expect($receipt->posted_by_id)->toBe($this->actor->id);
    expect($receipt->posted_at)->not->toBeNull();
    expect($receipt->total_cost_cents)->toBe(686384);
    expect($first->fresh()->last_cost_cents)->toBe(12345);
    expect($second->fresh()->last_cost_cents)->toBe(34567);
    $movements = StockMovement::query()->orderBy('id')->get();
    expect($movements->pluck('variant_id')->all())->toBe([$first->id, $second->id]);
    expect($movements->pluck('qty_after')->all())->toBe([53, 2]);
    expect($movements->pluck('delta')->all())->toBe([50, 2]);
    foreach ($movements as $movement) {
        expect($movement->reason)->toBe(StockReason::GoodsReceived);
        expect($movement->source->is($receipt))->toBeTrue();
        expect($movement->user_id)->toBe($this->actor->id);
    }
    expect(Activity::query()->where('subject_type', $receipt->getMorphClass())->where('subject_id', $receipt->id)->where('event', 'updated')->exists())->toBeTrue();
});

it('refuses an empty receipt and a repeated post with validation rather than authorization errors', function () {
    expect(fn () => app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor))->toThrow(ValidationException::class, 'Add at least one line before posting.');
    $this->assertDatabaseCount('document_sequences', 0);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, Variant::factory()->create()->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    expect(fn () => app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor))->toThrow(ValidationException::class, 'Only draft goods received notes can be changed.');
    $this->assertDatabaseCount('stock_movements', 1);
});

it('rechecks tracked and active flags when posting', function (string $flag) {
    $variant = Variant::factory()->create(['sku' => 'CHANGED-SKU']);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor);
    $variant->update([$flag => false]);
    expect(fn () => app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor))->toThrow(ValidationException::class, 'CHANGED-SKU');
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseCount('document_sequences', 0);
    expect($this->receipt->fresh()->status)->toBe(GoodsReceiptStatus::Draft);
})->with(['track_stock', 'is_active']);

it('rolls back every stock cost and number change when a later line exceeds the stock limit', function () {
    $first = Variant::factory()->create(['last_cost_cents' => 77]);
    $second = Variant::factory()->create(['last_cost_cents' => 88]);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $first->id, 1, 100, $this->actor);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $second->id, 1, 200, $this->actor);
    StockLevel::factory()->create(['variant_id' => $second->id, 'location_id' => $this->location->id, 'qty' => StockLedger::MaxQty]);

    expect(fn () => app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseCount('stock_levels', 1);
    $this->assertDatabaseCount('document_sequences', 0);
    expect($first->fresh()->last_cost_cents)->toBe(77);
    expect($second->fresh()->last_cost_cents)->toBe(88);
    expect($this->receipt->fresh()->number)->toBeNull();
});

it('voids a posted receipt with reversal movements and retains its number', function () {
    $variant = Variant::factory()->create(['last_cost_cents' => null]);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 50, 100, $this->actor);
    $posted = app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);

    $voided = app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, ' Incorrect delivery ');

    expect($voided->status)->toBe(GoodsReceiptStatus::Void);
    expect($voided->number)->toBe($posted->number);
    expect($voided->voided_by_id)->toBe($this->actor->id);
    expect($voided->voided_at)->not->toBeNull();
    expect($voided->void_reason)->toBe('Incorrect delivery');
    expect($variant->fresh()->last_cost_cents)->toBeNull();
    $movement = StockMovement::query()->where('reason', StockReason::GoodsReceivedVoid)->sole();
    expect($movement->delta)->toBe(-50);
    expect($movement->qty_after)->toBe(0);
    expect($movement->source->is($voided))->toBeTrue();
    expect($movement->note)->toBe('Incorrect delivery');
    expect($movement->is_override)->toBeFalse();
    $next = app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, '2026-01-01');
    app(SaveGoodsReceiptLine::class)->handle($next, null, $variant->id, 1, 200, $this->actor);
    $next = app(PostGoodsReceipt::class)->handle($next, $this->actor);
    expect($next->number)->toEndWith('-00002');
});

it('reports every shortage and changes nothing when any received stock is gone', function () {
    $first = Variant::factory()->create(['sku' => 'GYP-9-2400']);
    $second = Variant::factory()->create(['sku' => 'TEE-3000']);
    $third = Variant::factory()->create();
    foreach ([$first, $second, $third] as $variant) {
        app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 50, 100, $this->actor);
    }
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    app(AdjustStock::class)->handle($first, -30, 'Sold', $this->actor);
    app(AdjustStock::class)->handle($second, -45, 'Sold', $this->actor);
    $activityCount = Activity::count();

    try {
        app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'Mistake');
        $this->fail('Expected shortage validation.');
    } catch (ValidationException $exception) {
        expect($exception->getMessage())->toContain('GYP-9-2400: received 50, in stock 20', 'TEE-3000: received 50, in stock 5');
    }
    expect($this->receipt->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
    expect(StockMovement::count())->toBe(5);
    expect(Activity::count())->toBe($activityCount);
    foreach ([$first, $second, $third] as $variant) {
        expect($variant->fresh()->last_cost_cents)->toBe(100);
    }
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $third->id, 'qty' => 50]);
});

it('permits only admins to override shortages and logs the reason on every reversal', function () {
    $variant = Variant::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 50, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    app(AdjustStock::class)->handle($variant, -30, 'Sold', $this->actor);
    expect(fn () => app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'Override', true))->toThrow(AuthorizationException::class);
    $admin = User::factory()->admin()->create();

    app(VoidGoodsReceipt::class)->handle($this->receipt, $admin, '  Approved correction  ', true);

    $movement = StockMovement::query()->where('reason', StockReason::GoodsReceivedVoid)->sole();
    expect($movement->qty_after)->toBe(-30);
    expect($movement->is_override)->toBeTrue();
    expect($movement->note)->toBe('Approved correction');
    expect($movement->user_id)->toBe($admin->id);
});

it('requires a bounded nonblank void reason', function (string $reason) {
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, Variant::factory()->create()->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    expect(fn () => app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, $reason))->toThrow(ValidationException::class);
    expect($this->receipt->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
    $this->assertDatabaseCount('stock_movements', 1);
})->with(['', '   ', str_repeat('x', 256)]);

it('restores costs by posting order when draft ids differ and timestamps tie', function () {
    $this->travelTo(now()->startOfSecond());
    $variant = Variant::factory()->create(['last_cost_cents' => null]);
    $receipts = [];
    foreach ([100, 200, 300, 400] as $cost) {
        $receipt = app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, '2026-01-01');
        app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 1, $cost, $this->actor);
        $receipts[] = $receipt;
    }
    foreach ([1, 0, 3, 2] as $index) {
        app(PostGoodsReceipt::class)->handle($receipts[$index], $this->actor);
    }
    expect($receipts[0]->fresh()->posted_at->toDateTimeString())->toBe($receipts[3]->fresh()->posted_at->toDateTimeString());

    app(VoidGoodsReceipt::class)->handle($receipts[2], $this->actor, 'Last posted was wrong');
    expect($variant->fresh()->last_cost_cents)->toBe(400);
    app(VoidGoodsReceipt::class)->handle($receipts[3], $this->actor, 'Previous delivery was wrong');
    expect($variant->fresh()->last_cost_cents)->toBe(100);
    app(VoidGoodsReceipt::class)->handle($receipts[0], $this->actor, 'Earlier delivery was wrong');
    expect($variant->fresh()->last_cost_cents)->toBe(200);
    app(VoidGoodsReceipt::class)->handle($receipts[1], $this->actor, 'First posted was wrong');
    expect($variant->fresh()->last_cost_cents)->toBeNull();
});

it('refuses changes and deletion of posted and void receipts through every action and policy', function (bool $void) {
    $variant = Variant::factory()->create();
    $line = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    if ($void) {
        app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'Mistake');
    }
    expect(fn () => app(UpdateGoodsReceiptDetails::class)->handle($this->receipt, $this->actor, $this->supplier->id, '2026-01-01'))->toThrow(ValidationException::class);
    expect(fn () => app(SaveGoodsReceiptLine::class)->handle($this->receipt, $line, $variant->id, 2, 100, $this->actor))->toThrow(ValidationException::class);
    expect(fn () => app(DeleteGoodsReceiptLine::class)->handle($line, $this->actor))->toThrow(ValidationException::class);
    expect(fn () => app(DeleteGoodsReceipt::class)->handle($this->receipt, $this->actor))->toThrow(ValidationException::class);
    expect($this->actor->can('update', $this->receipt->fresh()))->toBeFalse();
    expect($this->actor->can('delete', $this->receipt->fresh()))->toBeFalse();
    expect($this->actor->can('update', $line->fresh()))->toBeFalse();
    expect($this->actor->can('delete', $line->fresh()))->toBeFalse();
})->with([false, true]);

it('refuses voiding drafts and repeated voids before checking the gate', function () {
    expect(fn () => app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'Mistake'))->toThrow(ValidationException::class, 'Only posted goods received notes can be voided.');
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, Variant::factory()->create()->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'Mistake');
    expect(fn () => app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'Again'))->toThrow(ValidationException::class, 'Only posted goods received notes can be voided.');
});

it('deletes a draft and its lines without stock or numbering side effects', function () {
    $line = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, Variant::factory()->create()->id, 1, 100, $this->actor);
    expect(app(DeleteGoodsReceipt::class)->handle($this->receipt, $this->actor))->toBeTrue();
    $this->assertModelMissing($this->receipt);
    $this->assertModelMissing($line);
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseCount('document_sequences', 0);
});

it('denies sales staff direct receipt actions', function () {
    $sales = User::factory()->sales()->create();
    $variant = Variant::factory()->create();
    $line = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor);
    expect(fn () => app(CreateGoodsReceipt::class)->handle($sales, $this->supplier->id, '2026-01-01'))->toThrow(AuthorizationException::class);
    expect(fn () => app(UpdateGoodsReceiptDetails::class)->handle($this->receipt, $sales, $this->supplier->id, '2026-01-01'))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveGoodsReceiptLine::class)->handle($this->receipt, $line, $variant->id, 2, 100, $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeleteGoodsReceiptLine::class)->handle($line, $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeleteGoodsReceipt::class)->handle($this->receipt, $sales))->toThrow(AuthorizationException::class);
    expect(fn () => app(PostGoodsReceipt::class)->handle($this->receipt, $sales))->toThrow(AuthorizationException::class);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    expect(fn () => app(VoidGoodsReceipt::class)->handle($this->receipt, $sales, 'Mistake'))->toThrow(AuthorizationException::class);
});

it('uses posting movements instead of edited timestamps when restoring a cost', function () {
    $variant = Variant::factory()->create();
    $receipts = [];
    foreach ([100, 200, 300] as $cost) {
        $receipt = app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, '2026-01-01');
        app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 1, $cost, $this->actor);
        $receipts[] = app(PostGoodsReceipt::class)->handle($receipt, $this->actor);
    }
    $receipts[0]->update(['posted_at' => '2026-11-17 11:00:00']);
    $receipts[1]->update(['posted_at' => '2026-11-17 10:00:00']);

    app(VoidGoodsReceipt::class)->handle($receipts[2], $this->actor, 'Correction');

    expect($variant->fresh()->last_cost_cents)->toBe(200);
});

it('rolls back an admin void if a reversal would underflow stock', function () {
    $variant = Variant::factory()->create();
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    $admin = User::factory()->admin()->create();
    DB::transaction(fn () => app(StockLedger::class)->apply($variant, $this->location, StockLedger::MinQty, StockReason::ManualAdjust, null, $admin, 'Approved shortage', true));
    DB::transaction(fn () => app(StockLedger::class)->apply($variant, $this->location, -1, StockReason::ManualAdjust, null, $admin, 'Approved shortage', true));
    $movements = StockMovement::count();

    expect(fn () => app(VoidGoodsReceipt::class)->handle($this->receipt, $admin, 'Correction', true))->toThrow(ValidationException::class, 'Voiding would exceed the supported stock quantity range.');

    expect($this->receipt->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
    expect($variant->fresh()->last_cost_cents)->toBe(100);
    expect(StockMovement::count())->toBe($movements);
    $this->assertDatabaseHas('stock_levels', ['variant_id' => $variant->id, 'qty' => StockLedger::MinQty]);
});

it('preserves literal zero references and notes', function () {
    $receipt = app(UpdateGoodsReceiptDetails::class)->handle($this->receipt, $this->actor, $this->supplier->id, '2026-01-01', ' 0 ', ' 0 ');
    expect($receipt->supplier_reference)->toBe('0');
    expect($receipt->note)->toBe('0');
});

it('snapshots and restores a cost from outside goods receipts including null and zero', function (?int $priorCost) {
    $variant = Variant::factory()->create(['last_cost_cents' => $priorCost]);
    $line = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 240000, $this->actor);
    expect($line->previous_last_cost_cents)->toBeNull();

    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);

    expect($line->fresh()->previous_last_cost_cents)->toBe($priorCost);
    expect($variant->fresh()->last_cost_cents)->toBe(240000);

    app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'Incorrect delivery');

    expect($variant->fresh()->last_cost_cents)->toBe($priorCost);
})->with([250000, null, 0]);

it('restores the previous receipt cost then the original cost when voiding newest first', function () {
    $variant = Variant::factory()->create(['last_cost_cents' => 2500]);
    $firstLine = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    $second = app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, '2026-01-01');
    $secondLine = app(SaveGoodsReceiptLine::class)->handle($second, null, $variant->id, 1, 200, $this->actor);
    app(PostGoodsReceipt::class)->handle($second, $this->actor);
    expect($firstLine->fresh()->previous_last_cost_cents)->toBe(2500);
    expect($secondLine->fresh()->previous_last_cost_cents)->toBe(100);

    app(VoidGoodsReceipt::class)->handle($second, $this->actor, 'Second delivery was wrong');

    expect($variant->fresh()->last_cost_cents)->toBe(100);

    app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'First delivery was wrong');

    expect($variant->fresh()->last_cost_cents)->toBe(2500);
});

it('keeps the latest posted receipt cost when voiding an older receipt', function () {
    $variant = Variant::factory()->create(['last_cost_cents' => 2500]);
    app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor);
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    $second = app(CreateGoodsReceipt::class)->handle($this->actor, $this->supplier->id, '2026-01-01');
    app(SaveGoodsReceiptLine::class)->handle($second, null, $variant->id, 1, 200, $this->actor);
    app(PostGoodsReceipt::class)->handle($second, $this->actor);

    app(VoidGoodsReceipt::class)->handle($this->receipt, $this->actor, 'First delivery was wrong');

    expect($variant->fresh()->last_cost_cents)->toBe(200);
    expect($second->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
});

it('logs the addition of a draft receipt line even when its cost is zero', function () {
    $line = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, Variant::factory()->create()->id, 1, 0, $this->actor);

    expect(Activity::query()->where('subject_type', $line->getMorphClass())->where('subject_id', $line->id)->where('event', 'created')->exists())->toBeTrue();
});

it('refuses to post when the supplier has been deactivated since drafting', function () {
    $variant = Variant::factory()->create(['last_cost_cents' => 2500]);
    $line = app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 1, 100, $this->actor);
    $this->supplier->update(['is_active' => false]);

    try {
        app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
        $this->fail('Expected inactive supplier validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('receipt');
        expect($exception->errors()['receipt'])->toBe(["Supplier {$this->supplier->name} is inactive. Reactivate it or change the supplier before posting."]);
    }

    expect($this->receipt->fresh()->status)->toBe(GoodsReceiptStatus::Draft);
    expect($this->receipt->fresh()->number)->toBeNull();
    expect($line->fresh()->previous_last_cost_cents)->toBeNull();
    expect($variant->fresh()->last_cost_cents)->toBe(2500);
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseCount('document_sequences', 0);
});

it('marks only short lines as overrides when an admin requests a void override', function (bool $short) {
    $first = Variant::factory()->create();
    $second = Variant::factory()->create();
    foreach ([$first, $second] as $variant) {
        app(SaveGoodsReceiptLine::class)->handle($this->receipt, null, $variant->id, 2, 100, $this->actor);
    }
    app(PostGoodsReceipt::class)->handle($this->receipt, $this->actor);
    if ($short) {
        app(AdjustStock::class)->handle($first, -2, 'Sold', $this->actor);
    }
    $admin = User::factory()->admin()->create();

    app(VoidGoodsReceipt::class)->handle($this->receipt, $admin, 'Approved correction', true);

    $movements = StockMovement::query()->where('reason', StockReason::GoodsReceivedVoid)->orderBy('variant_id')->get();
    expect($movements->pluck('is_override')->all())->toBe([$short, false]);
    expect($movements->pluck('note')->all())->toBe(['Approved correction', 'Approved correction']);
    expect($this->receipt->fresh()->void_reason)->toBe('Approved correction');
})->with([false, true]);
