<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/search', [SearchController::class, 'index'])
    ->middleware('throttle:120,1');

Route::post('/search/selections', [SearchController::class, 'select'])
    ->middleware('throttle:60,1');
