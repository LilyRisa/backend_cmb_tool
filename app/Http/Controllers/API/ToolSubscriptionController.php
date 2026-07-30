<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PendingSubscriptionPayment;
use App\Models\SystemSetting;
use App\Services\SePayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ToolSubscriptionController extends Controller
{
    public function current(Request $request)
    {
        $user = $request->user();
        $subscription = $user->activeSubscription();

        return response()->json([
            'has_subscription' => $subscription !== null,
            'is_premium' => $user->isPremium(),
            'package_type' => $user->package_type,
            'package_expires_at' => $user->package_expires_at?->toIso8601String(),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan' => $subscription->plan,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'expires_at' => $subscription->expires_at?->toIso8601String(),
            ] : null,
            'plans' => SystemSetting::getPremiumPlans(),
        ]);
    }

    public function subscribe(Request $request)
    {
        $request->validate(['plan' => 'required|string']);

        $plan = collect(SystemSetting::getPremiumPlans())->firstWhere('id', $request->plan);
        if (!$plan) {
            return response()->json(['error' => 'Gói không tồn tại'], 422);
        }

        if (!SePayService::hasBankConfig()) {
            Log::error('SePay bank config missing');
            return response()->json(['error' => 'Chưa cấu hình thanh toán. Vui lòng liên hệ admin.'], 500);
        }

        $user = $request->user();
        $code = SePayService::generateTransactionCode($user->id, 'SUB');

        $payment = PendingSubscriptionPayment::create([
            'user_id' => $user->id,
            'plan' => $plan['id'],
            'amount' => (int) $plan['price'],
            'duration_days' => (int) $plan['duration_days'],
            'monthly_credits' => (int) ($plan['monthly_credits'] ?? SystemSetting::getPremiumMonthlyCredits()),
            'transaction_code' => $code,
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);

        return response()->json([
            'subscription_payment_id' => $payment->id,
            'plan' => $plan,
            'transaction_code' => $code,
            'bank_info' => SePayService::bankInfo((int) $plan['price'], $code),
            'qr_url' => SePayService::qrUrl((int) $plan['price'], $code),
        ]);
    }

    public function status(Request $request, int $id)
    {
        $payment = PendingSubscriptionPayment::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$payment) {
            return response()->json(['error' => 'Không tìm thấy giao dịch'], 404);
        }

        return response()->json([
            'id' => $payment->id,
            'status' => $payment->status,
            'plan' => $payment->plan,
            'amount' => $payment->amount,
            'transaction_code' => $payment->transaction_code,
            'completed_at' => $payment->completed_at?->toIso8601String(),
            'created_at' => $payment->created_at->toIso8601String(),
        ]);
    }
}
