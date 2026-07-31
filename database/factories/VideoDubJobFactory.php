<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoDubJobFactory extends Factory
{
    protected $model = VideoDubJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'target_language' => 'vi',
            'voice_id' => 'voice_test',
            'provider' => 'elevenlabs',
            'status' => 'queued',
            'stage' => 'queued',
        ];
    }
}
