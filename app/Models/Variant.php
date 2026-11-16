<?php

namespace App\Models;

use App\Enums\SaleUnit;
use App\Enums\StockStatus;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property SaleUnit $sale_unit
 * @property StockStatus $stock_status
 */
#[Fillable(['product_id', 'sku', 'label', 'colour', 'length_mm', 'width_mm', 'thickness_micron', 'weight_grams', 'sale_unit', 'pieces_per_unit', 'price_cents', 'trade_price_cents', 'last_cost_cents', 'track_stock', 'reorder_level', 'stock_status', 'is_active', 'sort_order'])]
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'pieces_per_unit' => 1,
        'track_stock' => true,
        'stock_status' => StockStatus::InStock->value,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'thickness_micron' => 'integer',
            'weight_grams' => 'integer',
            'sale_unit' => SaleUnit::class,
            'pieces_per_unit' => 'integer',
            'price_cents' => 'integer',
            'trade_price_cents' => 'integer',
            'last_cost_cents' => 'integer',
            'track_stock' => 'boolean',
            'reorder_level' => 'integer',
            'stock_status' => StockStatus::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /** @return HasMany<OrderLine, $this> */
    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function displayName(): string
    {
        return $this->product->getTranslation('title', 'en').' — '.($this->label ?: $this->sku);
    }
}
