<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToolTtsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setGenMaxApiKey('sk-test-key');
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
            'monthly_credits' => 1000,
            'purchased_credits' => 0,
            'credits' => 1000,
        ]);
    }

    public function test_generate_submits_tts_and_returns_pending_task(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_1'], 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/tts/voice_abc', ['text' => 'Hello world']);

        $response->assertStatus(202)->assertJsonPath('status', 'pending');
    }

    public function test_generate_validates_text_length(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/tts/voice_abc', ['text' => str_repeat('a', 10001)])
            ->assertStatus(422);
    }

    public function test_generate_from_srt_parses_file_and_creates_tasks(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_srt'], 200)]);
        $user = $this->premiumUser();
        $srtContent = "1\n00:00:01,000 --> 00:00:02,000\nHello.\n\n2\n00:00:03,000 --> 00:00:04,000\nWorld.\n";
        $file = UploadedFile::fake()->createWithContent('subtitles.srt', $srtContent);

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/tts/srt/voice_abc', ['file' => $file]);

        $response->assertStatus(202);
        $this->assertDatabaseCount('tts_histories', 2);
    }

    public function test_generate_from_srt_rejects_invalid_extension(): void
    {
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/tts/srt/voice_abc', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_status_returns_task_state(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'audio_url' => 'https://cdn/a.mp3']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/tts/status/{$history->id}");

        $response->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_history_returns_users_recent_tasks(): void
    {
        $user = $this->premiumUser();
        TtsHistory::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/tts/history');

        $response->assertOk()->assertJsonCount(2, 'tasks');
    }

    public function test_delete_history_removes_the_record(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create(['user_id' => $user->id]);

        $this->withHeaders($this->authHeader($user))
            ->deleteJson("/api/tool/tts/history/{$history->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tts_histories', ['id' => $history->id]);
    }
}
