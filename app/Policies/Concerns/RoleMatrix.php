<?php

namespace App\Policies\Concerns;

use App\Enums\GoodsReceiptStatus;
use App\Enums\StocktakeStatus;
use App\Models;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class RoleMatrix
{
    private const array Catalog = [
        'viewAny' => ['admin', 'sales', 'stock'], 'view' => ['admin', 'sales', 'stock'],
        'create' => ['admin', 'stock'], 'update' => ['admin', 'stock'], 'delete' => ['admin', 'stock'],
    ];

    private const array Reference = [
        'viewAny' => ['admin', 'sales', 'stock'], 'view' => ['admin', 'sales', 'stock'],
        'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin'],
    ];

    private const array StockReadOnly = [
        'viewAny' => ['admin', 'sales', 'stock'], 'view' => ['admin', 'sales', 'stock'],
    ];

    private const array Stock = [
        'viewAny' => ['admin', 'stock'], 'view' => ['admin', 'stock'],
        'create' => ['admin', 'stock'], 'update' => ['admin', 'stock'], 'delete' => ['admin', 'stock'],
    ];

    private const array Sales = [
        'viewAny' => ['admin', 'sales'], 'view' => ['admin', 'sales'],
        'create' => ['admin', 'sales'], 'update' => ['admin', 'sales'],
    ];

    private const array SalesReadOnly = [
        'viewAny' => ['admin', 'sales'], 'view' => ['admin', 'sales'],
    ];

    private const array Financial = [
        'viewAny' => ['admin', 'sales'], 'view' => ['admin', 'sales'], 'create' => ['admin', 'sales'],
    ];

    private const array AdminReadOnly = ['viewAny' => ['admin'], 'view' => ['admin']];

    /** @var array<class-string<Model>, array<string, array<string>>> */
    private const array Permissions = [
        Models\Category::class => self::Catalog,
        Models\Product::class => self::Catalog,
        Models\Variant::class => self::Catalog,
        Models\Location::class => self::Reference,
        Models\District::class => self::Reference,
        Models\StockLevel::class => self::StockReadOnly + ['adjust' => ['admin', 'stock']],
        Models\StockMovement::class => self::StockReadOnly,
        Models\Stocktake::class => self::Stock + ['draftOnly' => ['update', 'delete']],
        Models\StocktakeLine::class => self::Stock + ['draftOnly' => ['update', 'delete']],
        Models\Supplier::class => self::Stock,
        Models\GoodsReceipt::class => self::Stock + ['post' => ['admin', 'stock'], 'void' => ['admin', 'stock'], 'voidOverride' => ['admin'], 'draftOnly' => ['update', 'delete', 'post']],
        Models\GoodsReceiptLine::class => self::Stock + ['draftOnly' => ['update', 'delete']],
        Models\Customer::class => self::Sales + ['delete' => ['admin']],
        Models\Order::class => self::Sales,
        Models\OrderLine::class => self::Sales,
        Models\QuoteSnapshot::class => self::SalesReadOnly,
        Models\OrderEvent::class => self::SalesReadOnly,
        Models\Payment::class => self::Financial,
        Models\Invoice::class => self::Financial,
        Models\CreditNote::class => self::Financial,
        Models\SalesReturn::class => self::Financial,
        Models\SalesReturnLine::class => self::Financial,
        Models\DocumentSequence::class => self::AdminReadOnly,
        Activity::class => self::AdminReadOnly,
        User::class => self::AdminReadOnly + ['create' => ['admin'], 'update' => ['admin']],
    ];

    /** @param class-string<Model> $modelClass */
    public static function allows(User $user, string $modelClass, string $ability, ?Model $record = null): bool
    {
        if (! $user->is_active || in_array($ability, ['restore', 'forceDelete'], true)) {
            return false;
        }

        $permissions = self::Permissions[$modelClass] ?? [];

        if (! in_array($user->role->value, $permissions[$ability] ?? [], true)) {
            return false;
        }

        if (! in_array($ability, $permissions['draftOnly'] ?? [], true)) {
            return true;
        }

        return match (true) {
            $record instanceof Models\GoodsReceipt => $record->status === GoodsReceiptStatus::Draft,
            $record instanceof Models\GoodsReceiptLine => $record->goodsReceipt?->status === GoodsReceiptStatus::Draft,
            $record instanceof Models\Stocktake => $record->status === StocktakeStatus::Draft,
            $record instanceof Models\StocktakeLine => $record->stocktake?->status === StocktakeStatus::Draft,
            default => false,
        };
    }
}
