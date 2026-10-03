<?php

namespace App\Services;

use App\Models\Profile;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues and resolves API sessions (Sanctum personal access tokens) for
 * profiles. The session payload keeps the field names the frontend already
 * uses (access_token, expires_at, user.app_metadata.provider, ...).
 */
class AuthSession
{
    public const TTL_DAYS = 30;

    /**
     * @param  string|null  $role  The role this session signs in as; null
     *                             keeps the account's current role when valid.
     * @return array<string, mixed>
     */
    public function issue(Profile $profile, string $device = 'web', ?string $role = null): array
    {
        $roles = $profile->availableRoles();
        $role = in_array($role, $roles, true) ? $role
            : (in_array($profile->role, $roles, true) ? $profile->role : $roles[0]);

        $expiresAt = now()->addDays(self::TTL_DAYS);
        $newToken = $profile->createToken($device, ['*'], $expiresAt);
        $newToken->accessToken->forceFill(['active_role' => $role])->save();
        $token = $newToken->plainTextToken;

        $this->applyRole($profile, $role);

        return [
            'access_token' => $token,
            'refresh_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => self::TTL_DAYS * 86400,
            'expires_at' => $expiresAt->getTimestamp(),
            'user' => $this->user($profile),
        ];
    }

    /** @return array<string, mixed> */
    public function user(Profile $profile): array
    {
        return [
            'id' => $profile->id,
            'email' => $profile->email,
            'email_confirmed_at' => $profile->email_verified_at?->toIso8601String(),
            // Top-level copies read by the mobile app.
            'role' => $profile->role,
            'roles' => $profile->availableRoles(),
            'name' => $profile->full_name,
            'app_metadata' => ['provider' => $profile->auth_provider ?: 'email'],
            'user_metadata' => [
                'role' => $profile->role,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'middle_initial' => $profile->middle_initial,
                'status' => $profile->status,
            ],
        ];
    }

    /** The profile a bearer token belongs to, or null if unknown/expired. */
    public function resolve(?string $bearerToken): ?Profile
    {
        if (! $bearerToken) {
            return null;
        }

        $token = PersonalAccessToken::findToken($bearerToken);

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        $profile = $token->tokenable;

        if (! $profile instanceof Profile) {
            return null;
        }

        // Only differs for a buyer/seller account whose other session switched
        // profiles.role; re-checked so a revoked seller role isn't kept.
        if ($token->active_role && $token->active_role !== $profile->role
            && in_array($token->active_role, $profile->availableRoles(), true)) {
            $this->applyRole($profile, $token->active_role);
        }

        // Lets currentAccessToken() work (e.g. logout revokes just this token).
        return $profile->withAccessToken($token);
    }

    /**
     * Makes $role the profile's role for this request only, so every role
     * gate reads the session's role. Synced as original so a save() never
     * writes it back to profiles.role.
     */
    public function applyRole(Profile $profile, string $role): void
    {
        $profile->setAttribute('role', $role);
        $profile->syncOriginalAttribute('role');
    }

    public function revoke(?string $bearerToken): void
    {
        if ($bearerToken) {
            PersonalAccessToken::findToken($bearerToken)?->delete();
        }
    }
}
