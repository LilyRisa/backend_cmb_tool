<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceClone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToolVoiceControllerTest extends TestCase
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

    private function premiumUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
        ], $overrides));
    }

    public function test_models_returns_provider_filtered_list(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['models' => ['a']], 200)]);
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/models?provider=elevenlabs');

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'provider=elevenlabs'));
    }

    public function test_system_voices_returns_filtered_list(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voices' => []], 200)]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/tool/voices/system?gender=Female')
            ->assertOk();
    }

    public function test_cloned_voices_returns_list(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voices' => []], 200)]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))->getJson('/api/tool/voices/cloned')->assertOk();
    }

    public function test_cloned_voices_only_returns_callers_own_voices(): void
    {
        // GenMax has one shared provider account for all users, so the raw
        // provider response is account-wide. The endpoint must filter it down
        // to only the voices this specific caller cloned.
        Http::fake([
            'api.genmax.io/*' => Http::response(['voices' => [
                ['voice_id' => 'voice_mine', 'voice_name' => 'Mine'],
                ['voice_id' => 'voice_theirs', 'voice_name' => 'Not mine'],
            ]], 200),
        ]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        VoiceClone::factory()->create(['user_id' => $user->id, 'provider_voice_id' => 'voice_mine']);
        VoiceClone::factory()->create(['user_id' => $otherUser->id, 'provider_voice_id' => 'voice_theirs']);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/voices/cloned');

        $response->assertOk();
        $voices = $response->json('voices');
        $this->assertCount(1, $voices);
        $this->assertEquals('voice_mine', $voices[0]['voice_id']);
    }

    public function test_clone_uploads_audio_file(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voice_id' => 'new_v1'], 200)]);
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', ['file' => $file, 'voice_name' => 'My Voice']);

        $response->assertOk()->assertJsonPath('voice_id', 'new_v1');
    }

    public function test_clone_creates_voice_clone_record_scoped_to_user(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voice_id' => 'new_v2'], 200)]);
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', ['file' => $file, 'voice_name' => 'My Voice'])
            ->assertOk();

        $this->assertDatabaseHas('voice_clones', [
            'user_id' => $user->id,
            'provider_voice_id' => 'new_v2',
            'voice_name' => 'My Voice',
        ]);
    }

    public function test_clone_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);
        $file = UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', ['file' => $file, 'voice_name' => 'My Voice'])
            ->assertStatus(403);
    }

    public function test_clone_validates_required_fields(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', [])
            ->assertStatus(422);
    }

    public function test_delete_voice_calls_genmax(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = User::factory()->create();
        VoiceClone::factory()->create(['user_id' => $user->id, 'provider_voice_id' => 'voice_123']);

        $this->withHeaders($this->authHeader($user))
            ->deleteJson('/api/tool/voices/voice_123')
            ->assertOk();

        $this->assertDatabaseMissing('voice_clones', ['provider_voice_id' => 'voice_123']);
    }

    public function test_delete_voice_404s_for_another_users_voice_and_never_calls_genmax(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        VoiceClone::factory()->create(['user_id' => $owner->id, 'provider_voice_id' => 'voice_owned_by_someone_else']);

        $this->withHeaders($this->authHeader($attacker))
            ->deleteJson('/api/tool/voices/voice_owned_by_someone_else')
            ->assertStatus(404);

        Http::assertNothingSent();
        $this->assertDatabaseHas('voice_clones', ['provider_voice_id' => 'voice_owned_by_someone_else', 'user_id' => $owner->id]);
    }

    public function test_delete_voice_404s_for_unknown_voice(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->deleteJson('/api/tool/voices/voice_never_existed')
            ->assertStatus(404);

        Http::assertNothingSent();
    }
}
