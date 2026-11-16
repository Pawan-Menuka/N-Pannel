<?php

namespace App\Actions\Customers;

use App\Domain\Contact\InvalidPhoneNumber;
use App\Domain\Contact\PhoneNumber;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

final class SearchCustomers
{
    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function handle(Builder $query, string $search): Builder
    {
        $search = trim($search);
        try {
            $phone = PhoneNumber::toE164($search);
        } catch (InvalidPhoneNumber) {
            $phone = null;
        }
        $digits = preg_replace('/\D/', '', $search) ?? '';
        $national = null;
        if (preg_match('/^[\d\s+().-]+$/D', $search) === 1 && strlen($digits) >= 3) {
            $national = preg_replace('/^(?:0094|94)(?=\d)|^0/', '', $digits, 1) ?? $digits;
        }
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        return $query->where(function (Builder $query) use ($pattern, $national, $phone): void {
            $query->where('name', 'ilike', $pattern)->orWhere('company_name', 'ilike', $pattern);
            if ($national !== null && $national !== '') {
                if ($phone !== null) {
                    $query->orWhere('phone_e164', $phone);
                }
                $query->orWhere('phone_e164', 'like', '%'.$national.'%');
            }
        });
    }
}
