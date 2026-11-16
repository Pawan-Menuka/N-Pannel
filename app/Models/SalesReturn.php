<?php

namespace App\Models;

use Database\Factories\SalesReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['order_id', 'reason', 'note', 'total_cents', 'refund_cents', 'created_by_id'])]
class SalesReturn extends Model
{
    /** @use HasFactory<SalesReturnFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'total_cents' => 0,
        'refund_cents' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
            'refund_cents' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['id', 'created_at', 'updated_at', 'token_hash'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<SalesReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasOne<CreditNote, $this> */
    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
