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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AdjustStock
{
    public function __construct(private StockLedger $ledger) {}

    public function handle(Variant $variant, int $delta, string $note, User $actor): StockMovement
    {
        Gate::forUser($actor)->authorize('adjust', StockLevel::class);
        $errors = [];
        if ($delta === 0 || $delta < -1000000 || $delta > 1000000) {
            $errors['delta'] = 'Enter a non-zero quantity between -1,000,000 and 1,000,000.';
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 255) {
            $errors['note'] = 'A reason of at most 255 characters is required.';
        }
        if (! $variant->track_stock) {
            $errors['delta'] = "This item doesn't track stock.";
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        try {
            return DB::transaction(fn (): StockMovement => $this->ledger->apply($variant, Location::default(), $delta, StockReason::ManualAdjust, null, $actor, $note));
        } catch (InsufficientStock $exception) {
            throw ValidationException::withMessages(['delta' => "Only {$exception->available} in stock; you can remove at most {$exception->available}."]);
        } catch (StockLimitExceeded) {
            throw ValidationException::withMessages(['delta' => 'That would exceed the maximum stock quantity.']);
        }
    }
}
