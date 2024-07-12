<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create test user if it doesn't exist
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        // Run other seeders
        $this->call([
            RolesAndPermissionsSeeder::class,
            TeamsTableSeeder::class,
            UsersTableSeeder::class,
            SubscriptionsTableSeeder::class,
        ]);
    }
}
