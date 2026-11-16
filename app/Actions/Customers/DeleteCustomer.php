<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteCustomer
{
    public function handle(Customer $customer, User $actor): bool
    {
        return DB::transaction(function () use ($customer, $actor): bool {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($locked->is_walk_in) {
                throw ValidationException::withMessages(['customer' => 'The walk-in customer cannot be deleted.']);
            }
            Gate::forUser($actor)->authorize('delete', $locked);
            if ($locked->orders()->exists() || $locked->invoices()->exists()) {
                throw ValidationException::withMessages(['customer' => 'This customer has orders; it cannot be deleted.']);
            }
            if (Customer::query()->where('merged_into_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['customer' => 'Other customer records are merged into this customer; it cannot be deleted.']);
            }

            return (bool) $locked->delete();
        });
    }
}
