<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Mail\Logistics\ApplicationAccepted;
use App\Mail\Logistics\ApplicationInterview;
use App\Mail\Logistics\ApplicationRejected;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class LogisticsNotificationController extends Controller
{
    public function applicationAccepted(Request $request)
    {
        $data = $request->validate([
            'email'        => 'required|email',
            'name'         => 'required|string',
            'company_name' => 'required|string',
        ]);

        try {
            Mail::to($data['email'])->send(new ApplicationAccepted(
                $data['name'],
                $data['company_name']
            ));
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Failed to send application-accepted email', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function applicationInterview(Request $request)
    {
        $data = $request->validate([
            'email'        => 'required|email',
            'name'         => 'required|string',
            'company_name' => 'required|string',
            'interview_at' => 'required|date',
            'notes'        => 'nullable|string|max:1000',
        ]);

        try {
            Mail::to($data['email'])->send(new ApplicationInterview(
                $data['name'],
                $data['company_name'],
                $data['interview_at'],
                $data['notes'] ?? null
            ));
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Failed to send application-interview email', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function applicationRejected(Request $request)
    {
        $data = $request->validate([
            'email'        => 'required|email',
            'name'         => 'required|string',
            'company_name' => 'required|string',
            'reason'       => 'nullable|string',
        ]);

        try {
            Mail::to($data['email'])->send(new ApplicationRejected(
                $data['name'],
                $data['company_name'],
                $data['reason'] ?? null
            ));
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Failed to send application-rejected email', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}