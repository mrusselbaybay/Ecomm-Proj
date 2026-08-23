<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\Profile;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CourierApplicationController extends Controller
{
    public function __construct(private readonly SupabaseStorageService $supabaseStorage)
    {
    }

    /** Return the signed-in profile that can use the courier work flow. */
    private function authenticatedProfile(Request $request): Profile|JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $authResponse = Http::withHeaders([
            'apikey' => config('services.supabase.anon_key'),
            'Authorization' => 'Bearer '.$token,
        ])->get(config('services.supabase.url').'/auth/v1/user');

        if (! $authResponse->successful()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $profile = Profile::query()->find($authResponse->json('id'));
        if (! $profile) {
            return response()->json([
                'message' => 'Your courier profile has not been set up yet. Please complete courier registration first.',
            ], 422);
        }

        if (! in_array($profile->role, ['courier', 'logistics'], true)) {
            return response()->json(['message' => 'Only courier or logistics accounts can apply.'], 403);
        }

        return $profile;
    }

    /** Return the signed-in user's persisted applications. */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $applications = CourierApplication::query()
            ->with('logisticsCompany')
            ->where('courier_profile_id', $profile->id)
            ->orderByDesc('applied_at')
            ->get()
            ->map(fn (CourierApplication $application) => $this->applicationData($application));

        return response()->json(['data' => $applications]);
    }

    /**
     * Persist a courier's application, including the resume upload, in the
     * Supabase PostgreSQL database and Supabase Storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logistics_company_id' => ['required', 'uuid'],
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'cover_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $company = LogisticsCompany::query()->find($validated['logistics_company_id']);
        if (! $company) {
            return response()->json(['message' => 'Logistics company not found.'], 404);
        }

        $existing = CourierApplication::query()
            ->where('courier_profile_id', $profile->id)
            ->where('logistics_company_id', $company->id)
            ->first();

        if ($existing && $existing->status !== CourierApplication::STATUS_WITHDRAWN) {
            return response()->json([
                'message' => 'You already have an active application with this company. Withdraw it first before applying again.',
            ], 422);
        }

        // Upload the resume to Supabase Storage (bucket "documents"), the
        // same bucket every other applicant/company document lives in.
        $file = $request->file('resume');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $storagePath = "profile/{$profile->id}/resumes/".(string) Str::uuid().'.'.$extension;

        try {
            $this->supabaseStorage->upload($file, $storagePath);
        } catch (\Throwable $e) {
            Log::error('Resume upload to Supabase failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to upload your resume. Please try again.'], 500);
        }

        $attributes = [
            'status' => CourierApplication::STATUS_PENDING,
            'resume_original_name' => $file->getClientOriginalName(),
            'resume_path' => $storagePath,
            'resume_size' => $file->getSize(),
            'cover_note' => $validated['cover_note'] ?? null,
            'applied_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'interview_invited_at' => null,
            'interview_scheduled_at' => null,
        ];

        if ($existing) {
            $existing->update($attributes);
            $application = $existing;
        } else {
            $application = CourierApplication::query()->create([
                'id' => (string) Str::uuid(),
                'courier_profile_id' => $profile->id,
                'logistics_company_id' => $company->id,
                ...$attributes,
            ]);
        }

        $application->load('logisticsCompany');

        return response()->json(
            ['data' => $this->applicationData($application)],
            $existing ? 200 : 201,
        );
    }

    /** Withdraw the signed-in courier's pending application. */
    public function withdraw(Request $request, string $application): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $courierApplication = CourierApplication::query()
            ->whereKey($application)
            ->where('courier_profile_id', $profile->id)
            ->first();

        if (! $courierApplication) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($courierApplication->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending applications can be withdrawn.',
            ], 422);
        }

        $courierApplication->update(['status' => 'withdrawn']);

        return response()->json([
            'data' => $this->applicationData($courierApplication),
        ]);
    }

    /**
     * Return a short-lived signed URL for the signed-in courier's own resume
     * on a given application.
     */
    public function resume(Request $request, string $application): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $courierApplication = CourierApplication::query()
            ->whereKey($application)
            ->where('courier_profile_id', $profile->id)
            ->first();

        if (! $courierApplication || ! $courierApplication->resume_path) {
            return response()->json(['message' => 'No resume on file for this application.'], 404);
        }

        $url = $this->supabaseStorage->signedUrl($courierApplication->resume_path);
        if (! $url) {
            return response()->json(['message' => 'Could not generate a link to your resume right now.'], 502);
        }

        return response()->json(['url' => $url]);
    }

    /** @return array<string, mixed> */
    private function applicationData(CourierApplication $application): array
    {
        $company = $application->logisticsCompany;

        return [
            'id' => $application->id,
            'logistics_company_id' => $application->logistics_company_id,
            'status' => $application->status,
            'applied_at' => $application->applied_at?->toISOString(),
            'reviewed_at' => $application->reviewed_at?->toISOString(),
            'rejection_reason' => $application->rejection_reason,
            'interview_invited_at' => $application->interview_invited_at?->toISOString(),
            'interview_scheduled_at' => $application->interview_scheduled_at?->toISOString(),
            'cover_note' => $application->cover_note,
            'resume_original_name' => $application->resume_original_name,
            'resume_size' => $application->resume_size,
            'has_resume' => filled($application->resume_path),
            'company' => $company ? [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'company_email' => $company->company_email,
                'company_contact_no' => $company->company_contact_no,
                'region' => $company->region,
                'status' => $company->status,
                'account_status' => $company->account_status,
            ] : null,
        ];
    }
}
