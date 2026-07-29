<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {email} {password} {name=Admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new admin user, or promote an existing user with the given email to admin';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $name = $this->argument('name');

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->is_admin = true;
            $user->save();

            $this->info("Existing user {$email} promoted to admin.");

            return self::SUCCESS;
        }

        $newUser = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
            'package_type' => 'premium',
        ]);

        // email_verified_at is intentionally not mass-assignable on the User
        // model (not in $fillable), so it must be set separately.
        $newUser->forceFill(['email_verified_at' => now()])->save();

        $this->info("Admin user {$email} created.");

        return self::SUCCESS;
    }
}
