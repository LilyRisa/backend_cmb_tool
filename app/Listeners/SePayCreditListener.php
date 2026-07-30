<?php

namespace App\Listeners;

use App\Models\CreditTransaction;
use App\Models\PendingCreditTopup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SePay\SePay\Events\SePayWebhookEvent;

class SePayCreditListener
{
    public function handle(SePayWebhookEvent $event): void
    {
        $data = $event->sePayWebhookData;

        if ($data->transferType !== 'in') {
            Log::info('SePay credit: ignored transfer type: ' . $data->transferType);
            return;
        }

        $content = $data->content;
        $pattern = config('sepay.pattern', 'CMB');

        $escapedPattern = preg_quote($pattern, '/');
        if (!preg_match('/' . $escapedPattern . '[A-Za-z0-9]+/', $content, $matches)) {
            Log::warning('SePay credit: transaction code not found', ['content' => $content, 'pattern' => $pattern]);
            return;
        }

        $transactionCode = $matches[0];
        $topup = PendingCreditTopup::findByTransactionCode($transactionCode);

        if (!$topup) {
            Log::warning('SePay credit: no pending topup found', ['transaction_code' => $transactionCode]);
            return;
        }

        if ($data->transferAmount < $topup->amount) {
            Log::warning('SePay credit: amount mismatch', [
                'expected' => $topup->amount,
                'received' => $data->transferAmount,
                'topup_id' => $topup->id,
            ]);
            return;
        }

        // The atomic claim and the actual crediting must live in the same DB
        // transaction: if addCredits() throws after the claim succeeds, the claim
        // itself must roll back too (row goes back to "pending"), otherwise the row
        // is left "completed" with nothing granted to the user and no retry path.
        DB::transaction(function () use ($topup) {
            $claimed = PendingCreditTopup::where('id', $topup->id)
                ->where('status', PendingCreditTopup::STATUS_PENDING)
                ->update(['status' => PendingCreditTopup::STATUS_COMPLETED, 'completed_at' => now()]);

            if ($claimed === 0) {
                Log::info('SePay credit: topup already completed', ['topup_id' => $topup->id]);
                return;
            }

            $user = User::find($topup->user_id);
            if (!$user) {
                Log::error('SePay credit: user not found', ['user_id' => $topup->user_id]);
                return;
            }

            $user->addCredits(
                $topup->credits,
                'topup',
                "Nạp {$topup->credits} credit - Gói {$topup->package_id}",
                PendingCreditTopup::class,
                $topup->id,
                'purchased'
            );

            Log::info('SePay credit: topup completed', ['topup_id' => $topup->id, 'user_id' => $user->id, 'credits' => $topup->credits]);

            if ($user->referred_by) {
                $referrer = User::find($user->referred_by);
                if ($referrer) {
                    $commission = (int) floor($topup->credits * 0.10);
                    if ($commission > 0) {
                        $referrer->addCredits(
                            $commission,
                            CreditTransaction::TYPE_REFERRAL_COMMISSION,
                            "Hoa hồng 10%: {$user->name} nạp {$topup->credits} credits",
                            PendingCreditTopup::class,
                            $topup->id,
                            'purchased'
                        );
                    }
                }
            }
        });
    }
}
