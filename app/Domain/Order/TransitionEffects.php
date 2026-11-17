<?php

namespace App\Domain\Order;

final readonly class TransitionEffects
{
    /** @param list<string>|null $allowedCancelReasons */
    public function __construct(
        public bool $reduceStock = false,
        public bool $reserveStock = false,
        public bool $releaseStock = false,
        public bool $flagNeedsAttention = false,
        public bool $requiresSettledBalance = false,
        public bool $requiresCancelReason = false,
        public ?array $allowedCancelReasons = null,
        public bool $requiresNothingPaid = false,
    ) {}
}
