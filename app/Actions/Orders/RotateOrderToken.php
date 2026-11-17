<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use LogicException;

/** The caller must hold the order row lock inside a transaction. */
final class RotateOrderToken
{
    public function handle(Order $order): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Rotating an order token requires a database transaction and order lock.');
        }
        $plain = bin2hex(random_bytes(32));
        $order->update(['token_hash' => hash('sha256', $plain)]);

        return $plain;
    }
}
