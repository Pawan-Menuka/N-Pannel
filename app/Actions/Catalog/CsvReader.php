<?php

namespace App\Actions\Catalog;

use Generator;
use RuntimeException;

final class CsvReader
{
    /** @return Generator<int, list<string|null>> */
    public function records(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read the uploaded file');
        }
        try {
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $line = 1;
            while (true) {
                $start = ftell($handle);
                $values = fgetcsv($handle, 0, ',', '"', '');
                if ($values === false) {
                    break;
                }
                $end = ftell($handle);
                if ($start === false || $end === false) {
                    throw new RuntimeException('Cannot read the uploaded file');
                }
                $recordLine = $line;
                fseek($handle, $start);
                $previousCr = false;
                $remaining = $end - $start;
                while ($remaining > 0) {
                    $chunk = fread($handle, min(8192, $remaining));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('Cannot read the uploaded file');
                    }
                    $remaining -= strlen($chunk);
                    $line += preg_match_all('/\r\n|\r|\n/', $chunk) ?: 0;
                    if ($previousCr && str_starts_with($chunk, "\n")) {
                        $line--;
                    }
                    $previousCr = str_ends_with($chunk, "\r");
                }
                yield $recordLine => $values;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string|null> $values */
    public function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value ?? '') !== '') {
                return false;
            }
        }

        return true;
    }
}
