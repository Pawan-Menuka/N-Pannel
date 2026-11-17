<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\RoleMatrix;

class OrderPolicy extends ModelPolicy
{
    protected const string Model = Order::class;

    public function send(User $user): bool
    {
        return RoleMatrix::allows($user, Order::class, 'send');
    }

    public function accept(User $user): bool
    {
        return RoleMatrix::allows($user, Order::class, 'accept');
    }

    public function overrideStock(User $user): bool
    {
        return RoleMatrix::allows($user, Order::class, 'overrideStock');
    }
}
