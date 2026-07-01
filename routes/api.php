<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Temporary stub — replaced by the real controller in Task 6.
Route::post('/email/verify/{id}/{hash}', fn () => response()->noContent())
    ->name('verification.verify');
