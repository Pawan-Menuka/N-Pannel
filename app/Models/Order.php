<?php

namespace App\Models;

use App\Domain\Order\PaymentStates;
use App\Enums\AcceptedVia;
use App\Enums\DocumentStatus;
use App\Enums\FulfilmentMethod;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PriceTier;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property OrderStatus $status
 * @property PaymentState $payment_state
 * @property OrderSource $source
 * @property PriceTier $price_tier
 * @property FulfilmentMethod|null $fulfilment
 * @property AcceptedVia|null $accepted_via
 * @property Carbon|null $valid_until
 */
#[Fillable(['reference', 'token_hash', 'customer_id', 'source', 'locale', 'status', 'payment_state', 'fulfilment', 'location_id', 'price_tier', 'district_id', 'customer_name', 'phone_e164', 'email', 'address_line1', 'address_line2', 'city', 'delivery_notes', 'subtotal_cents', 'delivery_cents', 'discount_cents', 'total_cents', 'tax_cents', 'paid_cents', 'refunded_cents', 'required_advance_cents', 'exclusions', 'valid_until', 'hold_expires_at', 'quote_revision', 'accepted_at', 'accepted_via', 'needs_attention', 'attention_note', 'cancel_reason', 'created_by_id', 'placed_at', 'confirmed_at', 'completed_at', 'cancelled_at'])]
#[Hidden(['token_hash'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'source' => OrderSource::Quote->value,
        'locale' => 'en',
        'payment_state' => PaymentState::Unpaid->value,
        'price_tier' => PriceTier::Retail->value,
        'subtotal_cents' => 0,
        'delivery_cents' => 0,
        'discount_cents' => 0,
        'total_cents' => 0,
        'tax_cents' => 0,
        'paid_cents' => 0,
        'refunded_cents' => 0,
        'quote_revision' => 0,
        'needs_attention' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'payment_state' => PaymentState::class,
            'fulfilment' => FulfilmentMethod::class,
            'price_tier' => PriceTier::class,
            'subtotal_cents' => 'integer',
            'delivery_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'tax_cents' => 'integer',
            'paid_cents' => 'integer',
            'refunded_cents' => 'integer',
            'required_advance_cents' => 'integer',
            'valid_until' => 'datetime',
            'hold_expires_at' => 'datetime',
            'quote_revision' => 'integer',
            'accepted_at' => 'datetime',
            'accepted_via' => AcceptedVia::class,
            'needs_attention' => 'boolean',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<OrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /** @return HasMany<QuoteSnapshot, $this> */
    public function quoteSnapshots(): HasMany
    {
        return $this->hasMany(QuoteSnapshot::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<SalesReturn, $this> */
    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function balanceCents(): int
    {
        return PaymentStates::balance($this->total_cents, $this->paid_cents);
    }

    public function activeInvoice(): ?Invoice
    {
        return $this->invoices()->where('status', DocumentStatus::Issued)->first();
    }
}
