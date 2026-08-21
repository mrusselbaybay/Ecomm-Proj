<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffAccountRequest;
use App\Mail\AccountCreated;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class StaffAccountController extends Controller
{
    public function store(StoreStaffAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = strtolower(trim($data['email']));
        $userId = null;

        try {
            $response = $this->supabaseRequest()
                ->post($this->supabaseUrl('/auth/v1/admin/users'), [
                    'email' => $email,
                    'password' => $data['password'],
                    'email_confirm' => true,
                    'user_metadata' => [
                        'role' => 'admin',
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'middle_initial' => $data['middle_initial'] ?? '',
                        'status' => 'approved',
                        'account_status' => 'active',
                    ],
                ]);

            $this->throwIfFailed($response, 'Unable to create the Supabase user.');

            $userId = $response->json('id');

            if (! is_string($userId) || $userId === '') {
                throw new RuntimeException('Supabase did not return a user ID.');
            }

            $profileResponse = $this->supabaseRequest()
                ->withHeader('Prefer', 'return=representation')
                ->patch($this->supabaseUrl("/rest/v1/profiles?id=eq.{$userId}"), [
                    'role' => 'admin',
                    'status' => 'approved',
                    'account_status' => 'active',
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'middle_initial' => $data['middle_initial'] ?? null,
                    'email' => $email,
                ]);

            $this->throwIfFailed($profileResponse, 'Unable to activate the staff profile.');

            if ($profileResponse->json() === []) {
                throw new RuntimeException('The staff profile was not created by the Supabase trigger.');
            }

            Mail::to($email)->queue(new AccountCreated(
                trim("{$data['first_name']} {$data['last_name']}"),
                $email,
                $data['password'],
                'admin',
            ));

            return response()->json([
                'message' => 'Staff account created. Login credentials were queued for email delivery.',
                'user_id' => $userId,
            ], 201);
        } catch (Throwable $exception) {
            if ($userId) {
                $this->deleteSupabaseUser($userId);
            }

            Log::error('Admin staff account creation failed.', [
                'email' => $email,
                'role' => 'admin',
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $this->safeErrorMessage($exception),
            ], 422);
        }
    }

    private function supabaseRequest(): PendingRequest
    {
        $serviceRoleKey = config('services.supabase.service_role_key');

        if (! is_string($serviceRoleKey) || $serviceRoleKey === '') {
            throw new RuntimeException('Supabase service-role credentials are not configured.');
        }

        return Http::withHeaders([
            'apikey' => $serviceRoleKey,
            'Authorization' => "Bearer {$serviceRoleKey}",
            'Content-Type' => 'application/json',
        ]);
    }

    private function supabaseUrl(string $path): string
    {
        return rtrim((string) config('services.supabase.url'), '/').$path;
    }

    private function throwIfFailed(Response $response, string $fallback): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            $response->json('msg')
                ?? $response->json('message')
                ?? $fallback,
        );
    }

    private function deleteSupabaseUser(string $userId): void
    {
        try {
            $this->supabaseRequest()
                ->delete($this->supabaseUrl("/auth/v1/admin/users/{$userId}"));
        } catch (Throwable $exception) {
            Log::error('Failed to roll back staff Supabase user.', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeErrorMessage(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'already') || str_contains($message, 'registered')) {
            return 'That email address is already registered.';
        }

        return 'Unable to create the staff account. Check the server logs for details.';
    }
}
