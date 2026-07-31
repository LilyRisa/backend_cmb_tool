<?php

namespace Database\Factories;

use App\Models\SrtGenerateJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SrtGenerateJobFactory extends Factory
{
    protected $model = SrtGenerateJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_filename' => 'audio.mp3',
            'status' => 'queued',
            'stage' => 'queued',
        ];
    }
}
