<?php

namespace App\Models;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use Database\Factories\OrderEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property OrderEventType $type
 * @property OrderStatus|null $from_status
 * @property OrderStatus|null $to_status
 */
#[Fillable(['order_id', 'type', 'from_status', 'to_status', 'note', 'user_id'])]
class OrderEvent extends Model
{
    /** @use HasFactory<OrderEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => OrderEventType::class,
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
