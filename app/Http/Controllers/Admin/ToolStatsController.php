<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\LoginLog;
use App\Models\SystemSetting;
use App\Models\Subscription;
use App\Models\TtsHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ToolStatsController extends Controller
{
    /**
     * GET /admin/tool-stats
     * Show overall tool usage statistics.
     */
    public function index(Request $request)
    {
        $totalUsers = User::count();
        $premiumUsers = User::where('package_type', '!=', 'free')->count();
        $totalTtsRequests = TtsHistory::count();
        $completedTtsRequests = TtsHistory::where('status', 'completed')->count();

        $totalCreditsUsed = abs(CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)->sum('amount'));
        $totalCreditsRefunded = CreditTransaction::where('type', CreditTransaction::TYPE_REFUND)->sum('amount');

        $today = Carbon::today();
        $creditsToday = abs(CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)
            ->whereDate('created_at', $today)->sum('amount'));
        $creditsThisWeek = abs(CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)
            ->where('created_at', '>=', $today->copy()->startOfWeek())->sum('amount'));
        $creditsThisMonth = abs(CreditTransaction::where('type', CreditTransaction::TYPE_DEDUCT)
            ->where('created_at', '>=', $today->copy()->startOfMonth())->sum('amount'));

        $ttsToday = TtsHistory::whereDate('created_at', $today)->count();

        $topUsers = User::select('users.*')
            ->addSelect(DB::raw('(SELECT COALESCE(ABS(SUM(amount)), 0) FROM credit_transactions WHERE credit_transactions.user_id = users.id AND type = "deduct") as total_credits_used'))
            ->addSelect(DB::raw('(SELECT COUNT(*) FROM tts_histories WHERE tts_histories.user_id = users.id) as total_tts_requests'))
            ->orderByDesc('total_credits_used')
            ->limit(20)
            ->get();

        $recentLogins = LoginLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return view('admin.tool-stats.index', compact(
            'totalUsers',
            'premiumUsers',
            'totalTtsRequests',
            'completedTtsRequests',
            'totalCreditsUsed',
            'totalCreditsRefunded',
            'creditsToday',
            'creditsThisWeek',
            'creditsThisMonth',
            'ttsToday',
            'topUsers',
            'recentLogins'
        ));
    }

    /**
     * GET /admin/tool-stats/user/{id}
     * Show detailed stats for a specific user.
     */
    public function userDetail(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        $transactions = CreditTransaction::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        $ttsHistories = TtsHistory::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(30, ['*'], 'tts_page');

        $loginLogs = LoginLog::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $videoDubJobs = \App\Models\VideoDubJob::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(15, ['*'], 'videodub_page');

        $bugReports = \App\Models\BugReport::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(15, ['*'], 'bugreport_page');

        $featureUsages = \App\Models\FeatureUsage::where('user_id', $id)
            ->orderBy('usage_count', 'desc')
            ->get();

        $totalUsed = abs(CreditTransaction::where('user_id', $id)
            ->where('type', CreditTransaction::TYPE_DEDUCT)->sum('amount'));
        $totalTts = TtsHistory::where('user_id', $id)->count();
        $completedTts = TtsHistory::where('user_id', $id)->where('status', 'completed')->count();

        return view('admin.tool-stats.user-detail', compact(
            'user',
            'transactions',
            'ttsHistories',
            'loginLogs',
            'videoDubJobs',
            'bugReports',
            'featureUsages',
            'totalUsed',
            'totalTts',
            'completedTts'
        ));
    }

    /**
     * POST /admin/tool-stats/user/{id}/add-credits
     * Admin manually adds credits to a user.
     */
    public function addCredits(Request $request, int $id)
    {
        $request->validate([
            'amount' => 'required|integer|min:1|max:100000',
            'description' => 'nullable|string|max:255',
            'credit_type' => 'nullable|in:monthly,purchased',
        ]);

        $user = User::findOrFail($id);
        $amount = $request->input('amount');
        $description = $request->input('description', 'Admin cộng credits');
        $creditType = $request->input('credit_type', 'purchased');

        $user->addCredits($amount, CreditTransaction::TYPE_BONUS, $description, 'admin', auth()->id(), $creditType);

        $label = $creditType === 'monthly' ? 'monthly' : 'purchased';
        return redirect()->back()->with('success', "Đã cộng {$amount} {$label} credits cho {$user->name}.");
    }

    /**
     * POST /admin/tool-stats/user/{id}/set-premium
     * Admin sets user premium status.
     */
    public function setPremium(Request $request, int $id)
    {
        $request->validate([
            'package_type' => 'required|string|in:free,premium,enterprise',
            'duration_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $user = User::findOrFail($id);
        $user->package_type = $request->input('package_type');

        if ($request->input('package_type') !== 'free' && $request->input('duration_days')) {
            $user->package_expires_at = now()->addDays($request->input('duration_days'));
        } elseif ($request->input('package_type') === 'free') {
            $user->package_expires_at = null;
        }

        $user->save();

        if ($user->package_type === 'free') {
            Subscription::where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update(['status' => Subscription::STATUS_CANCELLED]);
        } else {
            if ($user->isPremium() && $user->credits === 0) {
                $defaultCredits = SystemSetting::getPremiumMonthlyCredits();
                if ($defaultCredits > 0) {
                    $user->addCredits(
                        $defaultCredits,
                        CreditTransaction::TYPE_BONUS,
                        'Welcome bonus - Premium activation',
                        'admin',
                        auth()->id()
                    );
                }
            }

            Subscription::create([
                'user_id' => $user->id,
                'plan' => $user->package_type,
                'status' => Subscription::STATUS_ACTIVE,
                'amount' => 0,
                'payment_method' => 'admin',
                'transaction_id' => null,
                'starts_at' => now(),
                'expires_at' => $user->package_expires_at,
            ]);
        }

        return redirect()->back()->with('success', "Đã cập nhật gói {$user->package_type} cho {$user->name}.");
    }
}
