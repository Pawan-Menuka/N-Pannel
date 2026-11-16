<?php

namespace App\Models;

use Database\Factories\GoodsReceiptLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['goods_receipt_id', 'variant_id', 'qty', 'unit_cost_cents', 'previous_last_cost_cents', 'line_total_cents', 'sort_order'])]
class GoodsReceiptLine extends Model
{
    /** @use HasFactory<GoodsReceiptLineFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_cost_cents' => 'integer',
            'previous_last_cost_cents' => 'integer',
            'line_total_cents' => 'integer',
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

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
