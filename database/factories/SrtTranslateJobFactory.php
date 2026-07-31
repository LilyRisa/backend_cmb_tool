<?php

namespace Database\Factories;

use App\Models\SrtTranslateJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SrtTranslateJobFactory extends Factory
{
    protected $model = SrtTranslateJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'target_language' => 'vi',
            'status' => 'queued',
            'stage' => 'queued',
        ];
    }
}
