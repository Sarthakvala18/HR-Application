<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionPersistenceProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_session_survives_consecutive_requests(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->get('/admin')->assertSuccessful();
        $this->get('/admin')->assertSuccessful();
        $this->get('/admin/employees')->assertSuccessful();
    }

    /**
     * Re-seeding must not change an existing user's password hash: Laravel's
     * AuthenticateSession middleware compares the session's stored hash against
     * the current one, so a rehash silently signs everyone out.
     */
    public function test_reseeding_does_not_change_an_existing_password_hash(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => UserRole::SuperAdmin,
        ]);

        $originalHash = $user->password;

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            $originalHash,
            $user->fresh()->password,
            'Re-seeding rehashed the password, which would log out every signed-in user',
        );
    }
}
