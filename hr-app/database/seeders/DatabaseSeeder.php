<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            AppSeeder::class,
            RoleTemplateSeeder::class,
        ]);

        // Local bootstrap account only. Production sign-in is Google OAuth.
        //
        // firstOrCreate rather than updateOrCreate: re-hashing the password on
        // every seed run changes the stored hash, which makes Laravel's
        // AuthenticateSession middleware log out everyone who is currently
        // signed in. Re-seeding should not kick people out.
        if (app()->environment('local')) {
            User::firstOrCreate(
                ['email' => 'admin@example.com'],
                [
                    'name' => 'HR Admin',
                    'password' => 'password',
                    'role' => UserRole::SuperAdmin,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
