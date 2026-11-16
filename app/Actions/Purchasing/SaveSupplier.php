<?php

namespace App\Actions\Purchasing;

use App\Domain\Contact\InvalidPhoneNumber;
use App\Domain\Contact\PhoneNumber;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveSupplier
{
    /** @param array{name: string, phone_e164?: ?string, email?: ?string, address?: ?string, notes?: ?string, is_active?: bool} $data */
    public function handle(?Supplier $supplier, array $data, User $actor): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $actor): Supplier {
            $supplier = $supplier === null ? new Supplier : Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            Gate::forUser($actor)->authorize($supplier->exists ? 'update' : 'create', $supplier->exists ? $supplier : Supplier::class);
            $data['name'] = trim($data['name']);
            foreach (['phone_e164', 'email', 'address', 'notes'] as $field) {
                if (array_key_exists($field, $data) || ! $supplier->exists) {
                    $data[$field] = trim($data[$field] ?? '');
                    $data[$field] = $data[$field] === '' ? null : $data[$field];
                }
            }
            Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'address' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
            ])->validate();
            try {
                if (array_key_exists('phone_e164', $data) && $data['phone_e164'] !== null) {
                    $data['phone_e164'] = PhoneNumber::toE164($data['phone_e164']);
                }
            } catch (InvalidPhoneNumber $exception) {
                throw ValidationException::withMessages(['phone_e164' => $exception->getMessage()]);
            }
            $supplier->fill($data)->save();

            return $supplier->refresh();
        });
    }
}
