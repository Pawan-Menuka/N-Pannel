<?php

namespace App\Domain\Stock;

final class InsufficientStock extends \DomainException
{
    public function __construct(public readonly int $variantId, public readonly int $requested, public readonly int $available)
    {
        parent::__construct("Only {$available} in stock, {$requested} requested.");
    }
}
