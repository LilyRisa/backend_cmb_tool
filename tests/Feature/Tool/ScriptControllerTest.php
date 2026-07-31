<?php

namespace Tests\Feature\Tool;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScriptControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function premiumUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
        ], $attributes));
    }

    public function test_generate_returns_script_for_premium_user(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Generated script.']]]], 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', [
                'topic' => 'cats',
                'word_count' => 20,
                'context' => 'vui vẻ',
                'language' => 'Tiếng Việt',
            ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.script', 'Generated script.');
    }

    public function test_generate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', [
                'topic' => 'cats', 'word_count' => 20, 'context' => 'vui vẻ', 'language' => 'Tiếng Việt',
            ])
            ->assertStatus(403);
    }

    public function test_generate_requires_topic(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', ['context' => 'vui vẻ', 'language' => 'Tiếng Việt', 'word_count' => 20])
            ->assertStatus(422);
    }

    public function test_generate_requires_word_count_or_duration(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', ['topic' => 'cats', 'context' => 'vui vẻ', 'language' => 'Tiếng Việt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('word_count');
    }

    public function test_generate_returns_500_on_provider_failure(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', ['topic' => 'cats', 'word_count' => 20, 'context' => 'vui vẻ', 'language' => 'Tiếng Việt'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }
}
