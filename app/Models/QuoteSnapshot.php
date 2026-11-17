<?php

namespace App\Models;

use Database\Factories\QuoteSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $valid_until */
#[Fillable(['order_id', 'revision', 'lines', 'total_cents', 'required_advance_cents', 'exclusions', 'valid_until', 'created_by_id'])]
class QuoteSnapshot extends Model
{
    /** @use HasFactory<QuoteSnapshotFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'lines' => 'array',
            'total_cents' => 'integer',
            'required_advance_cents' => 'integer',
            'valid_until' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
