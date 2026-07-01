<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('guest')
    ->name('register');

Route::post('/login', [LoginController::class, 'store'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('login');

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('logout');

Route::get('/me', [UserController::class, 'show'])
    ->middleware(['auth:sanctum', 'verified'])
    ->name('me');

// Temporary stub — replaced by the real controller in Task 6.
Route::post('/email/verify/{id}/{hash}', fn () => response()->noContent())
    ->name('verification.verify');
