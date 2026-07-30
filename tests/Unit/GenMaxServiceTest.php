<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use App\Services\GenMaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenMaxServiceTest extends TestCase
{
    use RefreshDatabase;

    private GenMaxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setGenMaxApiKey('sk-test-key');
        $this->service = new GenMaxService();
    }

    private function premiumUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
            'monthly_credits' => 1000,
            'purchased_credits' => 0,
            'credits' => 1000,
        ], $overrides));
    }

    public function test_text_to_speech_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello']);

        $this->assertFalse($result['success']);
        $this->assertEquals(403, $result['status']);
    }

    public function test_text_to_speech_rejects_insufficient_credits(): void
    {
        $user = $this->premiumUser(['monthly_credits' => 1, 'purchased_credits' => 0, 'credits' => 1]);

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => str_repeat('a', 500)]);

        $this->assertEquals(402, $result['status']);
        $this->assertEquals(1, $user->fresh()->credits);
    }

    public function test_text_to_speech_pre_deducts_credits_and_creates_history_on_success(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::response(['id' => 'genmax_task_123'], 200),
        ]);
        $user = $this->premiumUser();

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertTrue($result['success']);
        $this->assertEquals(202, $result['status']);
        $this->assertEquals('pending', $result['data']['status']);
        $this->assertDatabaseHas('tts_histories', [
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_123',
            'voice_id' => 'voice_abc',
            'status' => 'pending',
        ]);
        $this->assertLessThan(1000, $user->fresh()->monthly_credits);
    }

    public function test_text_to_speech_refunds_credits_when_genmax_fails(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::response(['error' => 'provider down'], 500),
        ]);
        $user = $this->premiumUser();

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertFalse($result['success']);
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }

    public function test_text_to_speech_uses_cache_for_identical_repeat_request(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'genmax_task_1'], 200)
                ->push(['status' => 'completed', 'characters_used' => 11, 'result' => ['audio_url' => 'https://cdn/audio1.mp3']], 200),
        ]);
        $user = $this->premiumUser();

        $first = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);
        $this->service->getTaskStatus($user, $first['data']['id']);

        $creditsBeforeSecond = $user->fresh()->monthly_credits;
        $second = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertTrue($second['success']);
        $this->assertEquals(200, $second['status']);
        $this->assertTrue($second['data']['cached'] ?? false);
        $this->assertEquals($creditsBeforeSecond, $user->fresh()->monthly_credits);
    }

    public function test_get_task_status_returns_404_for_missing_history(): void
    {
        $user = $this->premiumUser();

        $result = $this->service->getTaskStatus($user, 999999);

        $this->assertEquals(404, $result['status']);
    }

    public function test_get_task_status_polls_genmax_and_marks_completed_with_refund(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_1',
            'text' => str_repeat('a', 100),
            'credits_deducted_user' => 10,
            'status' => 'pending',
        ]);
        $user->decrement('monthly_credits', 10);

        Http::fake([
            'api.genmax.io/*' => Http::response([
                'status' => 'completed',
                'progress' => 100,
                'characters_used' => 50,
                'result' => ['audio_url' => 'https://cdn.genmax.io/audio/1.mp3'],
            ], 200),
        ]);

        $result = $this->service->getTaskStatus($user, $history->id);

        $this->assertEquals(200, $result['status']);
        $this->assertEquals('completed', $result['data']['status']);
        $this->assertEquals('https://cdn.genmax.io/audio/1.mp3', $result['data']['audio_url']);
        $this->assertTrue($history->fresh()->is_credit_deducted);
        $this->assertGreaterThan(990, $user->fresh()->monthly_credits);
    }

    public function test_get_task_status_refunds_all_credits_on_failure(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_2',
            'credits_deducted_user' => 15,
            'status' => 'pending',
        ]);
        $user->decrement('monthly_credits', 15);

        Http::fake([
            'api.genmax.io/*' => Http::response(['status' => 'failed', 'error' => 'synthesis error'], 200),
        ]);

        $this->service->getTaskStatus($user, $history->id);

        $this->assertEquals(1000, $user->fresh()->monthly_credits);
        $this->assertEquals('failed', $history->fresh()->status);
        $this->assertEquals(0, $history->fresh()->credits_deducted_user);
    }
}
