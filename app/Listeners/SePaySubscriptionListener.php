<?php

namespace App\Listeners;

use App\Models\PendingSubscriptionPayment;
use App\Models\User;
use App\Services\PremiumService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SePay\SePay\Events\SePayWebhookEvent;

class SePaySubscriptionListener
{
    public function handle(SePayWebhookEvent $event): void
    {
        $data = $event->sePayWebhookData;

        if ($data->transferType !== 'in') {
            return;
        }

        $pattern = config('sepay.pattern', 'CMB');
        $escaped = preg_quote($pattern, '/');
        if (!preg_match('/' . $escaped . '[A-Za-z0-9]+/', $data->content, $matches)) {
            return;
        }

        $payment = PendingSubscriptionPayment::findByTransactionCode($matches[0]);
        if (!$payment) {
            return;
        }

        if ($data->transferAmount < $payment->amount) {
            Log::warning('SePay subscription: amount mismatch', [
                'expected' => $payment->amount,
                'received' => $data->transferAmount,
                'id' => $payment->id,
            ]);
            return;
        }

        // The atomic claim and the actual activation must live in the same DB
        // transaction: if PremiumService::activate() throws after the claim succeeds,
        // the claim itself must roll back too (row goes back to "pending"), otherwise
        // the row is left "completed" with Premium never actually granted and no retry
        // path (PremiumService::activate() opens its own nested transaction/savepoint,
        // which is fine — it rolls back along with this outer one).
        DB::transaction(function () use ($payment, $data) {
            $claimed = PendingSubscriptionPayment::where('id', $payment->id)
                ->where('status', PendingSubscriptionPayment::STATUS_PENDING)
                ->update(['status' => PendingSubscriptionPayment::STATUS_COMPLETED, 'completed_at' => now()]);

            if ($claimed === 0) {
                Log::info('SePay subscription: already processed', ['id' => $payment->id]);
                return;
            }

            $user = User::find($payment->user_id);
            if (!$user) {
                Log::error('SePay subscription: user not found', ['user_id' => $payment->user_id]);
                return;
            }

            PremiumService::activate($user, [
                'id' => $payment->plan,
                'price' => $payment->amount,
                'duration_days' => $payment->duration_days,
                'monthly_credits' => $payment->monthly_credits,
            ], (string) $data->id, 'sepay');

            Log::info('SePay subscription: completed', ['id' => $payment->id, 'user_id' => $user->id, 'plan' => $payment->plan]);
        });
    }
}
