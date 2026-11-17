<?php

use App\Http\Controllers\QuoteDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/q/{reference}/{token}', QuoteDocumentController::class)
    ->middleware('throttle:30,1')
    ->name('quotes.show');

Route::get('/', function () {
    return view('welcome');
});
