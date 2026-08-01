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

    public function test_index_shows_current_settings_without_leaking_the_raw_api_key(): void
    {
        SystemSetting::setImageGenApiKey('sk-super-secret');
        SystemSetting::setImageGenModel('dall-e-3');

        $response = $this->actingAsAdmin()->get('/admin/tool-settings');

        $response->assertOk();
        $response->assertViewHas('settings', function ($settings) {
            return $settings['image_gen_model'] === 'dall-e-3' && $settings['image_gen_api_key_set'] === true;
        });
        $response->assertDontSee('sk-super-secret');
    }

    public function test_index_shows_defaults_when_nothing_configured(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/tool-settings');

        $response->assertOk();
        $response->assertViewHas('settings', function ($settings) {
            return $settings['image_gen_base_url'] === 'https://api.openai.com/v1'
                && $settings['image_gen_credits_per_image'] === 200
                && $settings['image_gen_api_key_set'] === false;
        });
    }

    public function test_update_saves_new_settings(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/tool-settings', [
            'image_gen_base_url' => 'https://my-provider.test/v1',
            'image_gen_model' => 'custom-model',
            'image_gen_credits_per_image' => 300,
        ]);

        $response->assertRedirect(route('admin.tool-settings.index'));
        $this->assertEquals('https://my-provider.test/v1', SystemSetting::getImageGenBaseUrl());
        $this->assertEquals('custom-model', SystemSetting::getImageGenModel());
        $this->assertEquals(300, SystemSetting::getImageGenCreditsPerImage());
    }

    public function test_update_only_changes_api_key_when_a_new_one_is_provided(): void
    {
        SystemSetting::setImageGenApiKey('sk-original');

        $this->actingAsAdmin()->post('/admin/tool-settings', [
            'image_gen_base_url' => 'https://api.openai.com/v1',
            'image_gen_model' => 'gpt-image-1',
            'image_gen_credits_per_image' => 200,
            'image_gen_api_key' => '',
        ]);

        $this->assertEquals('sk-original', SystemSetting::getImageGenApiKey());

        $this->actingAsAdmin()->post('/admin/tool-settings', [
            'image_gen_base_url' => 'https://api.openai.com/v1',
            'image_gen_model' => 'gpt-image-1',
            'image_gen_credits_per_image' => 200,
            'image_gen_api_key' => 'sk-new-key',
        ]);

        $this->assertEquals('sk-new-key', SystemSetting::getImageGenApiKey());
    }

    public function test_update_validates_required_fields(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/tool-settings', ['image_gen_base_url' => 'not-a-url'])
            ->assertSessionHasErrors(['image_gen_base_url', 'image_gen_model', 'image_gen_credits_per_image']);
    }

    public function test_index_and_update_reject_unauthenticated_requests(): void
    {
        $this->get('/admin/tool-settings')->assertRedirect();
        $this->post('/admin/tool-settings', [])->assertRedirect();
    }
}
