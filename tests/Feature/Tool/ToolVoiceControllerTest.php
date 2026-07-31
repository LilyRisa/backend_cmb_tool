<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\User;
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

    public function test_clone_uploads_audio_file(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voice_id' => 'new_v1'], 200)]);
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', ['file' => $file, 'voice_name' => 'My Voice']);

        $response->assertOk()->assertJsonPath('voice_id', 'new_v1');
    }

    public function test_clone_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', [])
            ->assertStatus(422);
    }

    public function test_delete_voice_calls_genmax(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->deleteJson('/api/tool/voices/voice_123')
            ->assertOk();
    }
}
