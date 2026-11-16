<?php

namespace App\Actions\Catalog;

use Illuminate\Support\Facades\Storage;

final class PruneCatalogImports
{
    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->getTimestamp();
        $deleted = 0;
        foreach ($disk->files('catalog-imports', recursive: true) as $file) {
            if ($disk->lastModified($file) < $cutoff && $disk->delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
