<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AIControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function premiumUser(): User
    {
        return User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10)]);
    }

    public function test_transcribe_returns_srt_for_premium_user(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 1, 'text' => 'Hi']]], 200)]);
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))->post('/api/transcribe', ['file' => $file]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('Hi', $response->json('srt'));
    }

    public function test_transcribe_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/transcribe', ['file' => $file])
            ->assertStatus(403);
    }

    public function test_transcribe_requires_email_verification(): void
    {
        $user = User::factory()->unverified()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10)]);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/transcribe', ['file' => $file])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_translate_text_format(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Xin chào']]]]],
            ], 200),
        ]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => 'Hello',
            'target_language' => 'vi',
            'format' => 'text',
        ]);

        $response->assertOk()->assertJsonPath('translated', 'Xin chào');
    }

    public function test_translate_srt_format_uses_chunk_translator(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "1\n00:00:01,000 --> 00:00:02,000\nDòng 1"]]]]],
            ], 200),
        ]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => "1\n00:00:01,000 --> 00:00:02,000\nLine 1",
            'target_language' => 'vi',
            'format' => 'srt',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Dòng 1', $response->json('translated'));
    }

    public function test_translate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => 'Hello',
            'target_language' => 'vi',
            'format' => 'text',
        ])->assertStatus(403);
    }

    public function test_transcribe_and_translate_routes_are_rate_limited(): void
    {
        $middlewareFor = function (string $uri): array {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));

            $this->assertNotNull($route, "Route POST {$uri} not found");

            return $route->gatherMiddleware();
        };

        // Explicit 3rd throttle segment is this project's convention: without it,
        // Laravel's default key is only user-ID/IP and the limit collides across routes.
        $this->assertContains('throttle:10,1,transcribe', $middlewareFor('api/transcribe'));
        $this->assertContains('throttle:10,1,translate', $middlewareFor('api/translate'));
    }

    public function test_translate_returns_500_on_provider_failure(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => 'Hello',
            'target_language' => 'vi',
            'format' => 'text',
        ])->assertStatus(500)->assertJsonPath('success', false);
    }
}
