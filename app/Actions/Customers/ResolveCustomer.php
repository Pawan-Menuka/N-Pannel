<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ResolveCustomer
{
    /** @param array<string, mixed> $attributes */
    public function handle(?string $phone, string $name, User $actor, array $attributes = []): ResolveCustomerResult
    {
        $identity = CustomerData::validate(['phone_e164' => $phone, 'name' => $name]);

        return DB::transaction(function () use ($identity, $attributes, $actor): ResolveCustomerResult {
            $phone = $identity['phone_e164'] ?? null;
            $name = $identity['name'] ?? '';
            $customer = $phone === null ? null : Customer::query()->where('phone_e164', $phone)->lockForUpdate()->first();
            if ($customer === null) {
                Gate::forUser($actor)->authorize('create', Customer::class);
                $data = CustomerData::validate(array_intersect_key($attributes, array_flip([
                    'type', 'price_tier', 'company_name', 'vat_number', 'email', 'district_id',
                    'address_line1', 'address_line2', 'city', 'notes',
                ])));
                $data['name'] = $name;
                $data['last_activity_at'] = now();
                $customer = $phone === null
                    ? Customer::query()->create($data)
                    : Customer::query()->createOrFirst(['phone_e164' => $phone], $data);
                if ($customer->wasRecentlyCreated) {
                    return new ResolveCustomerResult($customer, true);
                }
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            }

            $visited = [];
            $chain = [$customer];
            while ($customer->merged_into_id !== null) {
                if (isset($visited[$customer->id])) {
                    throw ValidationException::withMessages(['phone_e164' => 'This customer has a circular merge. Contact an administrator.']);
                }
                $visited[$customer->id] = true;
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->merged_into_id);
                $chain[] = $customer;
            }
            Gate::forUser($actor)->authorize('view', $customer);
            if ($customer->anonymised_at !== null) {
                Gate::forUser($actor)->authorize('create', Customer::class);
                foreach ($chain as $record) {
                    if ($phone !== null && $record->phone_e164 === $phone) {
                        $record->phone_e164 = null;
                        $record->save();
                    }
                }

                return $this->handle($phone, $name, $actor, $attributes);
            }
            if (mb_strtolower(trim($customer->name)) !== mb_strtolower($name)) {
                $alias = 'Also gave the name "'.$name.'"';
                $line = $alias.' on '.now('Asia/Colombo')->toDateString().'.';
                $notes = ($customer->notes === null || $customer->notes === '' ? '' : $customer->notes."\n").$line;
                if (! str_contains(mb_strtolower($customer->notes ?? ''), mb_strtolower($alias)) && mb_strlen($notes) <= 2000) {
                    $customer->notes = $notes;
                }
            }
            $customer->last_activity_at = now();
            $customer->save();

            return new ResolveCustomerResult($customer, false);
        });
    }

    public function walkIn(): Customer
    {
        return Customer::query()->firstOrCreate(['is_walk_in' => true], ['name' => 'Walk-in customer', 'last_activity_at' => now()]);
    }
}
