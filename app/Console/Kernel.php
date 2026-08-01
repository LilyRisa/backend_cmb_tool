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

        // Reclaim temp audio files left behind by crashed/interrupted SRT and video-dub
        // jobs (e.g. the user was deleted mid-pipeline, cascading away the job row before
        // it could run and call its own cleanup()) — orphans older than a day are safe to
        // remove since a normal run always deletes its own temp file within minutes.
        $schedule->call(fn () => $this->pruneOrphanedTempFiles())->daily();

        // Reclaim old AI-generated images — see pruneOldGeneratedImages() for why a
        // flat age cutoff is the only signal available for this directory.
        $schedule->call(fn () => $this->pruneOldGeneratedImages())->daily();

        // Prune old completed/failed SRT job rows so the tables (which store full
        // longText SRT payloads) don't grow unbounded.
        $schedule->call(fn () => SrtGenerateJob::whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '<', now()->subDays(30))
            ->delete())->daily();

        $schedule->call(fn () => SrtTranslateJob::whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '<', now()->subDays(30))
            ->delete())->daily();

        // Finalize video-dub jobs whose client stopped polling before the
        // linked TTS task finished — see CleanupStaleDubJobs for the
        // 30-minute stale / 120-minute force-timeout thresholds.
        // withoutOverlapping() needs an explicit expiry: its default mutex TTL is
        // 1440 minutes (24h), so a hard-killed run (OOM, kill -9, deploy restart)
        // would silently block every subsequent run for a full day. This batch of up
        // to 50 stale jobs, each polled over HTTP, can legitimately outrun its own
        // 5-minute interval under load, so the guard itself is needed.
        $schedule->command('dub:cleanup-stale')->everyFiveMinutes()->withoutOverlapping(10);

        // Queue processing is NOT scheduled here — the Docker deployment
        // (docker-compose.yml) runs a dedicated, always-running `worker`
        // container (`php artisan queue:work`) instead of draining the queue
        // on a schedule. A scheduler-driven drain only made sense in an
        // environment with no persistent supervisor process available; Docker
        // makes a real long-running worker trivial, so use that instead.
    }

    /**
     * Delete temp audio uploads in the SRT and video-dub staging directories that are
     * older than a day — i.e. orphaned, since a job that actually runs cleans up its
     * own file. ProcessVideoDub has the same deserialization-orphan failure mode as the
     * SRT jobs (SerializesModels throws restoring a cascade-deleted job row, so the
     * job's own cleanup() never runs and the staged upload leaks permanently).
     *
     * Extracted from the schedule closure so it can be exercised directly by tests.
     */
    public function pruneOrphanedTempFiles(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->timestamp;

        foreach (['srt-generate-temp', 'srt-translate-temp', 'video-dub-temp'] as $dir) {
            foreach ($disk->files($dir) as $file) {
                $lastModified = $disk->lastModified($file);

                if ($lastModified !== false && $lastModified < $cutoff) {
                    $disk->delete($file);
                }
            }
        }
    }

    /**
     * Reclaim generated images older than 30 days. These are permanent artifacts
     * with no DB row/history by design (v1 YAGNI — see design spec section 5), so
     * unlike temp uploads there's no owning record to expire against; a flat
     * age-based cutoff on the public disk is the only available cleanup signal.
     */
    public function pruneOldGeneratedImages(): void
    {
        $disk = Storage::disk('public');
        $cutoff = now()->subDays(30)->timestamp;

        foreach ($disk->files('generated-images') as $file) {
            $lastModified = $disk->lastModified($file);

            if ($lastModified !== false && $lastModified < $cutoff) {
                $disk->delete($file);
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
