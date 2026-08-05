# Admin Dashboard Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 3-stat-card admin dashboard with stat cards, trend charts, and leaderboard/activity tables, using only models that already exist in this backend.

**Architecture:** One controller method (`AdminController::dashboard()`) aggregates all data via Eloquent queries (patterns copied from `ToolStatsController`/`UserAnalyticsController`, which already implement the top-users and suspicious-IP queries against this schema); one view (`admin/dashboard.blade.php`) renders stat cards + two Chart.js charts + four tables; Chart.js is added to `admin/layout.blade.php` via CDN.

**Tech Stack:** Laravel (Blade views, Eloquent, Carbon), Chart.js (CDN), PHPUnit feature tests, `RefreshDatabase`.

## Global Constraints
- Fixed 30-day window for trend charts (no range selector) — per spec.
- No new models/migrations — use only `User`, `CreditTransaction`, `TtsHistory`, `VideoDubJob`, `PendingCreditTopup`, `LoginLog`, `FeatureUsage` (all already exist in `app/Models`).
- Drop the old backend's Device/DspPreset/AudioFile legacy row — no equivalent models exist here.
- Chart.js loaded via CDN in `admin/layout.blade.php`, matching how jQuery/Bootstrap/SweetAlert2 are already loaded there — no bundler/npm entry.
- Reuse the existing `admin._partials._stats-card` partial as-is (no changes needed — it already supports icon/value/label/variant/subtitle/link).

---

### Task 1: Add Chart.js to the admin layout

**Files:**
- Modify: `resources/views/admin/layout.blade.php:238-247` (script block, right before `@stack('scripts')`)

**Interfaces:**
- Produces: a global `Chart` constructor available to any admin page's `@push('scripts')` block.

- [ ] **Step 1: Add the Chart.js CDN script tag**

In `resources/views/admin/layout.blade.php`, immediately after the "Admin Core JS" script tag and before `@stack('scripts')`, add:

```html
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
```

- [ ] **Step 2: Verify no existing page breaks**

Run: `php artisan test --filter=AdminLayoutTest`
Expected: the two tests unrelated to `admin.dashboard` (`test_breadcrumb_partial_renders_linked_and_current_items`, `test_layout_extends_correctly_with_a_throwaway_page`) still PASS. (`test_layout_renders_page_title_and_content` is already broken before this change — Task 4 fixes it.)

- [ ] **Step 3: Commit**

```bash
git add resources/views/admin/layout.blade.php
git commit -m "Add Chart.js to the admin layout for dashboard charts"
```

---

