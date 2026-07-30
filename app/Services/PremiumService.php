<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PremiumService
{
    /**
     * @param array $plan keys: id, price, duration_days, monthly_credits
     */
    public static function activate(User $user, array $plan, ?string $txId = null, string $method = 'sepay'): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $txId, $method) {
            $user = User::where('id', $user->id)->lockForUpdate()->first();

            $planId = (string) ($plan['id'] ?? 'monthly');
            $durationDays = (int) ($plan['duration_days'] ?? 30);
            $amount = (int) ($plan['price'] ?? 0);
            $targetMonthly = (int) ($plan['monthly_credits'] ?? SystemSetting::getPremiumMonthlyCredits());

            $base = ($user->package_expires_at && Carbon::parse($user->package_expires_at)->isFuture())
                ? Carbon::parse($user->package_expires_at)
                : now();
            $newExpiry = $base->copy()->addDays($durationDays);

            $user->package_type = 'premium';
            $user->package_expires_at = $newExpiry;

            $delta = max(0, $targetMonthly - (int) $user->monthly_credits);
            if ($delta > 0) {
                $user->monthly_credits = $targetMonthly;
                $user->credits = $user->monthly_credits + $user->purchased_credits;
                $user->credits_reset_at = now();
            }
            $user->save();

            if ($delta > 0) {
                CreditTransaction::create([
                    'user_id' => $user->id,
                    'type' => CreditTransaction::TYPE_SUBSCRIPTION,
                    'amount' => $delta,
                    'balance_after' => $user->monthly_credits + $user->purchased_credits,
                    'description' => "Premium {$planId} - cấp {$targetMonthly} monthly credits",
                ]);
            }

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan' => $planId,
                'status' => Subscription::STATUS_ACTIVE,
                'amount' => $amount,
                'payment_method' => $method,
                'transaction_id' => $txId,
                'starts_at' => now(),
                'expires_at' => $newExpiry,
            ]);

            Log::info('Premium activated', [
                'user_id' => $user->id,
                'plan' => $planId,
                'expires_at' => $newExpiry->toDateTimeString(),
                'tx' => $txId,
                'method' => $method,
            ]);

            return $subscription;
        });
    }
}
