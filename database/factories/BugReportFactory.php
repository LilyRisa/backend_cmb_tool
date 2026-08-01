<?php

namespace Database\Factories;

use App\Models\BugReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BugReportFactory extends Factory
{
    protected $model = BugReport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'description' => $this->faker->sentence(),
            'screenshots' => null,
            'screenshot_count' => 0,
            'app_version' => '4.2.1',
            'status' => 'pending',
        ];
    }
}
