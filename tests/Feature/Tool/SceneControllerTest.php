<?php

namespace Tests\Feature\Tool;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SceneControllerTest extends TestCase
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

    private function scenesResponse(): array
    {
        return ['choices' => [['message' => ['content' => json_encode([
            ['text' => 'A scene.', 'visual_description' => 'a scene', 'duration_weight' => 3],
        ])]]]];
    }

    public function test_generate_returns_scenes_for_premium_user(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->scenesResponse(), 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', [
                'script' => 'A scene.',
                'context' => 'lạc quan',
                'total_duration' => 15,
            ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.total_scenes', 1);
    }

    public function test_generate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['script' => 'A scene.', 'context' => 'lạc quan', 'total_duration' => 15])
            ->assertStatus(403);
    }

    public function test_generate_requires_valid_context_enum(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['script' => 'A scene.', 'context' => 'not-a-real-context', 'total_duration' => 15])
            ->assertStatus(422);
    }

    public function test_generate_requires_script(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['context' => 'lạc quan', 'total_duration' => 15])
            ->assertStatus(422);
    }

    public function test_generate_returns_500_on_provider_failure(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['script' => 'A scene.', 'context' => 'lạc quan', 'total_duration' => 15])
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }
}
