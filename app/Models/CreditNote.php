<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\CreditNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property DocumentStatus $status
 * @property Carbon|null $issued_on
 * @property Carbon|null $voided_at
 * @property array{name: string, company_name: ?string, phone_e164: ?string, email: ?string, address_line1: ?string, address_line2: ?string, city: ?string, vat_number: ?string} $customer_snapshot
 * @property list<array{sku: ?string, description: string, sale_unit: ?string, qty: int, unit_price_cents: int, line_total_cents: int}> $lines
 */
#[Fillable(['number', 'order_id', 'invoice_id', 'sales_return_id', 'customer_id', 'status', 'customer_snapshot', 'lines', 'total_cents', 'tax_cents', 'vat_rate_bp', 'seller_vat_number', 'issued_on', 'issued_by_id', 'voided_at', 'void_reason'])]
class CreditNote extends Model
{
    /** @use HasFactory<CreditNoteFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var array<class-string<Model>> */
    protected static $ignoreOnTouch = [self::class];

    /** @param array<string>|string|null $attribute */
    public function touch(mixed $attribute = null): bool
    {
        if ($this->exists) {
            throw new LogicException('Credit notes cannot be changed.');
        }

        return parent::touch($attribute);
    }

    protected static function booted(): void
    {
        static::updating(function (CreditNote $record): void {
            throw new LogicException('Credit notes cannot be changed.');
        });
        static::deleting(function (CreditNote $record): void {
            throw new LogicException('Credit notes cannot be deleted.');
        });
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => DocumentStatus::Issued->value,
        'tax_cents' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'customer_snapshot' => 'array',
            'lines' => 'array',
            'total_cents' => 'integer',
            'tax_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'issued_on' => 'date',
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

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<SalesReturn, $this> */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
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
}
