<?php

namespace App\Policies;

use App\Models\GoodsReceiptLine;

class GoodsReceiptLinePolicy extends ModelPolicy
{
    protected const string Model = GoodsReceiptLine::class;
}
