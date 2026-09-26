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

    /** @return array<string, mixed> */
    public function issue(Profile $profile, string $device = 'web'): array
    {
        $expiresAt = now()->addDays(self::TTL_DAYS);
        $token = $profile->createToken($device, ['*'], $expiresAt)->plainTextToken;

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

        // Lets currentAccessToken() work (e.g. logout revokes just this token).
        return $profile->withAccessToken($token);
    }

    public function revoke(?string $bearerToken): void
    {
        if ($bearerToken) {
            PersonalAccessToken::findToken($bearerToken)?->delete();
        }
    }
}
