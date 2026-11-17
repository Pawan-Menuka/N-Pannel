<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reference collisions are retried in savepoints inside the caller's transaction. */
final class InsertOrderWithReference
{
    public function __construct(private OrderReferenceGenerator $references) {}

    /** @param array<string, mixed> $attributes */
    public function handle(string $prefix, array $attributes): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = $this->references->next($prefix);
            try {
                return DB::transaction(fn (): Order => Order::query()->create([...$attributes, 'reference' => $reference]));
            } catch (UniqueConstraintViolationException $exception) {
                if (! Order::query()->where('reference', $reference)->exists()) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages(['lines' => 'Could not allocate a unique order reference. Please try again.']);
    }
}
