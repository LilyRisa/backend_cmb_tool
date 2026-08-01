<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSettingImageGenTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_url_defaults_to_openai_when_never_set(): void
    {
        $this->assertEquals('https://api.openai.com/v1', SystemSetting::getImageGenBaseUrl());
    }

    public function test_base_url_can_be_overridden(): void
    {
        SystemSetting::setImageGenBaseUrl('https://my-provider.test/v1');

        $this->assertEquals('https://my-provider.test/v1', SystemSetting::getImageGenBaseUrl());
    }

    public function test_api_key_defaults_to_null_and_is_encrypted_at_rest(): void
    {
        $this->assertNull(SystemSetting::getImageGenApiKey());

        SystemSetting::setImageGenApiKey('sk-secret-123');

        $this->assertEquals('sk-secret-123', SystemSetting::getImageGenApiKey());
        $raw = SystemSetting::where('key', 'image_gen_api_key')->first();
        $this->assertNotEquals('sk-secret-123', $raw->value);
        $this->assertTrue($raw->is_encrypted);
    }

    public function test_model_defaults_to_gpt_image_1(): void
    {
        $this->assertEquals('gpt-image-1', SystemSetting::getImageGenModel());

        SystemSetting::setImageGenModel('dall-e-3');

        $this->assertEquals('dall-e-3', SystemSetting::getImageGenModel());
    }

    public function test_credits_per_image_defaults_to_200_and_is_admin_configurable(): void
    {
        $this->assertEquals(200, SystemSetting::getImageGenCreditsPerImage());

        SystemSetting::setImageGenCreditsPerImage(350);

        $this->assertEquals(350, SystemSetting::getImageGenCreditsPerImage());
    }
}
