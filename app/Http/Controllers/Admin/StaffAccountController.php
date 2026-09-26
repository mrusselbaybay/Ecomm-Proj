<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffAccountRequest;
use App\Mail\AccountCreated;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use App\Services\AccountRegistrar;

class StaffAccountController extends Controller
{
    public function store(StoreStaffAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = strtolower(trim($data['email']));
        // Staff get the same admin role as their creator.
        $role = $request->user()->role;
        $userId = null;

        try {
            $userId = app(AccountRegistrar::class)->createUser($email, $data['password'], ['role' => $role], [
                'status' => 'approved',
                'account_status' => 'active',
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_initial' => $data['middle_initial'] ?? null,
            ])['id'];

            Mail::to($email)->queue(new AccountCreated(
                trim("{$data['first_name']} {$data['last_name']}"),
                $email,
                $data['password'],
                $role,
            ));

            return response()->json([
                'message' => 'Staff account created. Login credentials were queued for email delivery.',
                'user_id' => $userId,
            ], 201);
        } catch (Throwable $exception) {
            if ($userId) {
                $this->deleteAccount($userId);
            }

            Log::error('Admin staff account creation failed.', [
                'email' => $email,
                'role' => $role,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $this->safeErrorMessage($exception),
            ], 422);
        }
    }




    private function deleteAccount(string $userId): void
    {
        app(AccountRegistrar::class)->deleteUser($userId);
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
