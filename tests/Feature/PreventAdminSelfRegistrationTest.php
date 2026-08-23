<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreventAdminSelfRegistrationTest extends TestCase
{
    /**
     * The core bug: role was forwarded unfiltered to Supabase user_metadata,
     * so `role=admin` in the request body created a real admin profile.
     */
    public function test_register_rejects_admin_role_with_validation_error(): void
    {
        Http::fake(); // if any Supabase call is made, we can assert it wasn't

        $response = $this->postJson('/api/auth/register', [
            'email'    => 'wannabe-admin@example.com',
            'password' => 'password123',
            'role'     => 'admin',
            'first_name' => 'Would',
            'last_name'  => 'BeAdmin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('role');

        // The Supabase admin-user-creation call must never have fired.
        Http::assertNothingSent();
    }

    public function test_register_rejects_roles_outside_the_registrable_whitelist(): void
    {
        Http::fake();

        $response = $this->postJson('/api/auth/register', [
            'email'    => 'staff@example.com',
            'password' => 'password123',
            'role'     => 'staff', // not buyer/seller/courier
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('role');
        Http::assertNothingSent();
    }

    /**
     * Even without a `role` field, extra request keys (e.g. trying to sneak
     * in `status` or `account_status`) must not leak into user_metadata.
     */
    public function test_register_ignores_unexpected_fields_in_metadata(): void
    {
        Http::fake([
            '*/auth/v1/admin/users' => Http::response(['id' => 'fake-uuid-123'], 201),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email'          => 'buyer@example.com',
            'password'       => 'password123',
            'role'           => 'buyer',
            'status'         => 'approved',   // attempted injection
            'account_status' => 'active',     // attempted injection
        ]);

        $response->assertStatus(201);

        Http::assertSent(function ($request) {
            $metadata = $request->data()['user_metadata'] ?? [];

            return $metadata['role'] === 'buyer'
                && $metadata['status'] === 'pending' // server-controlled, not client-controlled
                && ! array_key_exists('account_status', $metadata);
        });
    }

    /**
     * A legitimate buyer/seller/courier registration must keep working.
     */
    public function test_register_still_allows_valid_registrable_roles(): void
    {
        Http::fake([
            '*/auth/v1/admin/users' => Http::response(['id' => 'fake-uuid-456'], 201),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email'    => 'seller@example.com',
            'password' => 'password123',
            'role'     => 'seller',
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
        ]);

        $response->assertStatus(201);
        Http::assertSentCount(1);
    }

    /**
     * Direct-route bypass check: hitting the endpoint with no `role` at all
     * must default to the safe 'buyer' role, never 'admin'.
     */
    public function test_register_defaults_missing_role_to_buyer(): void
    {
        Http::fake([
            '*/auth/v1/admin/users' => Http::response(['id' => 'fake-uuid-789'], 201),
        ]);

        $this->postJson('/api/auth/register', [
            'email'    => 'noroletest@example.com',
            'password' => 'password123',
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            return ($request->data()['user_metadata']['role'] ?? null) === 'buyer';
        });
    }
}