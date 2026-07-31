<?php

namespace App\Console;

use App\Models\PendingCreditTopup;
use App\Models\PendingSubscriptionPayment;
use App\Models\SrtGenerateJob;
use App\Models\SrtTranslateJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

        // Reclaim temp audio files left behind by crashed/interrupted SRT jobs (e.g. the
        // user was deleted mid-pipeline, cascading away the job row before it could run
        // and call its own cleanup()) — orphans older than a day are safe to remove since
        // a normal run always deletes its own temp file within minutes.
        $schedule->call(fn () => $this->pruneOrphanedSrtTempFiles())->daily();

        // Prune old completed/failed SRT job rows so the tables (which store full
        // longText SRT payloads) don't grow unbounded.
        $schedule->call(fn () => SrtGenerateJob::whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '<', now()->subDays(30))
            ->delete())->daily();

        $schedule->call(fn () => SrtTranslateJob::whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '<', now()->subDays(30))
            ->delete())->daily();
    }

    /**
     * Delete temp audio uploads in the SRT staging directories that are older than a
     * day — i.e. orphaned, since a job that actually runs cleans up its own file.
     *
     * Extracted from the schedule closure so it can be exercised directly by tests.
     */
    public function pruneOrphanedSrtTempFiles(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->timestamp;

        foreach (['srt-generate-temp', 'srt-translate-temp'] as $dir) {
            foreach ($disk->files($dir) as $file) {
                $lastModified = $disk->lastModified($file);

                if ($lastModified !== false && $lastModified < $cutoff) {
                    $disk->delete($file);
                }
            }
        }
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
