<?php

use App\Http\Controllers\API\OAuthController;
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
        Route::post('/oauth/authorize', [OAuthController::class, 'authorizeClient'])->middleware('throttle:5,1');
    });
});

Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::get('/me', [UserController::class, 'me']);
    Route::post('/logout', [UserController::class, 'logout']);
    Route::put('/account/change-password', [UserController::class, 'updatePassword']);
    Route::put('/account/change-name', [UserController::class, 'updateName']);
    Route::match(['put', 'post'], '/account/profile', [UserController::class, 'updateProfile']);
});
