<?php

namespace Database\Factories;

use App\Models\PendingCreditTopup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PendingCreditTopupFactory extends Factory
{
    protected $model = PendingCreditTopup::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'package_id' => 'starter',
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB' . Str::random(10),
            'status' => PendingCreditTopup::STATUS_PENDING,
        ];
    }
}
