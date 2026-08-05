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
