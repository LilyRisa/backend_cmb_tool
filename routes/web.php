<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\API\OAuthController;
use App\Http\Controllers\API\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/email/verify/{token}', [UserController::class, 'verifyEmail'])->middleware('throttle:10,1,email-verify');

Route::get('/password/reset/{token}', [UserController::class, 'showResetForm']);
Route::post('/password/reset', [UserController::class, 'resetPassword']);

Route::get('/oauth/callback', [OAuthController::class, 'callback']);

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminController::class, 'login'])->name('login.submit')->middleware('throttle:5,1,admin-login');

    Route::middleware('admin')->group(function () {
        Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    });
});
