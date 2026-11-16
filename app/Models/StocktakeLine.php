<?php

namespace App\Models;

use Database\Factories\StocktakeLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stocktake_id', 'variant_id', 'expected_qty', 'counted_qty'])]
class StocktakeLine extends Model
{
    /** @use HasFactory<StocktakeLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_qty' => 'integer',
            'counted_qty' => 'integer',
        ];
    }

    /** @return BelongsTo<Stocktake, $this> */
    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
