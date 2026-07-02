<?php

use App\Http\Controllers\SteamAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/steam/callback', [SteamAuthController::class, 'callback'])
    ->middleware('auth')
    ->name('steam.callback');
