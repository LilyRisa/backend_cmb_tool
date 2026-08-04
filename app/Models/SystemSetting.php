<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'is_encrypted', 'description'];

    protected $casts = ['is_encrypted' => 'boolean'];

    public static function getValue(string $key, $default = null)
    {
        return Cache::remember("system_setting.{$key}", 300, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            if (!$setting) return $default;

            return $setting->is_encrypted ? decrypt($setting->value) : $setting->value;
        });
    }

    public static function setValue(string $key, $value, bool $encrypted = false, ?string $description = null): static
    {
        Cache::forget("system_setting.{$key}");

        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypted ? encrypt($value) : $value,
                'is_encrypted' => $encrypted,
                'description' => $description,
            ]
        );
    }

    public static function getCharsPerMinute(): int
    {
        return (int) static::getValue('chars_per_minute', 800);
    }

    public static function getPremiumMonthlyCredits(): int
    {
        return (int) static::getValue('premium_monthly_credits', 5000);
    }

    public static function getAiTextBaseUrl(): string
    {
        return static::getValue('ai_text_base_url', 'https://openrouter.ai/api/v1');
    }

    public static function setAiTextBaseUrl(string $url): static
    {
        return static::setValue('ai_text_base_url', $url, false, 'AI Text Provider Base URL (script/scene/translate)');
    }

    public static function getAiTextModel(): string
    {
        return static::getValue('ai_text_model', '~google/gemini-flash-latest');
    }

    public static function setAiTextModel(string $model): static
    {
        return static::setValue('ai_text_model', $model, false, 'AI Text Provider Model (script/scene/translate)');
    }

    public static function getAiTextApiKey(): ?string
    {
        $key = static::getValue('ai_text_api_key');

        // Falls back to the pre-migration env var so existing deployments keep
        // working until an admin sets this in Admin > Tool Settings.
        return ($key !== null && $key !== '') ? $key : config('services.openrouter.api_key');
    }

    public static function setAiTextApiKey(string $apiKey): static
    {
        return static::setValue('ai_text_api_key', $apiKey, true, 'AI Text Provider API Key (script/scene/translate)');
    }

    public static function getGenMaxApiKey(): ?string
    {
        return static::getValue('genmax_api_key');
    }

    public static function setGenMaxApiKey(string $apiKey): static
    {
        return static::setValue(
            'genmax_api_key',
            $apiKey,
            true,
            'GenMax TTS Provider API Key'
        );
    }

    public static function getImageGenBaseUrl(): string
    {
        return static::getValue('image_gen_base_url', 'https://api.openai.com/v1');
    }

    public static function setImageGenBaseUrl(string $url): static
    {
        return static::setValue('image_gen_base_url', $url, false, 'Image Generation API Base URL');
    }

    public static function getImageGenApiKey(): ?string
    {
        $key = static::getValue('image_gen_api_key');

        return $key === '' ? null : $key;
    }

    public static function setImageGenApiKey(string $apiKey): static
    {
        return static::setValue('image_gen_api_key', $apiKey, true, 'Image Generation API Key');
    }

    public static function getImageGenModel(): string
    {
        return static::getValue('image_gen_model', 'gpt-image-1');
    }

    public static function setImageGenModel(string $model): static
    {
        return static::setValue('image_gen_model', $model, false, 'Image Generation Model');
    }

    public static function getImageGenCreditsPerImage(): int
    {
        return (int) static::getValue('image_gen_credits_per_image', 200);
    }

    public static function setImageGenCreditsPerImage(int $credits): static
    {
        return static::setValue('image_gen_credits_per_image', (string) $credits, false, 'Image Generation Credits Per Image');
    }

    public static function getPremiumPlans(): array
    {
        $raw = static::getValue('premium_plans');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                return array_values($decoded);
            }
        }
        return static::defaultPremiumPlans();
    }

    protected static function defaultPremiumPlans(): array
    {
        return [
            ['id' => 'monthly', 'name' => 'Premium Tháng', 'price' => 99000, 'duration_days' => 30, 'monthly_credits' => 5000],
            ['id' => 'yearly', 'name' => 'Premium Năm', 'price' => 999000, 'duration_days' => 365, 'monthly_credits' => 5000],
        ];
    }
}
