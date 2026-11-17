<?php

namespace App\Models;

use App\Domain\Customers\Balance;
use App\Enums\CustomerType;
use App\Enums\PriceTier;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property CustomerType $type
 * @property PriceTier $price_tier
 */
#[Fillable(['phone_e164', 'name', 'name_edited_by_staff', 'type', 'price_tier', 'company_name', 'vat_number', 'email', 'preferred_locale', 'district_id', 'address_line1', 'address_line2', 'city', 'tags', 'notes', 'assigned_to_id', 'marketing_opt_in', 'marketing_opt_in_at', 'last_activity_at', 'anonymised_at', 'merged_into_id', 'is_walk_in'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'name_edited_by_staff' => false,
        'type' => CustomerType::Homeowner->value,
        'price_tier' => PriceTier::Retail->value,
        'preferred_locale' => 'en',
        'tags' => '[]',
        'marketing_opt_in' => false,
        'is_walk_in' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
            'name_edited_by_staff' => 'boolean',
            'type' => CustomerType::class,
            'price_tier' => PriceTier::class,
            'tags' => 'array',
            'marketing_opt_in' => 'boolean',
            'marketing_opt_in_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'is_walk_in' => 'boolean',
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

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasManyThrough<Payment, Order, $this> */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Order::class);
    }

    public function balanceCents(): int
    {
        return (int) $this->orders()->whereIn('status', Balance::statuses())
            ->selectRaw('COALESCE(SUM(GREATEST(total_cents - paid_cents, 0)), 0) AS balance')->value('balance');
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    #[Scope]
    protected function withBalance(Builder $query): Builder
    {
        return $query->addSelect(['balance_cents' => self::balanceQuery()]);
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    #[Scope]
    protected function owingMoney(Builder $query): Builder
    {
        return $query->whereHas('orders', fn (Builder $orders): Builder => $orders
            ->whereIn('status', Balance::statuses())
            ->whereColumn('total_cents', '>', 'paid_cents'));
    }

    /** @return Builder<Order> */
    public static function balanceQuery(): Builder
    {
        return Order::query()->whereColumn('orders.customer_id', 'customers.id')
            ->whereIn('status', Balance::statuses())
            ->selectRaw('COALESCE(SUM(GREATEST(total_cents - paid_cents, 0)), 0)');
    }

    /** @return BelongsTo<Customer, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'merged_into_id');
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    #[Scope]
    protected function walkIn(Builder $query): Builder
    {
        return $query->where('is_walk_in', true);
    }
}
