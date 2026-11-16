<?php

namespace App\Actions\Purchasing;

use App\Models\Supplier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReceiptDetails
{
    /** @return array{supplier_id: int, received_on: string, supplier_reference: ?string, note: ?string} */
    public function validate(int $supplierId, string $receivedOn, ?string $supplierReference, ?string $note): array
    {
        $supplierReference = trim($supplierReference ?? '');
        $note = trim($note ?? '');
        $data = [
            'supplier_id' => $supplierId, 'received_on' => $receivedOn,
            'supplier_reference' => $supplierReference === '' ? null : $supplierReference,
            'note' => $note === '' ? null : $note,
        ];
        Validator::make($data, [
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Colombo')->toDateString()],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $supplier = Supplier::query()->lockForUpdate()->find($supplierId);
        if ($supplier === null || ! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
        }

        return $data;
    }
}
