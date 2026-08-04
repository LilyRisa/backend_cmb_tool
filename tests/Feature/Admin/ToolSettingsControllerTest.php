<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'image_gen_base_url' => 'https://api.openai.com/v1',
            'image_gen_model' => 'gpt-image-1',
            'image_gen_credits_per_image' => 200,
            'ai_text_base_url' => 'https://openrouter.ai/api/v1',
            'ai_text_model' => '~google/gemini-flash-latest',
        ], $overrides);
    }

    public function test_index_shows_the_raw_api_key_for_admin_editing(): void
    {
        SystemSetting::setImageGenApiKey('sk-super-secret');
        SystemSetting::setImageGenModel('dall-e-3');

        $response = $this->actingAsAdmin()->get('/admin/tool-settings');

        $response->assertOk();
        $response->assertViewHas('settings', function ($settings) {
            return $settings['image_gen_model'] === 'dall-e-3' && $settings['image_gen_api_key'] === 'sk-super-secret';
        });
    }

    public function test_index_shows_defaults_when_nothing_configured(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/tool-settings');

        $response->assertOk();
        $response->assertViewHas('settings', function ($settings) {
            return $settings['image_gen_base_url'] === 'https://api.openai.com/v1'
                && $settings['image_gen_credits_per_image'] === 200
                && $settings['image_gen_api_key'] === null;
        });
    }

    public function test_update_saves_new_settings(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/tool-settings', $this->validPayload([
            'image_gen_base_url' => 'https://my-provider.test/v1',
            'image_gen_model' => 'custom-model',
            'image_gen_credits_per_image' => 300,
        ]));

        $response->assertRedirect(route('admin.tool-settings.index'));
        $this->assertEquals('https://my-provider.test/v1', SystemSetting::getImageGenBaseUrl());
        $this->assertEquals('custom-model', SystemSetting::getImageGenModel());
        $this->assertEquals(300, SystemSetting::getImageGenCreditsPerImage());
    }

    public function test_update_only_changes_api_key_when_a_new_one_is_provided(): void
    {
        SystemSetting::setImageGenApiKey('sk-original');

        $this->actingAsAdmin()->post('/admin/tool-settings', $this->validPayload([
            'image_gen_api_key' => '',
        ]));

        $this->assertEquals('sk-original', SystemSetting::getImageGenApiKey());

        $this->actingAsAdmin()->post('/admin/tool-settings', $this->validPayload([
            'image_gen_api_key' => 'sk-new-key',
        ]));

        $this->assertEquals('sk-new-key', SystemSetting::getImageGenApiKey());
    }

    public function test_update_saves_ai_text_settings(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/tool-settings', $this->validPayload([
            'ai_text_base_url' => 'https://my-ai-text-provider.test/v1',
            'ai_text_model' => 'some/model',
            'ai_text_api_key' => 'sk-ai-text-key',
        ]));

        $response->assertRedirect(route('admin.tool-settings.index'));
        $this->assertEquals('https://my-ai-text-provider.test/v1', SystemSetting::getAiTextBaseUrl());
        $this->assertEquals('some/model', SystemSetting::getAiTextModel());
        $this->assertEquals('sk-ai-text-key', SystemSetting::getAiTextApiKey());
    }

    public function test_update_saves_genmax_api_key(): void
    {
        $this->actingAsAdmin()->post('/admin/tool-settings', $this->validPayload([
            'genmax_api_key' => 'sk-genmax-key',
        ]));

        $this->assertEquals('sk-genmax-key', SystemSetting::getGenMaxApiKey());
    }

    public function test_update_validates_required_fields(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/tool-settings', ['image_gen_base_url' => 'not-a-url'])
            ->assertSessionHasErrors([
                'image_gen_base_url', 'image_gen_model', 'image_gen_credits_per_image',
                'ai_text_base_url', 'ai_text_model',
            ]);
    }

    public function test_index_and_update_reject_unauthenticated_requests(): void
    {
        $this->get('/admin/tool-settings')->assertRedirect();
        $this->post('/admin/tool-settings', [])->assertRedirect();
    }
}
