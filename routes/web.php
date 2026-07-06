<?php

use App\Http\Controllers\SteamAuthController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/steam/callback', [SteamAuthController::class, 'callback'])
    ->middleware('auth')
    ->name('steam.callback');

Route::get('/stripe/payment/{id}', [PaymentController::class, 'show'])
    ->name('cashier.payment');
