<?php

use App\Actions\Catalog\PruneCatalogImports;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog:prune-imports', function (PruneCatalogImports $prune): void {
    $this->info('Deleted '.$prune->handle().' expired catalog import files.');
})->purpose('Delete catalog import files older than one day');

Schedule::command('catalog:prune-imports')->daily();
