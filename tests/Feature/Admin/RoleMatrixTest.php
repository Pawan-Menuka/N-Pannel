<?php

use App\Enums\GoodsReceiptStatus;
use App\Enums\StocktakeStatus;
use App\Enums\UserRole;
use App\Models;
use Spatie\Activitylog\Models\Activity;

dataset('role matrix', function () {
    // Each row specifies readers, creators, editors and deleters independently of the policies.
    $rows = [
        [Models\Category::class, 'ASK', 'AK', 'AK', 'AK'],
        [Models\Product::class, 'ASK', 'AK', 'AK', 'AK'],
        [Models\Variant::class, 'ASK', 'AK', 'AK', 'AK'],
        [Models\Location::class, 'ASK', 'A', 'A', 'A'],
        [Models\District::class, 'ASK', 'A', 'A', 'A'],
        [Models\StockLevel::class, 'ASK', '', '', ''],
        [Models\StockMovement::class, 'ASK', '', '', ''],
        [Models\Stocktake::class, 'AK', 'AK', 'AK', 'AK'],
        [Models\StocktakeLine::class, 'AK', 'AK', 'AK', 'AK'],
        [Models\Supplier::class, 'AK', 'AK', 'AK', 'AK'],
        [Models\GoodsReceipt::class, 'AK', 'AK', 'AK', 'AK'],
        [Models\GoodsReceiptLine::class, 'AK', 'AK', 'AK', 'AK'],
        [Models\Customer::class, 'AS', 'AS', 'AS', 'A'],
        [Models\Order::class, 'AS', 'AS', 'AS', ''],
        [Models\OrderLine::class, 'AS', 'AS', 'AS', ''],
        [Models\QuoteSnapshot::class, 'AS', '', '', ''],
        [Models\OrderEvent::class, 'AS', '', '', ''],
        [Models\Payment::class, 'AS', 'AS', '', ''],
        [Models\Invoice::class, 'AS', 'AS', '', ''],
        [Models\CreditNote::class, 'AS', 'AS', '', ''],
        [Models\SalesReturn::class, 'AS', 'AS', '', ''],
        [Models\SalesReturnLine::class, 'AS', 'AS', '', ''],
        [Models\DocumentSequence::class, 'A', '', '', ''],
        [Activity::class, 'A', '', '', ''],
        [Models\User::class, 'A', 'A', 'A', ''],
    ];

    foreach ($rows as [$model, $read, $create, $update, $delete]) {
        foreach (['A' => UserRole::Admin, 'S' => UserRole::Sales, 'K' => UserRole::Stock, 'I' => UserRole::Admin] as $code => $role) {
            yield class_basename($model).' '.$code => [$model, $role, $code, $read, $create, $update, $delete];
        }
    }
});

it('enforces every model ability for each staff role', function (string $modelClass, UserRole $role, string $code, string $read, string $create, string $update, string $delete) {
    $user = Models\User::factory()->make(['role' => $role, 'is_active' => $code !== 'I']);
    $model = new $modelClass;

    if ($model instanceof Models\GoodsReceiptLine) {
        $model->setRelation('goodsReceipt', new Models\GoodsReceipt(['status' => GoodsReceiptStatus::Draft]));
    }

    if ($model instanceof Models\StocktakeLine) {
        $model->setRelation('stocktake', new Models\Stocktake(['status' => StocktakeStatus::Draft]));
    }

    foreach (['viewAny' => $read, 'view' => $read, 'create' => $create, 'update' => $update, 'delete' => $delete, 'restore' => '', 'forceDelete' => ''] as $ability => $allowed) {
        $subject = in_array($ability, ['viewAny', 'create'], true) ? $modelClass : $model;

        expect($user->can($ability, $subject))->toBe(str_contains($allowed, $code), $ability);
    }
})->with('role matrix');

it('uses the document or parent status for draft-only abilities', function (UserRole $role, bool $active, string $status) {
    $user = Models\User::factory()->make(['role' => $role, 'is_active' => $active]);
    $receipt = new Models\GoodsReceipt(['status' => $status]);
    $receiptLine = (new Models\GoodsReceiptLine)->setRelation('goodsReceipt', $receipt);
    $stocktake = new Models\Stocktake(['status' => $status]);
    $stocktakeLine = (new Models\StocktakeLine)->setRelation('stocktake', $stocktake);
    $canWrite = $active && in_array($role, [UserRole::Admin, UserRole::Stock], true);

    foreach ([$receipt, $receiptLine, $stocktake, $stocktakeLine] as $model) {
        expect($user->can('delete', $model))->toBe($canWrite && $status === 'draft');
    }

    foreach ([$receipt, $receiptLine] as $model) {
        expect($user->can('update', $model))->toBe($canWrite && $status === 'draft');
    }

    foreach ([$stocktake, $stocktakeLine] as $model) {
        expect($user->can('update', $model))->toBe($canWrite);
    }
})->with([UserRole::Admin, UserRole::Sales, UserRole::Stock])->with([true, false])->with(['draft', 'posted']);
