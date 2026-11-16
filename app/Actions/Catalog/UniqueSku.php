<?php

namespace App\Actions\Catalog;

use App\Models\Variant;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class UniqueSku implements ValidationRule
{
    public function __construct(private readonly ?Variant $record = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Variant::query()->whereRaw('upper(sku) = ?', [mb_strtoupper(trim((string) $value))]);
        if ($this->record !== null) {
            $query->whereKeyNot($this->record->getKey());
        }
        if ($query->exists()) {
            $fail('This SKU has already been taken');
        }
    }
}
