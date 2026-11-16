<?php

namespace App\Filament\Support;

use App\Domain\Money\InvalidAmount;
use App\Domain\Money\Lkr;
use Closure;
use Filament\Forms\Components\TextInput;

final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)->prefix('Rs')->inputMode('decimal')
            ->formatStateUsing(fn (mixed $state): ?string => $state === null || $state === '' ? null : Lkr::format((int) $state))
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        Lkr::parse((string) $value);
                    } catch (InvalidAmount) {
                        $fail('Enter an amount like 1,250.00');
                    }
                },
            ])
            ->dehydrateStateUsing(fn (mixed $state): ?int => $state === null || $state === '' ? null : Lkr::parse((string) $state));
    }
}
