<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TtsHistoryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_genmax_api_key_roundtrips_encrypted(): void
    {
        SystemSetting::setGenMaxApiKey('sk-test-12345');

        $this->assertEquals('sk-test-12345', SystemSetting::getGenMaxApiKey());
        $this->assertDatabaseMissing('system_settings', ['value' => 'sk-test-12345']);
    }

    public function test_genmax_api_key_returns_null_when_unset(): void
    {
        $this->assertNull(SystemSetting::getGenMaxApiKey());
    }

    public function test_tts_history_belongs_to_user_and_casts_voice_settings(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'voice_settings' => ['stability' => 0.5, 'style' => 0.2],
        ]);

        $this->assertEquals($user->id, $history->user->id);
        $this->assertIsArray($history->voice_settings);
        $this->assertEquals(0.5, $history->voice_settings['stability']);
    }

    public function test_scope_completed_filters_by_status(): void
    {
        TtsHistory::factory()->create(['status' => 'completed']);
        TtsHistory::factory()->create(['status' => 'pending']);

        $this->assertEquals(1, TtsHistory::completed()->count());
    }
}
