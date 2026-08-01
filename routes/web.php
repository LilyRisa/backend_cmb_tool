<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BlogManagementController;
use App\Http\Controllers\Admin\ToolManagementController;
use App\Http\Controllers\Admin\ToolSettingsController;
use App\Http\Controllers\Admin\VideoDubManagementController;
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

        Route::get('/videodub', [VideoDubManagementController::class, 'index'])->name('videodub.index');
        Route::get('/videodub/{id}', [VideoDubManagementController::class, 'show'])->name('videodub.show');

        Route::get('/tool-settings', [ToolSettingsController::class, 'index'])->name('tool-settings.index');
        Route::post('/tool-settings', [ToolSettingsController::class, 'update'])->name('tool-settings.update');

        Route::get('/tools', [ToolManagementController::class, 'index'])->name('tools.index');
        Route::get('/tools/create', [ToolManagementController::class, 'create'])->name('tools.create');
        Route::post('/tools', [ToolManagementController::class, 'store'])->name('tools.store');
        Route::get('/tools/{id}/edit', [ToolManagementController::class, 'edit'])->name('tools.edit');
        Route::put('/tools/{id}', [ToolManagementController::class, 'update'])->name('tools.update');
        Route::delete('/tools/{id}', [ToolManagementController::class, 'destroy'])->name('tools.destroy');

        Route::prefix('blog')->name('blog.')->group(function () {
            Route::get('/categories', [BlogManagementController::class, 'categoriesIndex'])->name('categories.index');
            Route::get('/categories/create', [BlogManagementController::class, 'categoriesCreate'])->name('categories.create');
            Route::post('/categories', [BlogManagementController::class, 'categoriesStore'])->name('categories.store');
            Route::get('/categories/{id}/edit', [BlogManagementController::class, 'categoriesEdit'])->name('categories.edit');
            Route::put('/categories/{id}', [BlogManagementController::class, 'categoriesUpdate'])->name('categories.update');
            Route::delete('/categories/{id}', [BlogManagementController::class, 'categoriesDestroy'])->name('categories.destroy');

            Route::get('/posts', [BlogManagementController::class, 'postsIndex'])->name('posts.index');
            Route::get('/posts/create', [BlogManagementController::class, 'postsCreate'])->name('posts.create');
            Route::post('/posts', [BlogManagementController::class, 'postsStore'])->name('posts.store');
            Route::get('/posts/{id}/edit', [BlogManagementController::class, 'postsEdit'])->name('posts.edit');
            Route::put('/posts/{id}', [BlogManagementController::class, 'postsUpdate'])->name('posts.update');
            Route::delete('/posts/{id}', [BlogManagementController::class, 'postsDestroy'])->name('posts.destroy');
        });
    });
});
