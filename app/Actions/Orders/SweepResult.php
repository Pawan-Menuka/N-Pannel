<?php

namespace App\Actions\Orders;

final readonly class SweepResult
{
    public function __construct(public int $expiredQuotes) {}
}
