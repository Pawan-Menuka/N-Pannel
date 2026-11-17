<?php

namespace App\Models;

use Database\Factories\SalesReturnLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['sales_return_id', 'order_line_id', 'variant_id', 'qty', 'restock', 'unit_price_cents', 'line_total_cents'])]
class SalesReturnLine extends Model
{
    /** @use HasFactory<SalesReturnLineFactory> */
    use HasFactory;

    /** @var array<class-string<Model>> */
    protected static $ignoreOnTouch = [self::class];

    /** @param array<string>|string|null $attribute */
    public function touch(mixed $attribute = null): bool
    {
        if ($this->exists) {
            throw new LogicException('Return lines cannot be changed.');
        }

        return parent::touch($attribute);
    }

    protected static function booted(): void
    {
        static::updating(function (SalesReturnLine $record): void {
            throw new LogicException('Return lines cannot be changed.');
        });
        static::deleting(function (SalesReturnLine $record): void {
            throw new LogicException('Return lines cannot be deleted.');
        });
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'restock' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'restock' => 'boolean',
            'unit_price_cents' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<SalesReturn, $this> */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    /** @return BelongsTo<OrderLine, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