### Task 2: Expand `AdminController::dashboard()` to aggregate full dashboard data

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminController.php:1-79`

**Interfaces:**
- Consumes: `App\Models\User` (`currentlyPremium()` scope, already exists), `App\Models\CreditTransaction` (`TYPE_DEDUCT` const), `App\Models\TtsHistory`, `App\Models\VideoDubJob`, `App\Models\PendingCreditTopup` (`STATUS_PENDING` const), `App\Models\LoginLog` (`ACTION_REGISTER` const), `App\Models\FeatureUsage`.
- Produces: `dashboard()` passes these keys to the `admin.dashboard` view: `totalUsers`, `premiumUsers`, `newUsersToday`, `creditsToday`, `creditsThisWeek`, `creditsThisMonth`, `totalTtsRequests`, `ttsToday`, `totalVideoDubJobs`, `videoDubToday`, `pendingTopups`, `chartLabels` (array of `d/m` strings, 30 entries), `creditData` (array of int, 30 entries), `ttsData` (array of int, 30 entries), `loginData` (array of int, 30 entries), `topUsers` (Collection of `User` with `total_credits_used` and `total_tts_requests` attributes), `recentLogins` (Collection of `LoginLog` with `user` loaded), `suspiciousIPs` (Collection of objects with `ip_address`, `user_count`, `last_seen`), `topFeatures` (Collection of objects with `feature_name`, `total_usage`, `unique_users`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/AdminDashboardTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\CreditTransaction;
use App\Models\FeatureUsage;
use App\Models\LoginLog;
use App\Models\PendingCreditTopup;
use App\Models\TtsHistory;
use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_dashboard_returns_all_expected_data_keys(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertViewHasAll([
            'totalUsers', 'premiumUsers', 'newUsersToday',
            'creditsToday', 'creditsThisWeek', 'creditsThisMonth',
            'totalTtsRequests', 'ttsToday',
            'totalVideoDubJobs', 'videoDubToday',
            'pendingTopups',
            'chartLabels', 'creditData', 'ttsData', 'loginData',
            'topUsers', 'recentLogins', 'suspiciousIPs', 'topFeatures',
        ]);
    }

    public function test_chart_arrays_cover_a_fixed_30_day_window(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk();
        $this->assertCount(30, $response->viewData('chartLabels'));
        $this->assertCount(30, $response->viewData('creditData'));
        $this->assertCount(30, $response->viewData('ttsData'));
        $this->assertCount(30, $response->viewData('loginData'));
    }

    public function test_credits_today_only_counts_todays_deductions(): void
    {
        $user = User::factory()->create();

        CreditTransaction::create([
            'user_id' => $user->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -50, 'balance_after' => 0, 'description' => 'today',
        ]);
        CreditTransaction::create([
            'user_id' => $user->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -30, 'balance_after' => 0, 'description' => 'yesterday',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk()->assertViewHas('creditsToday', 50);
        $response->assertViewHas('creditsThisWeek', 80);
    }

    public function test_top_users_ranks_by_total_credit_usage_descending(): void
    {
        $heavy = User::factory()->create(['name' => 'Heavy User']);
        $light = User::factory()->create(['name' => 'Light User']);

        CreditTransaction::create([
            'user_id' => $heavy->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -500, 'balance_after' => 0, 'description' => 'x',
        ]);
        CreditTransaction::create([
            'user_id' => $light->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -10, 'balance_after' => 0, 'description' => 'x',
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $topUsers = $response->viewData('topUsers');
        $this->assertSame($heavy->id, $topUsers->first()->id);
        $this->assertEquals(500, $topUsers->first()->total_credits_used);
    }

    public function test_suspicious_ips_flags_ips_shared_by_multiple_registered_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        LoginLog::record($userA->id, LoginLog::ACTION_REGISTER, '203.0.113.5');
        LoginLog::record($userB->id, LoginLog::ACTION_REGISTER, '203.0.113.5');

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $suspiciousIPs = $response->viewData('suspiciousIPs');
        $this->assertTrue($suspiciousIPs->contains(fn ($ip) => $ip->ip_address === '203.0.113.5' && $ip->user_count == 2));
    }

    public function test_top_features_aggregates_usage_across_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        FeatureUsage::create(['user_id' => $userA->id, 'feature_name' => 'tts', 'usage_count' => 7, 'last_used_at' => now()]);
        FeatureUsage::create(['user_id' => $userB->id, 'feature_name' => 'tts', 'usage_count' => 3, 'last_used_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $topFeatures = $response->viewData('topFeatures');
        $tts = $topFeatures->firstWhere('feature_name', 'tts');
        $this->assertEquals(10, $tts->total_usage);
        $this->assertEquals(2, $tts->unique_users);
    }

    public function test_pending_topups_counts_only_pending_status(): void
    {
        $user = User::factory()->create();
        PendingCreditTopup::create([
            'user_id' => $user->id, 'transaction_code' => 'TXN1', 'amount' => 100_000,
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);
        PendingCreditTopup::create([
            'user_id' => $user->id, 'transaction_code' => 'TXN2', 'amount' => 100_000,
            'status' => PendingCreditTopup::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk()->assertViewHas('pendingTopups', 1);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=AdminDashboardTest`
Expected: FAIL — `assertViewHasAll` fails because the view only currently receives `totalUsers`, `premiumUsers`, `newUsersToday`.

Before continuing, check `App\Models\PendingCreditTopup`'s actual `$fillable` list with `Read app/Models/PendingCreditTopup.php` and adjust the test's `PendingCreditTopup::create([...])` calls to match the real column set if `transaction_code` isn't accurate — the model file wasn't opened during planning, only its `STATUS_*` constants were confirmed.

- [ ] **Step 3: Implement `dashboard()`**

