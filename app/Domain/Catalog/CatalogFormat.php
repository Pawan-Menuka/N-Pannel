<?php

namespace App\Domain\Catalog;

final class CatalogFormat
{
    public const string SlugPattern = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    public const string SkuPattern = '/\A[A-Z0-9][A-Z0-9._\/-]*\z/';

    public static function exportCell(mixed $value): mixed
    {
        return is_string($value) && (self::isFormula($value) || self::hasEscapePrefix($value)) ? "'".$value : $value;
    }

    public static function importCell(string $value): string
    {
        return self::hasEscapePrefix($value) ? substr($value, 1) : $value;
    }

    private static function hasEscapePrefix(string $value): bool
    {
        if (! str_starts_with($value, "'")) {
            return false;
        }
        $next = substr($value, 1);

        return str_starts_with($next, "'") || self::isFormula($next);
    }

    private static function isFormula(string $value): bool
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]);
    }
}
