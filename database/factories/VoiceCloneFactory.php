<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VoiceClone;
use Illuminate\Database\Eloquent\Factories\Factory;

class VoiceCloneFactory extends Factory
{
    protected $model = VoiceClone::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_voice_id' => 'voice_' . $this->faker->uuid(),
            'voice_name' => $this->faker->words(2, true),
        ];
    }
}
