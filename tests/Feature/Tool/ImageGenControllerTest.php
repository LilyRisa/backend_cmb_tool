<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageGenControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        SystemSetting::setImageGenApiKey('sk-test-key');
    }

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

    public function test_generate_returns_image_urls_for_premium_user(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('fake-bytes')]]], 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat']);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(1, 'data.images');
    }

    public function test_generate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat'])
            ->assertStatus(403);
    }

    public function test_generate_requires_prompt(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', [])
            ->assertStatus(422);
    }

    public function test_generate_rejects_invalid_size(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat', 'size' => '999x999'])
            ->assertStatus(422);
    }

    public function test_generate_rejects_n_above_four(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat', 'n' => 5])
            ->assertStatus(422);
    }

    public function test_generate_returns_500_on_provider_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    public function test_generate_treats_explicit_null_size_and_n_as_defaults(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('fake-bytes')]]], 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat', 'size' => null, 'n' => null]);

        $response->assertOk()->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            return $request['size'] === '1024x1024' && $request['n'] === 1;
        });
    }

    public function test_generate_route_has_both_per_minute_and_daily_throttle_buckets(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/tool/generate-image' && in_array('POST', $r->methods()));

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();

        $this->assertTrue(collect($middleware)->contains(fn ($m) => str_contains($m, 'throttle:5,1,generate-image')));
        $this->assertTrue(collect($middleware)->contains(fn ($m) => str_contains($m, 'throttle:60,1440,generate-image-daily')));
    }

    public function test_generate_does_not_touch_user_credits(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('fake-bytes')]]], 200)]);
        $user = $this->premiumUser(['monthly_credits' => 1000, 'purchased_credits' => 0]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat'])
            ->assertOk();

        // Credit deduction for this endpoint is entirely client-orchestrated via
        // /credits/deduct-feature + /credits/confirm-feature — the endpoint itself
        // must never touch the balance.
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }
}
