<?php

namespace App\Models;

use App\Enums\StocktakeStatus;
use Database\Factories\StocktakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property StocktakeStatus $status
 */
#[Fillable(['location_id', 'status', 'note', 'created_by_id', 'posted_by_id', 'posted_at'])]
class Stocktake extends Model
{
    /** @use HasFactory<StocktakeFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => StocktakeStatus::Draft->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StocktakeStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<StocktakeLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }
}
