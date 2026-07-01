<?php

use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('guest')
    ->name('register');

// Temporary stub — replaced by the real controller in Task 6.
Route::post('/email/verify/{id}/{hash}', fn () => response()->noContent())
    ->name('verification.verify');
