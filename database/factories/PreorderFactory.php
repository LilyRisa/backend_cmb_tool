<?php

namespace Database\Factories;

use App\Models\Preorder;
use Illuminate\Database\Eloquent\Factories\Factory;

class PreorderFactory extends Factory
{
    protected $model = Preorder::class;

    public function definition(): array
    {
        return [
            'fullname' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'product_version' => 'cmb_core',
            'early_access' => false,
            'status' => 'pending',
        ];
    }
}
