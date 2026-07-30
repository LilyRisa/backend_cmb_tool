<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreditTransactionFactory extends Factory
{
    protected $model = CreditTransaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -10,
            'balance_after' => 100,
            'description' => 'Test transaction',
        ];
    }
}
