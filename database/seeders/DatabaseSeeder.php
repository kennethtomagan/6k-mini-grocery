<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the default admin and cashier accounts.
     *
     * The credentials are create-only: existing users are never modified, so re-running
     * the seeder is safe. The default password must be changed after the first login.
     * Passwords are hashed by the User model's `hashed` cast.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@k6minigrocery.com'],
            ['name' => 'Store Owner', 'password' => 'password', 'role' => UserRole::Admin, 'is_active' => true],
        );

        User::query()->firstOrCreate(
            ['email' => 'cashier@k6minigrocery.com'],
            ['name' => 'Cashier', 'password' => 'password', 'role' => UserRole::Cashier, 'is_active' => true],
        );
    }
}
