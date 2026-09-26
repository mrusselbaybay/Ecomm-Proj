<?php

namespace Tests\Feature\Auth;

use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PreventAdminSelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The core bug: role was forwarded unfiltered, so `role=admin` in the
     * request body created a real admin profile.
     */
    public function test_register_rejects_admin_role_with_validation_error(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'wannabe-admin@example.com',
            'password' => 'password123',
            'role' => 'admin',
            'first_name' => 'Would',
            'last_name' => 'BeAdmin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('profiles', ['email' => 'wannabe-admin@example.com']);
    }

    public function test_register_rejects_roles_outside_the_registrable_whitelist(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'staff@example.com',
            'password' => 'password123',
            'role' => 'staff', // not buyer/seller/courier
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('profiles', ['email' => 'staff@example.com']);
    }

    /**
     * Even without a `role` field, extra request keys (e.g. trying to sneak
     * in `status` or `account_status`) must not reach the profile.
     */
    public function test_register_ignores_unexpected_fields_in_metadata(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'buyer@example.com',
            'password' => 'password123',
            'role' => 'buyer',
            'status' => 'approved',        // attempted injection
            'account_status' => 'active',  // attempted injection
        ])->assertStatus(201);

        $profile = Profile::where('email', 'buyer@example.com')->firstOrFail();

        $this->assertSame('buyer', $profile->role);
        $this->assertSame('pending', $profile->status); // server-controlled
        $this->assertNotSame('active', $profile->account_status);
    }

    /**
     * A legitimate buyer/seller/courier registration must keep working.
     */
    public function test_register_still_allows_valid_registrable_roles(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'seller@example.com',
            'password' => 'password123',
            'role' => 'seller',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ])->assertStatus(201);

        $profile = Profile::where('email', 'seller@example.com')->firstOrFail();

        $this->assertSame('seller', $profile->role);
        $this->assertTrue(Hash::check('password123', $profile->password));
    }

    /**
     * Direct-route bypass check: hitting the endpoint with no `role` at all
     * must default to the safe 'buyer' role, never 'admin'.
     */
    public function test_register_defaults_missing_role_to_buyer(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'noroletest@example.com',
            'password' => 'password123',
        ])->assertStatus(201);

        $this->assertSame('buyer', Profile::where('email', 'noroletest@example.com')->value('role'));
    }
}