Replace `app/Http/Controllers/Admin/AdminController.php` entirely with:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\FeatureUsage;
use App\Models\LoginLog;
use App\Models\PendingCreditTopup;
use App\Models\TtsHistory;
use App\Models\User;
use App\Models\VideoDubJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    public function showLogin()
    {
        if (auth()->check() && auth()->user()->is_admin) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $turnstileError = \App\Services\TurnstileVerificationService::verify(
            $request->input('cf_turnstile_token'),
            $request->ip(),
        );

        if ($turnstileError !== null) {
            return back()->with('error', $turnstileError)->withInput();
        }

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();

            if (!$user->is_admin) {
                Auth::logout();
                return back()->with('error', 'Access denied. Admin privileges required.')->withInput();
            }

            $request->session()->regenerate();

            return redirect()->intended(route('admin.dashboard'))->with('success', 'Welcome back, ' . $user->name . '!');
        }

        return back()->with('error', 'Invalid email or password.')->withInput();
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Logged out successfully.');
    }

    public function dashboard()
    {
        $today = Carbon::today();

        // ── Stats Cards ──────────────────────────────────────────────
        $totalUsers = User::count();
        $premiumUsers = User::currentlyPremium()->count();
        $newUsersToday = User::whereDate('created_at', $today)->count();

        $creditsToday = abs(CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)
            ->whereDate('created_at', $today)->sum('amount'));
        $creditsThisWeek = abs(CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)
            ->where('created_at', '>=', $today->copy()->startOfWeek())->sum('amount'));
        $creditsThisMonth = abs(CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)
            ->where('created_at', '>=', $today->copy()->startOfMonth())->sum('amount'));

        $totalTtsRequests = TtsHistory::count();
        $ttsToday = TtsHistory::whereDate('created_at', $today)->count();

        $totalVideoDubJobs = VideoDubJob::count();
        $videoDubToday = VideoDubJob::whereDate('created_at', $today)->count();

        $pendingTopups = PendingCreditTopup::where('status', PendingCreditTopup::STATUS_PENDING)->count();

        // ── Chart Trends (30 days) ───────────────────────────────────
        $thirtyDaysAgo = $today->copy()->subDays(29);

        $creditTrend = CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, ABS(SUM(amount)) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $ttsTrend = TtsHistory::where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $loginTrend = LoginLog::where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $chartLabels = [];
        $creditData = [];
        $ttsData = [];
        $loginData = [];
        for ($i = 0; $i < 30; $i++) {
            $d = $thirtyDaysAgo->copy()->addDays($i)->format('Y-m-d');
            $chartLabels[] = Carbon::parse($d)->format('d/m');
            $creditData[] = (int) ($creditTrend[$d] ?? 0);
            $ttsData[] = (int) ($ttsTrend[$d] ?? 0);
            $loginData[] = (int) ($loginTrend[$d] ?? 0);
        }

        // ── Tables ───────────────────────────────────────────────────
        $topUsers = User::select('users.*')
            ->addSelect(DB::raw('(SELECT COALESCE(ABS(SUM(amount)), 0) FROM credit_transactions WHERE credit_transactions.user_id = users.id AND type = "deduct") as total_credits_used'))
            ->addSelect(DB::raw('(SELECT COUNT(*) FROM tts_histories WHERE tts_histories.user_id = users.id) as total_tts_requests'))
            ->orderByDesc('total_credits_used')
            ->limit(10)
            ->get();

        $recentLogins = LoginLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $suspiciousIPs = LoginLog::where('action', LoginLog::ACTION_REGISTER)
            ->select('ip_address')
            ->selectRaw('COUNT(DISTINCT user_id) as user_count')
            ->selectRaw('MAX(created_at) as last_seen')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(DISTINCT user_id) > 1')
            ->orderByDesc('user_count')
            ->limit(10)
            ->get();

        $topFeatures = FeatureUsage::select('feature_name')
            ->selectRaw('SUM(usage_count) as total_usage')
            ->selectRaw('COUNT(DISTINCT user_id) as unique_users')
            ->groupBy('feature_name')
            ->orderByDesc('total_usage')
            ->limit(10)
            ->get();

        return view('admin.dashboard', compact(
            'totalUsers',
            'premiumUsers',
            'newUsersToday',
            'creditsToday',
            'creditsThisWeek',
            'creditsThisMonth',
            'totalTtsRequests',
            'ttsToday',
            'totalVideoDubJobs',
            'videoDubToday',
            'pendingTopups',
            'chartLabels',
            'creditData',
            'ttsData',
            'loginData',
            'topUsers',
            'recentLogins',
            'suspiciousIPs',
            'topFeatures'
        ));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=AdminDashboardTest`
Expected: PASS (all 7 tests). If `PendingCreditTopup::create()` fails on mass assignment, re-check its `$fillable` array and fix the test's field names — do not change the model's fillable list to fit the test.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/AdminController.php tests/Feature/Admin/AdminDashboardTest.php
git commit -m "Expand admin dashboard controller with credit/TTS/login trends and leaderboards"
```

---

### Task 3: Rebuild the dashboard view with stat cards, charts, and tables

**Files:**
- Modify: `resources/views/admin/dashboard.blade.php` (full rewrite)

**Interfaces:**
- Consumes: every variable listed in Task 2's "Produces" line, plus `route('admin.users.index')`, `route('admin.analytics.ip.detail', $ip->ip_address)` (already registered routes).
- Produces: nothing consumed by later tasks — this is the leaf view.

