<?php

namespace App\Filament\Support;

use App\Domain\Money\InvalidAmount;
use App\Domain\Money\Lkr;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

final class OrderLineForm
{
    /** Numeric HTML input without Filament's NumberStateCast, which converts decimals to floats. */
    public static function rupeeInput(string $name): TextInput
    {
        return TextInput::make($name)->prefix('Rs')->type('number')->inputMode('decimal')->step('0.01')->rules(['numeric', 'decimal:0,2']);
    }

    public static function rupees(int $cents): string
    {
        return str_replace(',', '', Lkr::format($cents));
    }

    public static function parseMoney(string $value, string $field): int
    {
        try {
            return Lkr::parse($value);
        } catch (InvalidAmount $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
