<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Translatable\HasTranslations;

#[Fillable(['slug', 'category_id', 'brand', 'title', 'summary', 'description', 'is_active', 'sort_order'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasTranslations;
    use LogsActivity {
        resolveAttributeValue as protected resolveActivityAttributeValue;
    }

    /** @var list<string> */
    public array $translatable = ['title', 'summary', 'description'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
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

    /** @return array<string, mixed> */
    protected static function resolveAttributeValue(Model $model, string $attribute, LogOptions $options): array
    {
        if ($model instanceof self && $model->isTranslatableAttribute($attribute)) {
            return [$attribute => $model->getTranslations($attribute)];
        }

        return static::resolveActivityAttributeValue($model, $attribute, $options);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<Variant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }
}
