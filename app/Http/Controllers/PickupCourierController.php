<?php

namespace App\Http\Controllers;

use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PickupCourierController extends Controller
{
    public function __construct(private readonly SupabaseStorageService $supabaseStorage)
    {
    }

    /**
     * Get user ID from the request
     */
    private function getUserId(Request $request)
    {
        // First: Try from the form data (sent from Vue)
        $userId = $request->input('user_id');
        if ($userId) {
            Log::info('User ID from form data:', ['user_id' => $userId]);
            return $userId;
        }
        
        // Second: Try Laravel Auth
        if (Auth::check()) {
            Log::info('User ID from Laravel Auth:', ['user_id' => Auth::id()]);
            return Auth::id();
        }
        
        // Third: Try from the nexmart_session cookie
        $sessionCookie = $request->cookie('nexmart_session');
        if ($sessionCookie) {
            try {
                $userData = json_decode($sessionCookie, true);
                if (isset($userData['id'])) {
                    Log::info('User ID from session cookie:', ['user_id' => $userData['id']]);
                    return $userData['id'];
                }
                // If only email is stored, try to find the user by email
                if (isset($userData['email'])) {
                    $profile = \App\Models\Profile::where('email', $userData['email'])->first();
                    if ($profile) {
                        Log::info('User ID from email lookup:', ['user_id' => $profile->id]);
                        return $profile->id;
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error parsing session cookie: ' . $e->getMessage());
            }
        }
        
        // Fourth: Try to get from Authorization header (Bearer token)
        $token = $request->bearerToken();
        if ($token) {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'apikey' => config('services.supabase.anon_key'),
                    'Authorization' => 'Bearer ' . $token,
                ])->get(config('services.supabase.url') . '/auth/v1/user');
                
                if ($response->successful()) {
                    $user = $response->json();
                    if (isset($user['id'])) {
                        Log::info('User ID from bearer token:', ['user_id' => $user['id']]);
                        return $user['id'];
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error verifying token: ' . $e->getMessage());
            }
        }
        
        Log::warning('Could not find user ID from any source');
        return null;
    }

    /**
     * Get companies for the Vue app
     */
    public function getCompanies(Request $request)
    {
        $userId = $this->getUserId($request);
        
        Log::info('getCompanies - User ID:', ['userId' => $userId]);
        
        $selectedRegion = $request->query('region', 'All Regions');
        $search = trim((string) $request->query('search', ''));

        $query = LogisticsCompany::query()->active();

        if ($selectedRegion !== 'All Regions') {
            $query->where('region', $selectedRegion);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('company_email', 'like', "%{$search}%");
            });
        }

        $companies = $query->orderBy('company_name')->get();

        $regions = LogisticsCompany::query()
            ->whereNotNull('region')
            ->where('region', '!=', '')
            ->distinct()
            ->orderBy('region')
            ->pluck('region');

        $applications = [];
        $appliedCompanyIds = [];
        
        if ($userId) {
            $applications = CourierApplication::with('company')
                ->where('courier_profile_id', $userId)
                ->active()
                ->orderByDesc('applied_at')
                ->get();
                
            $appliedCompanyIds = $applications->pluck('logistics_company_id')->all();
        }

        return response()->json([
            'companies' => $companies,
            'regions' => $regions,
            'applications' => $applications,
            'appliedCompanyIds' => $appliedCompanyIds,
        ]);
    }

    /**
     * Return a short-lived signed URL for the signed-in courier's own resume.
     */
    public function viewResume(Request $request, CourierApplication $application)
    {
        $userId = $this->getUserId($request);

        if (! $userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($application->courier_profile_id !== $userId) {
            return response()->json(['error' => 'Unauthorized - You do not own this application'], 403);
        }

        if (! $application->resume_path) {
            return response()->json(['error' => 'No resume on file for this application.'], 404);
        }

        $url = $this->supabaseStorage->signedUrl($application->resume_path);
        if (! $url) {
            return response()->json(['error' => 'Could not generate a link to your resume right now.'], 502);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Get user's applications only (for refresh)
     */
    public function getApplications(Request $request)
    {
        $userId = $this->getUserId($request);
        
        if (!$userId) {
            return response()->json([]);
        }
        
        $applications = CourierApplication::with('company')
            ->where('courier_profile_id', $userId)
            ->active()
            ->orderByDesc('applied_at')
            ->get();

        return response()->json($applications);
    }

    /**
     * Submit a new application.
     */
    public function apply(Request $request, LogisticsCompany $company)
    {
        // Debug: Log everything
        Log::info('Apply request data:', [
            'all' => $request->all(),
            'files' => $request->allFiles(),
            'cookies' => $request->cookies->all(),
            'user_id_input' => $request->input('user_id'),
        ]);
        
        // Get user ID from request
        $userId = $this->getUserId($request);
        
        Log::info('apply - User ID:', ['userId' => $userId]);
        
        if (!$userId) {
            return response()->json(['error' => 'Unauthorized - User not found'], 401);
        }

        // Validate
        $request->validate([
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'cover_note' => ['nullable', 'string', 'max:2000'],
        ]);

        // Check if user already has an active application
        $hasActiveApplication = CourierApplication::where('courier_profile_id', $userId)
            ->where('logistics_company_id', $company->id)
            ->active()
            ->exists();

        if ($hasActiveApplication) {
            return response()->json([
                'error' => 'You already have an active application with this company. Withdraw it first before applying again.',
            ], 422);
        }

        // Upload the resume to Supabase Storage (bucket "documents"), the
        // same bucket every other applicant/company document lives in.
        $file = $request->file('resume');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $storagePath = "profile/{$userId}/resumes/" . (string) Str::uuid() . '.' . $extension;

        try {
            $this->supabaseStorage->upload($file, $storagePath);
        } catch (\Throwable $e) {
            Log::error('Resume upload to Supabase failed: ' . $e->getMessage());

            return response()->json(['error' => 'Failed to upload your resume. Please try again.'], 500);
        }

        // Create the application
        $application = CourierApplication::create([
            'courier_profile_id' => $userId,
            'logistics_company_id' => $company->id,
            'status' => CourierApplication::STATUS_PENDING,
            'resume_original_name' => $file->getClientOriginalName(),
            'resume_path' => $storagePath,
            'resume_size' => $file->getSize(),
            'cover_note' => $request->input('cover_note'),
            'applied_at' => now(),
        ]);

        Log::info('Application created:', ['application_id' => $application->id]);

        return response()->json([
            'message' => "Application sent to {$company->company_name}. Status: Pending.",
            'application' => $application->load('company'),
        ]);
    }

    /**
     * Withdraw a pending application.
     */
    public function withdraw(Request $request, CourierApplication $application)
    {
        // Debug: Log the request
        Log::info('Withdraw request data:', [
            'all' => $request->all(),
            'user_id_input' => $request->input('user_id'),
            'application_id' => $application->id,
        ]);
        
        $userId = $this->getUserId($request);
        
        Log::info('withdraw - User ID:', ['userId' => $userId]);
        
        if (!$userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        if ($application->courier_profile_id !== $userId) {
            Log::warning('User does not own this application:', [
                'user_id' => $userId,
                'application_owner' => $application->courier_profile_id,
            ]);
            return response()->json(['error' => 'Unauthorized - You do not own this application'], 403);
        }

        if ($application->status !== CourierApplication::STATUS_PENDING) {
            return response()->json([
                'error' => 'Only pending applications can be withdrawn.',
            ], 422);
        }

        $application->update(['status' => CourierApplication::STATUS_WITHDRAWN]);

        Log::info('Application withdrawn:', ['application_id' => $application->id]);

        return response()->json([
            'message' => 'Application withdrawn successfully.',
        ]);
    }
}