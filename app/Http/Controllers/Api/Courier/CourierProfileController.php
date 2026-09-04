<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Models\CourierApplication;
use App\Models\Profile;
use App\Models\ResignationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CourierProfileController extends Controller
{
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

        return $profile;
    }

    /**
     * Return the signed-in courier's employment status. "Employed" means an
     * 'accepted' row in courier_applications (the source of truth the rest
     * of the logistics flow keys off) — the payload carries the hiring
     * company's contact details + offered salary so the Flutter "Find
     * Work" screen can show the employer card, plus any pending resignation
     * request so it can show that state instead of a "resign" button.
     */
    public function employment(Request $request): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $application = CourierApplication::query()
            ->with('logisticsCompany')
            ->where('courier_profile_id', $profile->id)
            ->where('status', CourierApplication::STATUS_ACCEPTED)
            ->latest('reviewed_at')
            ->first();

        $company = $application?->logisticsCompany;
        $details = $profile->courierDetail;

        $pending = $company === null ? null : ResignationRequest::query()
            ->where('courier_profile_id', $profile->id)
            ->where('status', ResignationRequest::STATUS_PENDING)
            ->orderByDesc('submitted_at')
            ->first();

        return response()->json([
            'data' => [
                'is_employed' => $company !== null,
                'courier_application_id' => $application?->id,
                'logistics_company_id' => $company?->id,
                'company_name' => $company?->company_name,
                'company_email' => $company?->company_email,
                'company_contact_no' => $company?->company_contact_no,
                'region' => $company?->region,
                'description' => $company?->description,
                'monthly_salary' => $company?->monthly_salary !== null ? (float) $company->monthly_salary : null,
                'employed_since' => $application?->reviewed_at?->toISOString(),
                'vehicle' => $details?->vehicle,
                'plate_number' => $details?->plate_number,
                'pending_resignation' => $pending ? [
                    'id' => $pending->id,
                    'status' => $pending->status,
                    'submitted_at' => $pending->submitted_at?->toISOString(),
                ] : null,
            ],
        ]);
    }
}
