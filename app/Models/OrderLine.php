<?php

namespace App\Models;

use App\Enums\OrderLineKind;
use App\Enums\SaleUnit;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrderLineKind $kind
 * @property SaleUnit|null $sale_unit
 */
#[Fillable(['order_id', 'kind', 'variant_id', 'sku', 'description', 'sale_unit', 'qty', 'unit_price_cents', 'line_total_cents', 'unit_cost_cents', 'sort_order'])]
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => OrderLineKind::Product->value,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => OrderLineKind::class,
            'sale_unit' => SaleUnit::class,
            'qty' => 'integer',
            'unit_price_cents' => 'integer',
            'line_total_cents' => 'integer',
            'unit_cost_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** @return HasMany<SalesReturnLine, $this> */
    public function returnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }
}
