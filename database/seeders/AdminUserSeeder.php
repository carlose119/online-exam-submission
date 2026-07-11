<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD', 'password');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
                'role' => 'ADMIN',
            ]
        );

        if ($user->wasRecentlyCreated) {
            $this->command->info("Admin user created: {$email}");
            if (app()->environment('local')) {
                $this->command->warn("  Password: {$password}  (change it in production!)");
            }
        } else {
            $this->command->info("Admin user already exists: {$email}");
        }
    }
}
