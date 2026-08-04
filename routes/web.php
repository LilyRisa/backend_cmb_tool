<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BlogManagementController;
use App\Http\Controllers\Admin\BugReportManagementController;
use App\Http\Controllers\Admin\InquiryManagementController;
use App\Http\Controllers\Admin\ToolManagementController;
use App\Http\Controllers\Admin\ToolSettingsController;
use App\Http\Controllers\Admin\ToolStatsController;
use App\Http\Controllers\Admin\UserAnalyticsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\VideoDubManagementController;
use App\Http\Controllers\API\OAuthController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\Api404Controller;
use App\Http\Controllers\SpaController;
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

Route::view('/', 'tool-spa');
Route::view('/login', 'tool-spa');
Route::view('/register', 'tool-spa');

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

        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::get('/users/{id}', [UserManagementController::class, 'show'])->name('users.show');
        Route::put('/users/{id}', [UserManagementController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserManagementController::class, 'destroy'])->name('users.destroy');

        Route::get('/analytics/ip', [UserAnalyticsController::class, 'ipAnalysis'])->name('analytics.ip');
        Route::get('/analytics/ip/{ip}', [UserAnalyticsController::class, 'ipDetail'])->name('analytics.ip.detail');
        Route::get('/analytics/topups', [UserAnalyticsController::class, 'topupHistory'])->name('analytics.topups');

        Route::get('/videodub', [VideoDubManagementController::class, 'index'])->name('videodub.index');
        Route::get('/videodub/{id}', [VideoDubManagementController::class, 'show'])->name('videodub.show');

        Route::get('/tool-settings', [ToolSettingsController::class, 'index'])->name('tool-settings.index');
        Route::post('/tool-settings', [ToolSettingsController::class, 'update'])->name('tool-settings.update');

        Route::get('/tool-stats', [ToolStatsController::class, 'index'])->name('tool-stats.index');
        Route::get('/tool-stats/user/{id}', [ToolStatsController::class, 'userDetail'])->name('tool-stats.user');
        Route::post('/tool-stats/user/{id}/add-credits', [ToolStatsController::class, 'addCredits'])->name('tool-stats.add-credits');
        Route::post('/tool-stats/user/{id}/set-premium', [ToolStatsController::class, 'setPremium'])->name('tool-stats.set-premium');

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

        Route::get('/contact-messages', [InquiryManagementController::class, 'contactMessagesIndex'])->name('contact-messages.index');
        Route::put('/contact-messages/{id}', [InquiryManagementController::class, 'contactMessagesUpdateStatus'])->name('contact-messages.update');

        Route::get('/preorders', [InquiryManagementController::class, 'preordersIndex'])->name('preorders.index');
        Route::put('/preorders/{id}', [InquiryManagementController::class, 'preordersUpdateStatus'])->name('preorders.update');

        Route::get('/bug-reports', [BugReportManagementController::class, 'index'])->name('bug-reports.index');
        Route::put('/bug-reports/{id}', [BugReportManagementController::class, 'updateStatus'])->name('bug-reports.update');
    });
});

// Any /api/* request that doesn't match a real routes/api.php route — either
// because no such path exists, or because it exists but an inline
// ->where(...) constraint rejected the input (e.g. a non-numeric {id}) —
// returns a plain 404, for every HTTP verb. Registered here so it's only
// ever reached after routes/api.php's own routes have had a chance to match
// (RouteServiceProvider loads api.php first); an ordinary Route::any (not
// Route::fallback) so it satisfies Laravel's route-matching pass directly
// for whatever verb was requested — this is what keeps a non-GET verb
// (e.g. DELETE) from tripping Laravel's alternate-verb 405 logic, which a
// GET-only fallback alone cannot prevent. This route runs under web.php's
// `web` middleware group, but it must behave like the stateless /api/*
// endpoints it's guarding for, so the whole session/cookie/CSRF stack is
// excluded below — not just CSRF. Without VerifyCsrfToken excluded, a real
// non-GET request from an API client with no CSRF token gets 419, not 404.
// Without the session/cookie middleware also excluded, every unmatched
// /api/* hit (routine bot/scanner traffic against /api/<random> included,
// at any rate — this route has no throttle) would write a session file to
// disk and set Set-Cookie headers just to return a 404.
// Side effect worth knowing: because this rule matches every verb, a real,
// pre-existing /api/* route hit with an unsupported verb (e.g. POST to a
// GET-only route) now returns 404 instead of Laravel's standard 405 Method
// Not Allowed, across the entire /api/* surface — not just the new SPA
// routes. Arguably fine (arguably better — doesn't leak route existence to
// a prober), but undocumented behavior otherwise.
Route::any('/api/{any}', Api404Controller::class)
    ->where('any', '.*')
    ->withoutMiddleware([
        \App\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \App\Http\Middleware\EncryptCookies::class,
    ]);

// User-portal SPA fallback — Route::fallback() (not a Route::get('/{any}')
// catch-all) so it only fires when NO route matches at dispatch time. This
// correctly defers to routes registered after boot (e.g. test helpers that
// register routes in setUp()), which a plain registration-order catch-all
// would shadow. GET-only is intentional here — serving an HTML page only
// makes sense for GET; the /api/{any} rule above already handles every
// verb for the /api/* namespace.
Route::fallback(SpaController::class);
