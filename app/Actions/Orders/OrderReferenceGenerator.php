<?php

namespace App\Actions\Orders;

use App\Domain\Order\OrderReference;

class OrderReferenceGenerator
{
    public function next(string $prefix): string
    {
        return OrderReference::generate($prefix, random_int(...));
    }
}
