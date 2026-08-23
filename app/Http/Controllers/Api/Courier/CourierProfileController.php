<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Models\Profile;
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
     * Return the signed-in courier's employment status: whether a logistics
     * company has taken them on, plus the vehicle/plate on file for them.
     */
    public function employment(Request $request): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $details = $profile->courierDetail()->with('logisticsCompany')->first();
        $company = $details?->logisticsCompany;

        return response()->json([
            'data' => [
                'is_employed' => $company !== null,
                'logistics_company_id' => $company?->id,
                'company_name' => $company?->company_name,
                'vehicle' => $details?->vehicle,
                'plate_number' => $details?->plate_number,
            ],
        ]);
    }
}
