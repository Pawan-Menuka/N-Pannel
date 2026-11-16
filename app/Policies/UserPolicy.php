<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy extends ModelPolicy
{
    protected const string Model = User::class;
}
