<?php

namespace App\Policies;

use App\Models\GoodsReceipt;

class GoodsReceiptPolicy extends ModelPolicy
{
    protected const string Model = GoodsReceipt::class;
}
