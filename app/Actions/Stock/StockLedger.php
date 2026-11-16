<?php

namespace App\Actions\Stock;

use App\Domain\Stock\InsufficientStock;
use App\Domain\Stock\StockLimitExceeded;
use App\Enums\StockReason;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Multi-row stock operations must lock stock_levels in ascending variant_id order.
 */
final class StockLedger
{
    public const int MaxQty = 2147483647;

    public const int MinQty = -2147483648;

    public function apply(Variant $variant, Location $location, int $delta, StockReason $reason, ?Model $source, ?User $user, ?string $note = null, bool $override = false, bool $guardNegative = true): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock changes require a database transaction.');
        }
        if ($delta === 0) {
            throw new InvalidArgumentException('Stock change must not be zero.');
        }
        if ($delta < self::MinQty || $delta > self::MaxQty) {
            throw new StockLimitExceeded('That stock change exceeds the supported quantity range.');
        }
        $note = trim($note ?? '');
        $note = $note === '' ? null : $note;
        if ($override && $note === null) {
            throw new InvalidArgumentException('An override reason is required.');
        }

        StockLevel::query()->insertOrIgnore([
            'variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $query = StockLevel::query()->where('variant_id', $variant->id)->where('location_id', $location->id);
        if ($delta > 0) {
            if ((clone $query)->where('qty', '<=', self::MaxQty - $delta)->increment('qty', $delta) === 0) {
                throw new StockLimitExceeded('That would exceed the maximum stock quantity.');
            }
        } else {
            $decrement = clone $query;
            if ($guardNegative && ! $override) {
                $decrement->where('qty', '>=', abs($delta));
            } else {
                $decrement->where('qty', '>=', self::MinQty + abs($delta));
            }
            if ($decrement->decrement('qty', abs($delta)) === 0) {
                if (! $guardNegative || $override) {
                    throw new StockLimitExceeded('That would exceed the minimum stock quantity.');
                }
                throw new InsufficientStock($variant->id, abs($delta), (int) (clone $query)->value('qty'));
            }
        }
        $level = $query->lockForUpdate()->firstOrFail();
        $movement = new StockMovement([
            'variant_id' => $variant->id, 'location_id' => $location->id,
            'delta' => $delta, 'qty_after' => $level->qty, 'reason' => $reason,
            'user_id' => $user?->id, 'note' => $note, 'is_override' => $override,
        ]);
        $movement->source()->associate($source);
        $movement->save();

        return $movement;
    }
}
