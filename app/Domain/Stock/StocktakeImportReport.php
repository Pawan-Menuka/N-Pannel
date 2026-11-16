<?php

namespace App\Domain\Stock;

final class StocktakeImportReport
{
    public int $rowsRead = 0;

    public int $rowsOk = 0;

    public bool $committed = false;

    /** @var array<int, list<string>> */
    public array $errors = [];

    public function error(int $line, string $message): void
    {
        $this->errors[$line][] = $message;
    }

    public function isClean(): bool
    {
        return $this->errors === [];
    }
}
