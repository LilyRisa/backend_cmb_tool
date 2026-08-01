<?php

namespace Database\Factories;

use App\Models\FeatureUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeatureUsageFactory extends Factory
{
    protected $model = FeatureUsage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature_name' => $this->faker->randomElement(['video-dub', 'video-creator', 'translate-srt', 'tiktok-search']),
            'usage_count' => 1,
            'last_used_at' => now(),
        ];
    }
}