- [ ] **Step 1: Load the dataviz skill for chart styling guidance**

Before writing the two `<script>` chart blocks in this task, invoke the `dataviz` skill and follow its color/axis/tooltip guidance instead of copying colors ad hoc.

- [ ] **Step 2: Replace `resources/views/admin/dashboard.blade.php`**

```blade
@extends('admin.layout')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3 mb-4">
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-users',
    'value' => $totalUsers,
    'label' => 'Total Users',
    'variant' => 'primary',
    'subtitle' => '+' . $newUsersToday . ' hôm nay',
    'link' => route('admin.users.index'),
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-crown',
    'value' => $premiumUsers,
    'label' => 'Premium Users',
    'variant' => 'purple',
    'subtitle' => round($totalUsers > 0 ? ($premiumUsers / $totalUsers) * 100 : 0, 1) . '% tổng users',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-coins',
    'value' => number_format($creditsToday),
    'label' => 'Credits Hôm Nay',
    'variant' => 'warning',
    'subtitle' => 'Tuần: ' . number_format($creditsThisWeek) . ' · Tháng: ' . number_format($creditsThisMonth),
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-headphones',
    'value' => $totalTtsRequests,
    'label' => 'TTS Requests',
    'variant' => 'info',
    'subtitle' => '+' . $ttsToday . ' hôm nay',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-language',
    'value' => $totalVideoDubJobs,
    'label' => 'Video Dub Jobs',
    'variant' => 'teal',
    'subtitle' => '+' . $videoDubToday . ' hôm nay',
    'link' => route('admin.videodub.index'),
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-money-bill-wave',
    'value' => $pendingTopups,
    'label' => 'Pending Topups',
    'variant' => $pendingTopups > 0 ? 'danger' : 'success',
    'subtitle' => $pendingTopups > 0 ? 'Cần xử lý' : 'Không có',
    'link' => route('admin.analytics.topups'),
    ])
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-line"></i> Credit & TTS Trends (30 ngày)
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="creditTtsChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-sign-in-alt"></i> Login Activity (30 ngày)
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="loginChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-trophy"></i> Top Users (Credit Usage)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th class="text-end">Credits</th>
                                <th class="text-end">TTS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topUsers as $user)
                            <tr>
                                <td>
                                    <div style="font-weight:500;">{{ $user->name }}</div>
                                    <small class="text-muted">{{ $user->email }}</small>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-warning text-dark">{{ number_format($user->total_credits_used) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-info">{{ number_format($user->total_tts_requests) }}</span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-clock"></i> Recent Logins
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>IP</th>
                                <th>Thời gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentLogins as $log)
                            <tr>
                                <td>
                                    <span style="font-weight:500;">{{ $log->user->name ?? 'N/A' }}</span>
                                </td>
                                <td><code style="font-size:12px;">{{ $log->ip_address }}</code></td>
                                <td>
                                    <small class="text-muted">{{ $log->created_at->diffForHumans() }}</small>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-shield-alt"></i> IP Nghi Vấn
                @if($suspiciousIPs->count() > 0)
                <span class="badge bg-danger ms-auto">{{ $suspiciousIPs->count() }}</span>
                @endif
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th class="text-center">Users</th>
                                <th>Lần cuối</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($suspiciousIPs as $ip)
                            <tr style="cursor:pointer;" onclick="window.location='{{ route('admin.analytics.ip.detail', $ip->ip_address) }}'">
                                <td><code style="font-size:12px;">{{ $ip->ip_address }}</code></td>
                                <td class="text-center">
                                    @if($ip->user_count >= 4)
                                    <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>{{ $ip->user_count }}</span>
                                    @else
                                    <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>{{ $ip->user_count }}</span>
                                    @endif
                                </td>
                                <td>
                                    <small class="text-muted">{{ \Carbon\Carbon::parse($ip->last_seen)->diffForHumans() }}</small>
                                </td>
                                <td><i class="fas fa-eye text-muted"></i></td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="text-center py-4">
                                    <span class="text-success"><i class="fas fa-check-circle me-1"></i>Không phát hiện IP nghi vấn</span>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-fire"></i> Top Features
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Feature</th>
                                <th class="text-end">Lượt dùng</th>
                                <th class="text-end">Users</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topFeatures as $i => $f)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td style="font-weight:500;">{{ $f->feature_name }}</td>
                                <td class="text-end">
                                    <span class="badge bg-primary">{{ number_format($f->total_usage) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-info">{{ number_format($f->unique_users) }}</span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">Chưa có dữ liệu</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-bar"></i> Feature Usage Chart
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="featureChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const chartLabels = @json($chartLabels);

        new Chart(document.getElementById('creditTtsChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Credits Used',
                        data: @json($creditData),
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: 'TTS Requests',
                        data: @json($ttsData),
                        borderColor: '#06b6d4',
                        backgroundColor: 'rgba(6, 182, 212, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 2,
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { grid: { display: false } },
                    y: { type: 'linear', position: 'left', title: { display: true, text: 'Credits' } },
                    y1: { type: 'linear', position: 'right', title: { display: true, text: 'TTS' }, grid: { drawOnChartArea: false } },
                },
            },
        });

        new Chart(document.getElementById('loginChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Logins',
                    data: @json($loginData),
                    backgroundColor: 'rgba(59, 130, 246, 0.6)',
                    borderColor: '#3b82f6',
                    borderWidth: 1,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { stepSize: 1 } } },
            },
        });

        const featureChartEl = document.getElementById('featureChart');
        if (featureChartEl) {
            new Chart(featureChartEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: @json($topFeatures->pluck('feature_name')),
                    datasets: [
                        {
                            label: 'Lượt dùng',
                            data: @json($topFeatures->pluck('total_usage')),
                            backgroundColor: 'rgba(99, 102, 241, 0.7)',
                            borderColor: '#6366f1',
                            borderWidth: 1,
                            borderRadius: 4,
                        },
                        {
                            label: 'Unique Users',
                            data: @json($topFeatures->pluck('unique_users')),
                            backgroundColor: 'rgba(6, 182, 212, 0.7)',
                            borderColor: '#06b6d4',
                            borderWidth: 1,
                            borderRadius: 4,
                        },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                },
            });
        }
    });
</script>
@endpush
```

