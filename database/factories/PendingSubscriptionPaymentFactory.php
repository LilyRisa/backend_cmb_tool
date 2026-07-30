<?php

namespace Database\Factories;

use App\Models\PendingSubscriptionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PendingSubscriptionPaymentFactory extends Factory
{
    protected $model = PendingSubscriptionPayment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => 'monthly',
            'amount' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
            'transaction_code' => 'CMBSUB' . Str::random(10),
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ];
    }
}
