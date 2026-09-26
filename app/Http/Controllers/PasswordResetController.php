<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\PasswordResetCodeMail;
use App\Mail\SignupVerificationCodeMail;
use App\Models\PasswordResetCode;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordResetController extends Controller
{
    /**
     * How long a code stays valid. Exposed to callers via
     * expires_in_seconds so the frontend countdown (e.g. the admin
     * account settings "Change Password" modal) reflects the real TTL
     * instead of a hardcoded guess.
     */
    private const CODE_TTL_MINUTES = 15;

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
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
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
            'expires_in_seconds' => self::CODE_TTL_MINUTES * 60,
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
        Mail::to($email)->send(new SignupVerificationCodeMail($code));
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

        $profile = Profile::where('email', $email)->first();

        if (!$profile) {
            return response()->json([
                'message' => 'User not found.'
            ], 422);
        }

        DB::table('profiles')->where('id', $profile->id)->update([
            'password' => Hash::make($request->input('password')),
            'updated_at' => now(),
        ]);

        // A reset invalidates every existing session.
        $profile->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully! You can now log in.',
        ]);
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
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
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
            'expires_in_seconds' => self::CODE_TTL_MINUTES * 60,
        ]);
    }

    /**
     * Check if an account exists for this email
     */
    private function checkUserExists($email)
    {
        return Profile::where('email', $email)->exists();
    }
}