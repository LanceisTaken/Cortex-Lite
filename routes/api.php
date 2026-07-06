<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HardwareController;
use App\Http\Controllers\PlaySessionController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReverseController;
use App\Http\Controllers\SteamAuthController;
use App\Http\Controllers\SteamSyncController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

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
    Route::get('/usage', [UsageController::class, 'show'])
        ->name('usage.show');

    Route::post('/checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('checkout');

    Route::get('/hardware/gpus', [HardwareController::class, 'gpus'])
        ->name('hardware.gpus.search');
    Route::get('/hardware/cpus', [HardwareController::class, 'cpus'])
        ->name('hardware.cpus.search');

    Route::post('/recommend', [RecommendationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('recommend');

    Route::post('/reverse', [ReverseController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('reverse');

    Route::post('/sessions/start', [PlaySessionController::class, 'start'])
        ->middleware('throttle:30,1')
        ->name('sessions.start');
    Route::get('/sessions/active', [PlaySessionController::class, 'active'])
        ->name('sessions.active');
    Route::get('/sessions', [PlaySessionController::class, 'index'])
        ->name('sessions.index');
    Route::post('/sessions/{session}/end', [PlaySessionController::class, 'end'])
        ->middleware('throttle:30,1')
        ->name('sessions.end');

    Route::get('/steam/login', [SteamAuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('steam.login');
    Route::post('/steam/connect-id', [SteamAuthController::class, 'connectSteamId'])
        ->middleware('throttle:6,1')
        ->name('steam.connect-id');
    Route::post('/steam/sync', [SteamSyncController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('steam.sync');
});
