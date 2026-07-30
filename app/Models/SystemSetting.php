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
