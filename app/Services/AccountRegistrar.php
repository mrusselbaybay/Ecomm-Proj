<?php

namespace App\Services;

use App\Models\Profile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates and removes email/password login accounts (profiles rows with a
 * bcrypt password). Replaces the Supabase Auth admin API.
 */
class AccountRegistrar
{
    public function __construct(private readonly ProfileProvisioner $profiles) {}

    /**
     * @param  array<string, mixed>  $metadata   role/name/etc. (same keys the signup forms send)
     * @param  array<string, mixed>  $overrides  explicit profile columns (e.g. approved staff)
     * @return array{id: string}
     *
     * @throws \RuntimeException when the email is already registered
     */
    public function createUser(string $email, string $password, array $metadata, array $overrides = []): array
    {
        $email = strtolower(trim($email));

        if (Profile::where('email', $email)->exists()) {
            throw new \RuntimeException('A user with this email address has already been registered');
        }

        $id = (string) Str::uuid();

        $this->profiles->provision($id, $email, $metadata, [
            'password' => Hash::make($password),
            'auth_provider' => 'email',
            'email_verified_at' => now(),
            ...$overrides,
        ]);

        return ['id' => $id];
    }

    /** Removes the account and (via FK cascades) its addresses/details/documents. */
    public function deleteUser(string $userId): void
    {
        $this->profiles->remove($userId);
    }
}
