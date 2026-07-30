<?php

namespace App\Console;

use App\Models\PendingCreditTopup;
use App\Models\PendingSubscriptionPayment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        // Prune expired, never-clicked email verification tokens so the table
        // doesn't grow unbounded for users who never verify.
        $schedule->call(fn () => DB::table('email_verification_tokens')->where('expires_at', '<', now())->delete())->daily();

        // Expire pending credit topups / subscription payments that have sat
        // unpaid for more than 24 hours (typical bank-transfer window), so
        // abandoned checkouts don't grow the pending tables unbounded and
        // findByTransactionCode()'s pending-status lookups stay bounded.
        $schedule->call(fn () => PendingCreditTopup::where('status', PendingCreditTopup::STATUS_PENDING)
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => PendingCreditTopup::STATUS_EXPIRED]))->daily();

        $schedule->call(fn () => PendingSubscriptionPayment::where('status', PendingSubscriptionPayment::STATUS_PENDING)
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => PendingSubscriptionPayment::STATUS_EXPIRED]))->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
