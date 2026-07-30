<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => Subscription::PLAN_MONTHLY,
            'status' => Subscription::STATUS_ACTIVE,
            'amount' => 99000,
            'payment_method' => 'sepay',
            'transaction_id' => null,
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }
}
