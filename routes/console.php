<?php

use App\Actions\Catalog\PruneCatalogImports;
use App\Actions\Orders\SweepExpired;
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

Artisan::command('store:sweep', function (SweepExpired $sweep): void {
    $this->info('Expired '.$sweep->handle()->expiredQuotes.' quotes.');
})->purpose('Expire quotes past their validity date');

Schedule::command('store:sweep')->hourly();
