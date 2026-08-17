<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function index()
    {
        return view('auth.app', [
            'config' => [
                'supabase_url' => config('services.supabase.url'),
                'supabase_anon_key' => config('services.supabase.anon_key'),
            ]
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        $response = Http::withHeaders([
            'apikey'        => config('services.supabase.service_role_key'),
            'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
            'Content-Type'  => 'application/json',
        ])->post(config('services.supabase.url') . '/auth/v1/admin/users', [
            'email'            => strtolower(trim($request->email)),
            'password'         => $request->password,
            'email_confirm'    => true,
            'user_metadata'    => $request->except(['email', 'password']),
        ]);

        if (!$response->successful()) {
            Log::error('Supabase register failed', ['body' => $response->body()]);
            $error = $response->json();
            return response()->json([
                'message' => $error['msg'] ?? $error['message'] ?? 'Registration failed.',
            ], $response->status());
        }

        return response()->json([
            'message' => 'Registration successful.',
            'user'    => $response->json(),
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $response = Http::withHeaders([
            'apikey'       => config('services.supabase.anon_key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.supabase.url') . '/auth/v1/token?grant_type=password', [
            'email'    => strtolower(trim($request->email)),
            'password' => $request->password,
        ]);

        if (!$response->successful()) {
            $error = $response->json();
            return response()->json([
                'message' => $error['error_description'] ?? $error['message'] ?? 'Invalid credentials.',
            ], 401);
        }

        return response()->json($response->json());
    }

    public function user(Request $request)
    {
        $token = $request->bearerToken();

        $response = Http::withHeaders([
            'apikey'        => config('services.supabase.anon_key'),
            'Authorization' => 'Bearer ' . $token,
        ])->get(config('services.supabase.url') . '/auth/v1/user');

        if (!$response->successful()) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json($response->json());
    }

    public function logout(Request $request)
    {
        $token = $request->bearerToken();

        Http::withHeaders([
            'apikey'        => config('services.supabase.anon_key'),
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ])->post(config('services.supabase.url') . '/auth/v1/logout');

        return response()->json(['message' => 'Logged out successfully.']);
    }
}