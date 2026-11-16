<?php

namespace App\Models;

use App\Enums\StockReason;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['variant_id', 'location_id', 'delta', 'qty_after', 'reason', 'source_type', 'source_id', 'note', 'is_override', 'user_id'])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_override' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'qty_after' => 'integer',
            'reason' => StockReason::class,
            'is_override' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
