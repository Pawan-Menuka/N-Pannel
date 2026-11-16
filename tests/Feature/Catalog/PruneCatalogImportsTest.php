<?php

use Illuminate\Support\Facades\Storage;

it('prunes only catalog imports older than one day', function () {
    Storage::fake('local');
    $this->freezeTime();
    $disk = Storage::disk('local');
    $disk->put('catalog-imports/expired.csv', 'old');
    $disk->put('catalog-imports/nested/expired.csv', 'old');
    $disk->put('catalog-imports/current.csv', 'new');
    $disk->put('catalog-imports/boundary.csv', 'boundary');
    $disk->put('elsewhere/old.csv', 'keep');
    foreach (['catalog-imports/expired.csv', 'catalog-imports/nested/expired.csv', 'elsewhere/old.csv'] as $file) {
        touch($disk->path($file), now()->subDays(2)->getTimestamp());
    }
    touch($disk->path('catalog-imports/boundary.csv'), now()->subDay()->getTimestamp());

    $this->artisan('catalog:prune-imports')->expectsOutput('Deleted 2 expired catalog import files.')->assertSuccessful();

    $disk->assertMissing(['catalog-imports/expired.csv', 'catalog-imports/nested/expired.csv']);
    $disk->assertExists(['catalog-imports/current.csv', 'catalog-imports/boundary.csv', 'elsewhere/old.csv']);
});
