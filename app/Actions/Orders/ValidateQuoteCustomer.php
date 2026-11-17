<?php

namespace App\Actions\Orders;

use App\Models\Customer;
use Illuminate\Validation\ValidationException;

final class ValidateQuoteCustomer
{
    public function handle(?Customer $customer): Customer
    {
        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'This customer no longer exists.']);
        }
        if ($customer->is_walk_in) {
            throw ValidationException::withMessages(['customer_id' => 'A quote needs a named customer. Pick a customer instead of walk-in.']);
        }
        if ($customer->merged_into_id !== null) {
            throw ValidationException::withMessages(['customer_id' => 'This customer has been merged. Pick the surviving customer.']);
        }
        if ($customer->anonymised_at !== null) {
            throw ValidationException::withMessages(['customer_id' => 'This customer has been anonymised. Pick another customer.']);
        }

        return $customer;
    }
}