- [ ] **Step 3: Run the dashboard test suite**

Run: `php artisan test --filter=AdminDashboardTest`
Expected: PASS (view now renders with the full variable set).

- [ ] **Step 4: Commit**

```bash
git add resources/views/admin/dashboard.blade.php
git commit -m "Redesign admin dashboard view with charts and leaderboard tables"
```

---

### Task 4: Fix the pre-existing broken `AdminLayoutTest` dashboard test

**Files:**
- Modify: `tests/Feature/Admin/AdminLayoutTest.php:9-22`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing consumed elsewhere.

This test currently fails on `master` already (confirmed by running it before any change in this plan) — it calls `view('admin.dashboard', [...3 keys...])->render()` directly, which bypasses the HTTP request lifecycle, so `auth()->user()` is `null` inside `admin/layout.blade.php` and the render throws. It also asserts a string (`'Admin Dashboard'`) that doesn't literally appear anywhere in the rendered output. Since Task 3 changes `dashboard.blade.php` to require many more variables, this test must be rewritten regardless.

- [ ] **Step 1: Replace the test with an HTTP-level check**

```php
    public function test_dashboard_renders_with_full_stat_and_chart_data(): void
    {
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->getContent();

        $this->assertStringContainsString('Total Users', $html);
        $this->assertStringContainsString('creditTtsChart', $html);
    }
```

Replace the existing `test_layout_renders_page_title_and_content` method (lines 9-22) with the method above — same position in the file, same class.

- [ ] **Step 2: Run it**

Run: `php artisan test --filter=AdminLayoutTest`
Expected: all 3 tests in the file PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Admin/AdminLayoutTest.php
git commit -m "Fix pre-existing broken dashboard layout test to match the redesigned view"
```

---

### Task 5: Full regression pass

- [ ] **Step 1: Run the entire test suite**

Run: `php artisan test`
Expected: no failures. If any admin or dashboard-adjacent test outside this plan's scope fails, investigate before proceeding — do not silence it by deleting assertions.

- [ ] **Step 2: Manually verify in the browser**

Start the dev stack (Docker, per this project's existing setup — see [[project_docker_env]] memory, no local PHP/MySQL install exists), log in as an admin, open `/admin/dashboard`, and confirm:
- All 6 stat cards render with real numbers (or zeros on an empty dev DB — not blank/error).
- Both trend charts render (30 data points each).
- Top Users / Recent Logins / Suspicious IPs / Top Features tables render, showing "Chưa có dữ liệu" instead of erroring when empty.
- Feature usage chart renders when `topFeatures` is non-empty, and the page doesn't error when it's empty (`if (featureChartEl)` guard already handles zero-canvas edge case — but verify with an empty table too, since `document.getElementById('featureChart')` always exists here, only the *data* is empty).

- [ ] **Step 3: Commit any fixes found during manual verification, if needed**
