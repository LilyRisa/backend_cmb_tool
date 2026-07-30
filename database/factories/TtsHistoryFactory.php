<?php

namespace Database\Factories;

use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TtsHistoryFactory extends Factory
{
    protected $model = TtsHistory::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'genmax_task_id' => 'task_' . $this->faker->uuid(),
            'provider' => 'elevenlabs',
            'voice_id' => 'voice_abc123',
            'text' => 'Hello world, this is a test.',
            'status' => 'pending',
            'credits_deducted_user' => 10,
        ];
    }
}
