<?php

namespace Database\Factories;

use App\Models\FeatureCreditUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeatureCreditUsageFactory extends Factory
{
    protected $model = FeatureCreditUsage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature' => 'create_video_script',
            'duration_seconds' => 60,
            'credits' => 140,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ];
    }
}
