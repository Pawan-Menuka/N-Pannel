<?php

namespace App\Domain\Catalog;

final class ImportReport
{
    public int $rowsRead = 0;

    public int $productsToCreate = 0;

    public int $productsToUpdate = 0;

    public int $productsUnchanged = 0;

    public int $variantsToCreate = 0;

    public int $variantsToUpdate = 0;

    public int $variantsUnchanged = 0;

    public bool $committed = false;

    /** @var list<array{row: int, column: string, message: string}> */
    public array $errors = [];

    /** @var list<array{row: int, message: string}> */
    public array $warnings = [];

    public function error(int $row, string $column, string $message): void
    {
        $this->errors[] = compact('row', 'column', 'message');
    }

    public function warning(int $row, string $message): void
    {
        $this->warnings[] = compact('row', 'message');
    }

    public function isClean(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
