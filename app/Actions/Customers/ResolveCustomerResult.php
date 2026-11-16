<?php

namespace App\Actions\Customers;

use App\Models\Customer;

final readonly class ResolveCustomerResult
{
    public function __construct(public Customer $customer, public bool $created) {}
}
