<?php

use App\Http\Controllers\API\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/user/login', [UserController::class, 'login']);
Route::post('/user/register', [UserController::class, 'register']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/register', [UserController::class, 'register'])->middleware('throttle:3,60');
    Route::post('/forgot-password', [UserController::class, 'forgotPassword'])->middleware('throttle:3,10,forgot-password');

    Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
        Route::post('/resend-verification', [UserController::class, 'resendVerification'])->middleware('throttle:3,10');
    });
});

Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::get('/me', [UserController::class, 'me']);
    Route::post('/logout', [UserController::class, 'logout']);
});
