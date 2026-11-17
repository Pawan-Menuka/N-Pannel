<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property DocumentStatus $status
 * @property Carbon|null $issued_on
 * @property Carbon|null $due_on
 * @property Carbon|null $voided_at
 * @property array{name: string, company_name: ?string, phone_e164: ?string, email: ?string, address_line1: ?string, address_line2: ?string, city: ?string, vat_number: ?string} $customer_snapshot
 * @property list<array{kind: string, sku: ?string, description: string, sale_unit: ?string, qty: int, unit_price_cents: int, line_total_cents: int, sort_order: int}> $lines
 */
#[Fillable(['number', 'token_hash', 'order_id', 'customer_id', 'status', 'locale', 'customer_snapshot', 'lines', 'subtotal_cents', 'delivery_cents', 'discount_cents', 'tax_cents', 'total_cents', 'vat_rate_bp', 'seller_vat_number', 'issued_on', 'due_on', 'issued_by_id', 'voided_at', 'void_reason', 'updated_at'])]
#[Hidden(['token_hash'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Relationship touches bypass model events, so invoices must never receive them.
     *
     * @var array<class-string<Model>>
     */
    protected static $ignoreOnTouch = [self::class];

    /** @param  array<string>|string|null  $attribute */
    public function touch(mixed $attribute = null): bool
    {
        if ($this->exists && $this->getOriginal('status') === DocumentStatus::Void) {
            throw new LogicException('Void invoices cannot be changed.');
        }

        return parent::touch($attribute);
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') === DocumentStatus::Void && $invoice->getDirty() !== []) {
                throw new LogicException('Void invoices cannot be changed.');
            }
            if ($invoice->isDirty('status') && ($invoice->getOriginal('status') !== DocumentStatus::Issued || $invoice->status !== DocumentStatus::Void)) {
                throw new LogicException('Invoices can only change from issued to void.');
            }
            if (array_diff(array_keys($invoice->getDirty()), ['status', 'voided_at', 'void_reason', 'token_hash', 'updated_at']) !== []) {
                throw new LogicException('Issued invoices cannot be edited.');
            }
        });
        static::deleting(function (Invoice $invoice): void {
            throw new LogicException('Invoices cannot be deleted.');
        });
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => DocumentStatus::Issued->value,
        'locale' => 'en',
        'delivery_cents' => 0,
        'discount_cents' => 0,
        'tax_cents' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'customer_snapshot' => 'array',
            'lines' => 'array',
            'subtotal_cents' => 'integer',
            'delivery_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'issued_on' => 'date',
            'due_on' => 'date',
            'voided_at' => 'datetime',
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

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }
}
