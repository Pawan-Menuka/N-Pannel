<?php

namespace App\Actions\Returns;

final readonly class ReturnLineInput
{
    public function __construct(public int $orderLineId, public int $qty, public int $unitValueCents, public bool $restock) {}
}
