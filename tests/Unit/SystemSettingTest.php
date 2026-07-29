<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_and_get_plain_value(): void
    {
        SystemSetting::setValue('chars_per_minute', 800);

        $this->assertEquals(800, SystemSetting::getValue('chars_per_minute'));
    }

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertEquals(42, SystemSetting::getValue('nonexistent_key', 42));
    }

    public function test_encrypted_value_is_decrypted_on_read(): void
    {
        SystemSetting::setValue('secret_api_key', 'super-secret', true);

        $this->assertEquals('super-secret', SystemSetting::getValue('secret_api_key'));
        $this->assertDatabaseMissing('system_settings', ['value' => 'super-secret']);
    }

    public function test_get_premium_monthly_credits_defaults_to_5000(): void
    {
        $this->assertEquals(5000, SystemSetting::getValue('premium_monthly_credits', 5000));
    }
}
