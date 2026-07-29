<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
}
