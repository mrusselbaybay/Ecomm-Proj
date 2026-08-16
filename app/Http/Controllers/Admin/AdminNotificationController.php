<?php
// app/Http/Controllers/Admin/AdminNotificationController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationApproved;
use App\Mail\RegistrationRejected;
use App\Mail\AccountStatusChanged;
use App\Mail\AccountCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class AdminNotificationController extends Controller
{
    /**
     * Send approval notification
     */
    public function notifyApproval(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'name' => 'required|string',
                'user_id' => 'nullable|string'
            ]);

            Mail::to($request->email)->send(new RegistrationApproved($request->name));
            
            Log::info('Approval email sent to: ' . $request->email);
            
            return response()->json([
                'success' => true,
                'message' => 'Approval email sent successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send approval email: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send rejection notification
     */
    public function notifyRejection(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'name' => 'required|string',
                'reason' => 'required|string'
            ]);

            Mail::to($request->email)->send(new RegistrationRejected($request->name, $request->reason));
            
            Log::info('Rejection email sent to: ' . $request->email);
            
            return response()->json([
                'success' => true,
                'message' => 'Rejection email sent successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send rejection email: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send account status change notification
     * ✅ UPDATED: Now accepts 'reason' parameter
     */
    public function notifyStatusChange(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'name' => 'required|string',
                'status' => 'required|string|in:active,suspended,deactivated',
                'reason' => 'nullable|string'  // ✅ Added reason field
            ]);

            Mail::to($request->email)->send(new AccountStatusChanged(
                $request->name, 
                $request->status,
                $request->reason ?? null  // ✅ Pass reason to mail class
            ));
            
            Log::info('Status change email sent to: ' . $request->email . ' Status: ' . $request->status);
            
            return response()->json([
                'success' => true,
                'message' => 'Status change email sent successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send status change email: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send account created notification for staff
     */
    public function notifyAccountCreated(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'name' => 'required|string',
                'password' => 'required|string',
                'role' => 'required|string'
            ]);

            Mail::to($request->email)->send(new AccountCreated(
                $request->name,
                $request->email,
                $request->password,
                $request->role
            ));
            
            Log::info('Account created email sent to: ' . $request->email);
            
            return response()->json([
                'success' => true,
                'message' => 'Account created email sent successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send account created email: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}