<?php

namespace App\Domain\Reports;

enum AgeBucket
{
    case Days0To30;
    case Days31To60;
    case Days61To90;
    case Days90Plus;

    public static function forDays(int $days): self
    {
        return match (true) {
            $days <= 30 => self::Days0To30,
            $days <= 60 => self::Days31To60,
            $days <= 90 => self::Days61To90,
            default => self::Days90Plus,
        };
    }

    public function column(): string
    {
        return match ($this) {
            self::Days0To30 => 'age_0_30_cents',
            self::Days31To60 => 'age_31_60_cents',
            self::Days61To90 => 'age_61_90_cents',
            self::Days90Plus => 'age_90_plus_cents',
        };
    }

    public function minimumDays(): int
    {
        return match ($this) {
            self::Days0To30 => 0,
            self::Days31To60 => 31,
            self::Days61To90 => 61,
            self::Days90Plus => 91,
        };
    }

    public function maximumDays(): ?int
    {
        return match ($this) {
            self::Days0To30 => 30,
            self::Days31To60 => 60,
            self::Days61To90 => 90,
            self::Days90Plus => null,
        };
    }
}
