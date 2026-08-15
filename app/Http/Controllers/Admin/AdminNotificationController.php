<?php
// app/Http/Controllers/Admin/AdminNotificationController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationApproved;
use App\Mail\RegistrationRejected;
use App\Mail\AccountStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminNotificationController extends Controller
{
    public function notifyApproval(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string',
            'user_id' => 'nullable|string'
        ]);

        try {
            Mail::to($request->email)->send(new RegistrationApproved($request->name));
            return response()->json(['message' => 'Approval email sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function notifyRejection(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string',
            'reason' => 'required|string'
        ]);

        try {
            Mail::to($request->email)->send(new RegistrationRejected($request->name, $request->reason));
            return response()->json(['message' => 'Rejection email sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function notifyStatusChange(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string',
            'status' => 'required|string'
        ]);

        try {
            Mail::to($request->email)->send(new AccountStatusChanged($request->name, $request->status));
            return response()->json(['message' => 'Status change email sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}