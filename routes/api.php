<?php

use App\Http\Controllers\API\AIController;
use App\Http\Controllers\API\CreditTopupController;
use App\Http\Controllers\API\OAuthController;
use App\Http\Controllers\API\SrtGenerateController;
use App\Http\Controllers\API\SrtTranslateController;
use App\Http\Controllers\API\ToolCreditController;
use App\Http\Controllers\API\ToolFeatureCreditController;
use App\Http\Controllers\API\ToolSubscriptionController;
use App\Http\Controllers\API\ToolTtsController;
use App\Http\Controllers\API\ToolVoiceController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\VideoDubController;
use Illuminate\Support\Facades\Route;

Route::post('/user/login', [UserController::class, 'login'])->middleware('throttle:10,1,login');
Route::post('/user/register', [UserController::class, 'register'])->middleware('throttle:3,60');

Route::prefix('auth')->group(function () {
    Route::post('/login', [UserController::class, 'login'])->middleware('throttle:10,1,login');
    Route::post('/register', [UserController::class, 'register'])->middleware('throttle:3,60');
    Route::post('/forgot-password', [UserController::class, 'forgotPassword'])->middleware('throttle:3,10,forgot-password');

    Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
        Route::post('/resend-verification', [UserController::class, 'resendVerification'])->middleware('throttle:3,10,resend-verification');
        Route::post('/oauth/authorize', [OAuthController::class, 'authorizeClient'])->middleware('throttle:5,1,oauth-authorize');
    });
});

Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::get('/me', [UserController::class, 'me']);
    Route::post('/logout', [UserController::class, 'logout']);
    Route::put('/account/change-password', [UserController::class, 'updatePassword']);
    Route::put('/account/change-name', [UserController::class, 'updateName']);
    Route::match(['put', 'post'], '/account/profile', [UserController::class, 'updateProfile']);
});

Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::post('/transcribe', [AIController::class, 'transcribe'])->middleware(['throttle:10,1,transcribe', 'email.verified']);
    Route::post('/translate', [AIController::class, 'translate'])->middleware('throttle:10,1,translate');
});

Route::prefix('tool')->middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::get('/credits', [ToolCreditController::class, 'balance']);
    Route::get('/credits/transactions', [ToolCreditController::class, 'transactions']);
    Route::get('/credits/referral', [ToolCreditController::class, 'referralInfo']);

    Route::get('/credits/packages', [CreditTopupController::class, 'packages']);
    Route::post('/credits/topup', [CreditTopupController::class, 'createTopup']);
    Route::get('/credits/topup/status/{id}', [CreditTopupController::class, 'topupStatus'])->where('id', '[0-9]+');

    Route::get('/subscription', [ToolSubscriptionController::class, 'current']);
    Route::post('/subscription/subscribe', [ToolSubscriptionController::class, 'subscribe']);
    Route::get('/subscription/status/{id}', [ToolSubscriptionController::class, 'status'])->where('id', '[0-9]+');

    Route::post('/credits/deduct-feature', [ToolFeatureCreditController::class, 'deductFeature']);
    Route::post('/credits/confirm-feature/{id}', [ToolFeatureCreditController::class, 'confirmFeature'])->where('id', '[0-9]+');
    Route::get('/credits/feature-pricing', [ToolFeatureCreditController::class, 'featurePricing']);

    Route::post('/tts/srt/{voice_id}', [ToolTtsController::class, 'generateFromSrt'])->middleware(['throttle:5,1,tts-srt', 'email.verified']);
    Route::get('/tts/status/{id}', [ToolTtsController::class, 'status'])->where('id', '[0-9]+');
    Route::get('/tts/history', [ToolTtsController::class, 'history']);
    Route::delete('/tts/history/{id}', [ToolTtsController::class, 'deleteHistory'])->where('id', '[0-9]+');
    // [^/]+ (not .+) is deliberate: a bare .+ matches slashes too, so it would
    // greedily swallow multi-segment sibling paths like tts/status/{id} or
    // tts/history/{id} whenever their own {id} constraint fails to match
    // (e.g. a non-numeric id) — Laravel then reports 405 Method Not Allowed
    // for those paths under other verbs instead of a clean 404, since this
    // route's URI pattern still "matches" for POST.
    Route::post('/tts/{voice_id}', [ToolTtsController::class, 'generate'])->where('voice_id', '^(?!srt$)[^/]+')->middleware('email.verified');

    Route::get('/models', [ToolVoiceController::class, 'models']);
    Route::get('/voice-system-clone', [ToolVoiceController::class, 'system_clone']);
    Route::get('/voices/system', [ToolVoiceController::class, 'systemVoices']);
    Route::get('/voices/cloned', [ToolVoiceController::class, 'clonedVoices']);
    Route::post('/voices/clone', [ToolVoiceController::class, 'clone'])->middleware(['throttle:5,1,voice-clone', 'email.verified']);
    Route::delete('/voices/{id}', [ToolVoiceController::class, 'delete']);

    Route::post('/generate-srt', [SrtGenerateController::class, 'generate'])->middleware(['throttle:3,1,generate-srt', 'email.verified']);
    Route::get('/generate-srt/status/{id}', [SrtGenerateController::class, 'status'])->where('id', '[0-9]+');

    Route::post('/translate-srt', [SrtTranslateController::class, 'translate'])->middleware(['throttle:3,1,translate-srt', 'email.verified']);
    Route::get('/translate-srt/status/{id}', [SrtTranslateController::class, 'status'])->where('id', '[0-9]+');

    Route::post('/video-dub', [VideoDubController::class, 'dub'])->middleware(['throttle:3,1,video-dub', 'email.verified']);
    Route::get('/video-dub/status/{id}', [VideoDubController::class, 'status'])->where('id', '[0-9]+');
});
