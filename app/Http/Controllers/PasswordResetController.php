<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;

class PasswordResetController extends Controller
{
    public function sendCode(Request $request)
    {
        Log::info('Password reset requested', ['email' => $request->input('email')]);

        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->input('email')));

        // Check if user exists in Supabase
        $userExists = $this->checkUserExists($email);

        if (!$userExists) {
            Log::warning('User not found in Supabase', ['email' => $email]);
            return response()->json([
                'message' => 'No account found with this email address.'
            ], 422);
        }

        // Generate 6-digit code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Delete old codes
        PasswordResetCode::where('email', $email)->delete();

        // Save new code
        PasswordResetCode::create([
            'email' => $email,
            'code' => $code,
            'expires_at' => now()->addMinutes(15),
        ]);

        // Send email
        try {
            Mail::to($email)->send(new PasswordResetCodeMail($code));
            Log::info('Password reset code sent', ['email' => $email]);
        } catch (\Exception $e) {
            Log::error('Email send failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to send email. Please try again.'
            ], 500);
        }

        return response()->json([
            'message' => 'Verification code sent to your email.',
        ]);
    }

    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $email = strtolower(trim($request->input('email')));
        $code = $request->input('code');

        // Find the code
        $resetCode = PasswordResetCode::where('email', $email)
            ->where('code', $code)
            ->first();

        if (!$resetCode || !$resetCode->isValid()) {
            return response()->json([
                'message' => 'Invalid or expired code. Please request a new one.'
            ], 422);
        }

        return response()->json([
            'message' => 'Code verified successfully.',
        ]);
    }
    
    public function sendSignupCode(Request $request)
{
    $request->validate(['email' => 'required|email']);
    $email = strtolower(trim($request->input('email')));

    if ($this->checkUserExists($email)) {
        return response()->json([
            'message' => 'An account with this email already exists. Please log in instead.'
        ], 422);
    }

    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    PasswordResetCode::where('email', $email)->delete();
    PasswordResetCode::create([
        'email' => $email,
        'code' => $code,
        'expires_at' => now()->addMinutes(15),
    ]);

    try {
        Mail::to($email)->send(new PasswordResetCodeMail($code));
    } catch (\Exception $e) {
        Log::error('Signup email send failed', ['error' => $e->getMessage()]);
        return response()->json(['message' => 'Failed to send email. Please try again.'], 500);
    }

    return response()->json(['message' => 'Verification code sent to your email.']);
}

public function verifySignupCode(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'code' => 'required|string|size:6',
    ]);

    $email = strtolower(trim($request->input('email')));
    $code = $request->input('code');

    $resetCode = PasswordResetCode::where('email', $email)->where('code', $code)->first();

    if (!$resetCode || !$resetCode->isValid()) {
        return response()->json(['message' => 'Invalid or expired code. Please request a new one.'], 422);
    }

    $resetCode->markAsUsed();

    return response()->json(['message' => 'Email verified successfully.']);
}

public function resendSignupCode(Request $request)
{
    return $this->sendSignupCode($request);
}

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = strtolower(trim($request->input('email')));
        $code = $request->input('code');

        // Find the code
        $resetCode = PasswordResetCode::where('email', $email)
            ->where('code', $code)
            ->first();

        if (!$resetCode || !$resetCode->isValid()) {
            return response()->json([
                'message' => 'Invalid or expired code. Please request a new one.'
            ], 422);
        }

        // Mark as used
        $resetCode->markAsUsed();

        // Get user from Supabase
        $user = $this->findAuthUser($email);

        if (!$user) {
            return response()->json([
                'message' => 'User not found.'
            ], 422);
        }

        // Update password
        try {
            $response = Http::withHeaders([
                'apikey' => config('services.supabase.service_role_key'),
                'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
                'Content-Type' => 'application/json',
            ])->put(
                config('services.supabase.url') . '/auth/v1/admin/users/' . $user['id'],
                [
                    'password' => $request->input('password'),
                ]
            );

            if (!$response->successful()) {
                Log::error('Password update failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json([
                    'message' => 'Failed to update password. Please try again.'
                ], 500);
            }

            return response()->json([
                'message' => 'Password reset successfully! You can now log in.',
            ]);

        } catch (\Exception $e) {
            Log::error('Password reset error', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'An error occurred. Please try again.'
            ], 500);
        }
    }

    public function resendCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->input('email')));

        if (!$this->checkUserExists($email)) {
            return response()->json([
                'message' => 'No account found with this email address.'
            ], 422);
        }

        // Generate new code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordResetCode::where('email', $email)->delete();
        PasswordResetCode::create([
            'email' => $email,
            'code' => $code,
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            Mail::to($email)->send(new PasswordResetCodeMail($code));
        } catch (\Exception $e) {
            Log::error('Email send failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to send email. Please try again.'
            ], 500);
        }

        return response()->json([
            'message' => 'New verification code sent to your email.',
        ]);
    }

    /**
     * Check if a user exists in Supabase
     */
    private function checkUserExists($email)
    {
        try {
            // Method 1: Check profiles table (use service role key to bypass RLS)
            $response = Http::withHeaders([
                'apikey' => config('services.supabase.service_role_key'),
                'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
            ])->get(
                config('services.supabase.url') . '/rest/v1/profiles?email=eq.' . urlencode($email) . '&select=id'
            );

            if ($response->successful()) {
                $data = $response->json();
                if (count($data) > 0) {
                    Log::info('User found in profiles table', ['email' => $email]);
                    return true;
                }
            }

            // Method 2: Check auth.users via admin API
            $user = $this->findAuthUser($email);
            if ($user) {
                Log::info('User found in auth.users', ['email' => $email]);
                return true;
            }

            Log::warning('User not found in Supabase', ['email' => $email]);
            return false;

        } catch (\Exception $e) {
            Log::error('User check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Find a user in Supabase Auth by email (handles pagination)
     */
    private function findAuthUser($email)
    {
        $page = 1;
        $perPage = 1000;

        do {
            try {
                $response = Http::withHeaders([
                    'apikey' => config('services.supabase.service_role_key'),
                    'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
                ])->get(
                    config('services.supabase.url') . '/auth/v1/admin/users',
                    ['page' => $page, 'per_page' => $perPage]
                );

                if (!$response->successful()) {
                    Log::error('Supabase admin users request failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return null;
                }

                $body = $response->json();
                
                // The key fix: response is wrapped in a "users" key
                $users = $body['users'] ?? [];

                foreach ($users as $user) {
                    if (isset($user['email']) && strtolower($user['email']) === $email) {
                        return $user;
                    }
                }

                // Check if there's a next page
                $hasMore = !empty($body['next_page']);
                $page++;

            } catch (\Exception $e) {
                Log::error('findAuthUser error', ['error' => $e->getMessage()]);
                return null;
            }

        } while ($hasMore);

        return null;
    }

    /**
     * Get user ID from Supabase by email
     */
    private function getUserId($email)
    {
        $user = $this->findAuthUser($email);
        return $user['id'] ?? null;
    }
}