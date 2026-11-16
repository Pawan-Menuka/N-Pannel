<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateCustomer
{
    /** @param array<string, mixed> $data */
    public function handle(Customer $customer, array $data, User $actor): Customer
    {
        $phone = null;
        try {
            return DB::transaction(function () use ($customer, $data, $actor, &$phone): Customer {
                $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                if ($locked->is_walk_in || $locked->merged_into_id !== null || $locked->anonymised_at !== null) {
                    throw ValidationException::withMessages(['customer' => 'Walk-in, merged and anonymised customers cannot be edited.']);
                }
                Gate::forUser($actor)->authorize('update', $locked);
                $data = CustomerData::validate($data);
                $phone = $data['phone_e164'] ?? null;
                if ($phone !== null) {
                    $other = Customer::query()->where('phone_e164', $phone)->whereKeyNot($locked->id)->first();
                    if ($other !== null) {
                        throw $this->duplicatePhone($other);
                    }
                }
                if (array_key_exists('name', $data) && $data['name'] !== $locked->name) {
                    $data['name_edited_by_staff'] = true;
                }
                $locked->fill($data)->save();

                return $locked->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            $other = $phone === null ? null : Customer::query()->where('phone_e164', $phone)->whereKeyNot($customer->id)->first();
            if ($other === null) {
                throw $exception;
            }
            throw $this->duplicatePhone($other);
        }
    }

    private function duplicatePhone(Customer $customer): ValidationException
    {
        $visited = [];
        while ($customer->merged_into_id !== null) {
            if (isset($visited[$customer->id])) {
                return ValidationException::withMessages(['phone_e164' => 'This number belongs to a customer with a circular merge. Contact an administrator.']);
            }
            $visited[$customer->id] = true;
            $customer = Customer::query()->findOrFail($customer->merged_into_id);
        }
        $name = $customer->anonymised_at === null ? $customer->name : 'Removed customer';

        return ValidationException::withMessages(['phone_e164' => 'That number already belongs to '.$name.'.']);
    }
}
