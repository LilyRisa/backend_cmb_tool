<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CreditService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ToolCreditController extends Controller
{
    public function balance(Request $request)
    {
        $user = $request->user();

        $totalUsed = CreditTransaction::where('user_id', $user->id)
            ->where('type', CreditTransaction::TYPE_DEDUCT)
            ->sum('amount');

        $totalRefunded = CreditTransaction::where('user_id', $user->id)
            ->where('type', CreditTransaction::TYPE_REFUND)
            ->sum('amount');

        $charsPerMinute = max(SystemSetting::getCharsPerMinute(), 1);
        $totalCredits = ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0);

        return response()->json([
            'minutes_remaining' => CreditService::creditsToMinutes($totalCredits, $charsPerMinute),
            'minutes_used' => CreditService::creditsToMinutes(abs($totalUsed), $charsPerMinute),
            'minutes_refunded' => CreditService::creditsToMinutes($totalRefunded, $charsPerMinute),
            'credits' => $totalCredits,
            'monthly_credits' => $user->monthly_credits ?? 0,
            'purchased_credits' => $user->purchased_credits ?? 0,
            'credits_reset_at' => $user->credits_reset_at ? Carbon::parse($user->credits_reset_at)->toIso8601String() : null,
            'total_used' => abs($totalUsed),
            'total_refunded' => $totalRefunded,
            'chars_per_minute' => $charsPerMinute,
            'package_type' => $user->package_type,
            'package_expires_at' => $user->package_expires_at ? Carbon::parse($user->package_expires_at)->toIso8601String() : null,
            'is_premium' => $user->isPremium(),
        ]);
    }

    public function transactions(Request $request)
    {
        $pageSize = min((int) $request->get('page_size', 30), 100);
        $page = max((int) $request->get('page', 1), 1);
        $type = $request->get('type');

        $query = CreditTransaction::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');

        if ($type && in_array($type, ['deduct', 'topup', 'bonus', 'refund'])) {
            $query->where('type', $type);
        }

        $transactions = $query->paginate($pageSize, ['*'], 'page', $page);

        return response()->json([
            'transactions' => $transactions->map(function ($t) {
                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'balance_after' => $t->balance_after,
                    'description' => $t->description,
                    'created_at' => $t->created_at->toIso8601String(),
                ];
            }),
            'has_more' => $transactions->hasMorePages(),
            'total' => $transactions->total(),
            'current_page' => $transactions->currentPage(),
        ]);
    }

    public function referralInfo(Request $request)
    {
        $user = $request->user();

        if (empty($user->referral_code)) {
            $user->referral_code = User::generateUniqueReferralCode();
            $user->save();
        }

        $totalReferrals = User::where('referred_by', $user->id)->count();

        $totalEarned = CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', [CreditTransaction::TYPE_REFERRAL, CreditTransaction::TYPE_REFERRAL_COMMISSION])
            ->where('amount', '>', 0)
            ->sum('amount');

        $recentReferrals = CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', [CreditTransaction::TYPE_REFERRAL, CreditTransaction::TYPE_REFERRAL_COMMISSION])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'description' => $t->description,
                    'created_at' => $t->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'referral_code' => $user->referral_code,
            'referral_link' => $user->referral_link,
            'total_referrals' => $totalReferrals,
            'total_earned' => (int) $totalEarned,
            'referral_reward' => 800,
            'commission_rate' => 10,
            'recent_referrals' => $recentReferrals,
        ]);
    }
}
