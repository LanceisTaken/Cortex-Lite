<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\SteamAuthController;
use App\Http\Controllers\SteamSyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

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
    ->middleware('auth:sanctum')
    ->name('me');

Route::post('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth:sanctum', 'throttle:6,1'])
    ->name('verification.send');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1'])
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1'])
    ->name('password.update');

Route::delete('/account', [AccountController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('account.destroy');

Route::apiResource('games', GameController::class)
    ->middleware('auth:sanctum')
    ->except(['show']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/steam/login', [SteamAuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('steam.login');
    Route::post('/steam/connect-vanity', [SteamAuthController::class, 'connectVanity'])
        ->middleware('throttle:6,1')
        ->name('steam.connect-vanity');
    Route::post('/steam/sync', [SteamSyncController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('steam.sync');
});
