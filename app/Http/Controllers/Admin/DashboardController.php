<?php
// app/Http/Controllers/Admin/DashboardController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    /**
     * Get admin dashboard stats
     */
    public function stats()
    {
        // Since you're using Supabase directly from frontend,
        // this is just a placeholder for future API calls
        return response()->json([
            'total_users' => 0,
            'active_sellers' => 0,
            'pending_registrations' => 0,
            'open_complaints' => 0,
        ]);
    }

    /**
     * Get notifications
     */
    public function notifications()
    {
        return response()->json([
            [
                'text' => 'Welcome to the admin panel!',
                'time' => 'Just now'
            ]
        ]);
    }

    /**
     * Get registrations
     */
    public function registrations(Request $request)
    {
        // Your Supabase queries are in the frontend,
        // so this is just a placeholder
        return response()->json([]);
    }

    /**
     * Get accounts
     */
    public function accounts(Request $request)
    {
        return response()->json([]);
    }

    /**
     * Approve user
     */
    public function approve($id)
    {
        return response()->json(['message' => 'User approved']);
    }

    /**
     * Reject user
     */
    public function reject(Request $request, $id)
    {
        return response()->json(['message' => 'User rejected']);
    }

    /**
     * Update user status
     */
    public function updateStatus(Request $request, $id)
    {
        return response()->json(['message' => 'Status updated']);
    }
}