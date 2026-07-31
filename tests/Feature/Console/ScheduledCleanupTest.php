<?php

namespace Tests\Feature\Console;

use App\Models\PendingCreditTopup;
use App\Models\PendingSubscriptionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScheduledCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_schedule_prunes_expired_email_verification_tokens(): void
    {
        $user = User::factory()->create();

        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => hash('sha256', 'expired-token'),
            'expires_at' => now()->subDay(),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => hash('sha256', 'valid-token'),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The cleanup job is registered with ->daily(), which cron-evaluates as
        // due only at 00:00. Travel there so `schedule:run` actually fires it,
        // instead of asserting against the Kernel's private schedule() wiring.
        $this->travelTo(Carbon::tomorrow()->startOfDay());

        $this->artisan('schedule:run');

        $this->assertDatabaseMissing('email_verification_tokens', ['token' => hash('sha256', 'expired-token')]);
        $this->assertDatabaseHas('email_verification_tokens', ['token' => hash('sha256', 'valid-token')]);
    }

    public function test_daily_schedule_expires_old_pending_credit_topups(): void
    {
        $user = User::factory()->create();

        $old = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'status' => PendingCreditTopup::STATUS_PENDING,
            'transaction_code' => 'CMBOLDTOPUP1',
            'created_at' => now()->subHours(25),
        ]);
        $recent = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'status' => PendingCreditTopup::STATUS_PENDING,
            'transaction_code' => 'CMBRECENTTOPUP1',
            'created_at' => now()->subHours(1),
        ]);

        $this->travelTo(Carbon::tomorrow()->startOfDay());
        $this->artisan('schedule:run');

        $this->assertEquals(PendingCreditTopup::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertEquals(PendingCreditTopup::STATUS_PENDING, $recent->fresh()->status);
    }

    public function test_daily_schedule_expires_old_pending_subscription_payments(): void
    {
        $user = User::factory()->create();

        $old = PendingSubscriptionPayment::factory()->create([
            'user_id' => $user->id,
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
            'transaction_code' => 'CMBSUBOLD1',
            'created_at' => now()->subHours(25),
        ]);
        $recent = PendingSubscriptionPayment::factory()->create([
            'user_id' => $user->id,
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
            'transaction_code' => 'CMBSUBRECENT1',
            'created_at' => now()->subHours(1),
        ]);

        $this->travelTo(Carbon::tomorrow()->startOfDay());
        $this->artisan('schedule:run');

        $this->assertEquals(PendingSubscriptionPayment::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertEquals(PendingSubscriptionPayment::STATUS_PENDING, $recent->fresh()->status);
    }

    public function test_daily_schedule_reclaims_orphaned_srt_temp_files(): void
    {
        $disk = Storage::disk('local');

        // An orphan: a temp upload whose job row was cascade-deleted (user deleted
        // mid-pipeline), so the job never ran and never called its own cleanup().
        $orphan = 'srt-generate-temp/orphan-test.mp3';
        $fresh = 'srt-generate-temp/fresh-test.mp3';

        $disk->put($orphan, 'stale audio bytes');
        $disk->put($fresh, 'in-flight audio bytes');

        // The Storage facade can't backdate an mtime — touch the real path instead.
        touch($disk->path($orphan), now()->subDays(2)->timestamp);

        try {
            $this->travelTo(Carbon::tomorrow()->startOfDay());

            $this->artisan('schedule:run');

            $this->assertFalse($disk->exists($orphan));
            $this->assertTrue($disk->exists($fresh));
        } finally {
            $disk->delete([$orphan, $fresh]);
        }
    }

    public function test_daily_schedule_reclaims_orphaned_video_dub_temp_files(): void
    {
        $disk = Storage::disk('local');

        // VideoDubController::dub() stages uploads into video-dub-temp, and
        // ProcessVideoDub has the same deserialization-orphan failure mode as the
        // SRT jobs — a cascade-deleted job row makes SerializesModels throw before
        // the job's own cleanup() can ever run, leaking the staged upload.
        $orphan = 'video-dub-temp/orphan-dub.mp3';
        $fresh = 'video-dub-temp/fresh-dub.mp3';

        $disk->put($orphan, 'stale audio bytes');
        $disk->put($fresh, 'in-flight audio bytes');

        touch($disk->path($orphan), now()->subDays(2)->timestamp);

        try {
            $this->travelTo(Carbon::tomorrow()->startOfDay());

            $this->artisan('schedule:run');

            $this->assertFalse($disk->exists($orphan));
            $this->assertTrue($disk->exists($fresh));
        } finally {
            $disk->delete([$orphan, $fresh]);
        }
    }
}
