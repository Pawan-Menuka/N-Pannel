<?php

namespace App\Actions\Customers;

use App\Domain\Contact\InvalidPhoneNumber;
use App\Domain\Contact\PhoneNumber;
use App\Enums\CustomerType;
use App\Enums\PriceTier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{name?: string, phone_e164?: ?string, type?: CustomerType|string, price_tier?: PriceTier|string, company_name?: ?string, vat_number?: ?string, email?: ?string, district_id?: int|string|null, address_line1?: ?string, address_line2?: ?string, city?: ?string, notes?: ?string}
     */
    public static function validate(array $data): array
    {
        $data = array_intersect_key($data, array_flip([
            'name', 'phone_e164', 'type', 'price_tier', 'company_name', 'vat_number', 'email',
            'district_id', 'address_line1', 'address_line2', 'city', 'notes',
        ]));
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
                if ($key !== 'name' && $data[$key] === '') {
                    $data[$key] = null;
                }
            }
        }
        $data = Validator::make($data, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone_e164' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'required', Rule::enum(CustomerType::class)],
            'price_tier' => ['sometimes', 'required', Rule::enum(PriceTier::class)],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'district_id' => ['sometimes', 'nullable', 'integer', 'exists:districts,id'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        if (isset($data['phone_e164'])) {
            try {
                $data['phone_e164'] = PhoneNumber::toE164($data['phone_e164']);
            } catch (InvalidPhoneNumber $exception) {
                throw ValidationException::withMessages(['phone_e164' => $exception->getMessage()]);
            }
        }

        return $data;
    }
}
