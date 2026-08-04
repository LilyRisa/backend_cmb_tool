<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\PendingCreditTopup;
use Illuminate\Http\Request;

class UserAnalyticsController extends Controller
{
    /**
     * GET /admin/analytics/ip
     * Danh sách IP đăng ký — fraud detection
     * Chỉ dùng IP đăng ký (web) vì app đi qua proxy, IP login không chính xác.
     */
    public function ipAnalysis(Request $request)
    {
        $query = LoginLog::where('action', LoginLog::ACTION_REGISTER)
            ->select('ip_address')
            ->selectRaw('COUNT(DISTINCT user_id) as user_count')
            ->selectRaw('COUNT(*) as register_count')
            ->selectRaw('MAX(created_at) as last_seen')
            ->groupBy('ip_address');

        $minUsers = $request->get('min_users', 2);
        $query->havingRaw('COUNT(DISTINCT user_id) >= ?', [$minUsers]);

        if ($request->filled('search')) {
            $query->where('ip_address', 'LIKE', "%{$request->search}%");
        }

        $query->orderByDesc('user_count');
        $ips = $query->paginate(30)->withQueryString();

        $totalSuspicious = LoginLog::where('action', LoginLog::ACTION_REGISTER)
            ->select('ip_address')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(DISTINCT user_id) > 1')
            ->get()->count();
        $highRisk = LoginLog::where('action', LoginLog::ACTION_REGISTER)
            ->select('ip_address')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(DISTINCT user_id) >= 4')
            ->get()->count();

        return view('admin.analytics.ip-analysis', compact('ips', 'totalSuspicious', 'highRisk'));
    }

    /**
     * GET /admin/analytics/ip/{ip}
     * Chi tiết 1 IP: tất cả users đăng ký từ IP này
     * Chỉ dùng IP đăng ký vì app đi qua proxy.
     */
    public function ipDetail(string $ip)
    {
        $ip = urldecode($ip);

        $users = LoginLog::where('ip_address', $ip)
            ->where('action', LoginLog::ACTION_REGISTER)
            ->select('user_id')
            ->selectRaw('COUNT(*) as register_count')
            ->selectRaw('MAX(created_at) as last_seen')
            ->selectRaw('MIN(created_at) as first_seen')
            ->groupBy('user_id')
            ->with('user')
            ->get();

        $timeline = LoginLog::where('ip_address', $ip)
            ->where('action', LoginLog::ACTION_REGISTER)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        $totalRegistrations = LoginLog::where('ip_address', $ip)
            ->where('action', LoginLog::ACTION_REGISTER)->count();

        return view('admin.analytics.ip-detail', compact('ip', 'users', 'timeline', 'totalRegistrations'));
    }

    /**
     * GET /admin/analytics/topups
     * Credit Top-up History
     */
    public function topupHistory(Request $request)
    {
        $query = PendingCreditTopup::with('user')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transaction_code', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        $topups = $query->paginate(30)->withQueryString();

        $totalPending = PendingCreditTopup::where('status', 'pending')->count();
        $totalCompleted = PendingCreditTopup::where('status', 'completed')->count();
        $totalRevenue = PendingCreditTopup::where('status', 'completed')->sum('amount');

        return view('admin.analytics.topup-history', compact(
            'topups',
            'totalPending',
            'totalCompleted',
            'totalRevenue'
        ));
    }
}
