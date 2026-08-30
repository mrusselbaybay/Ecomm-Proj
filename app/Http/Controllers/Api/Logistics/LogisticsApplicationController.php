<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Resources\Logistics\LogisticsApplicationResource;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Services\SupabaseStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LogisticsApplicationController extends Controller
{
    public function __construct(private readonly SupabaseStorageService $supabaseStorage) {}

    /**
     * Return a short-lived signed URL for a courier's resume, scoped to the
     * signed-in logistics company that received the application.
     */
    public function resume(Request $request, string $application): JsonResponse
    {
        $profileId = $this->authenticatedProfileId($request);
        if ($profileId instanceof JsonResponse) {
            return $profileId;
        }

        $companyId = $this->companyIdForProfile($profileId);
        if (! $companyId) {
            return response()->json(['message' => 'No logistics company is associated with this account.'], 403);
        }

        $courierApplication = CourierApplication::query()
            ->whereKey($application)
            ->where('logistics_company_id', $companyId)
            ->first();

        if (! $courierApplication || ! $courierApplication->resume_path) {
            return response()->json(['message' => 'Resume not found.'], 404);
        }

        $url = $this->supabaseStorage->signedUrl($courierApplication->resume_path);
        if (! $url) {
            return response()->json(['message' => 'Could not generate a link to the resume right now.'], 502);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Return applications submitted to the signed-in logistics company.
     */
    public function __invoke(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $profileId = $this->authenticatedProfileId($request);
        if ($profileId instanceof JsonResponse) {
            return $profileId;
        }

        $companyId = $this->companyIdForProfile($profileId);
        if (! $companyId) {
            return response()->json(['message' => 'No logistics company is associated with this account.'], 403);
        }

        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,accepted,rejected,withdrawn'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $applications = CourierApplication::query()
            ->with('courier.courierDetail')
            ->where('logistics_company_id', $companyId)
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where('status', $status);
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->whereHas('courier', function (Builder $courierQuery) use ($search): void {
                    $courierQuery->where(function (Builder $courierQuery) use ($search): void {
                        $courierQuery->whereLike('first_name', "%{$search}%")
                            ->orWhereLike('last_name', "%{$search}%")
                            ->orWhereLike('email', "%{$search}%");
                    });
                });
            })
            ->orderByDesc('applied_at')
            ->get();

        return LogisticsApplicationResource::collection($applications);
    }

    /**
     * Validate the Supabase access token and return its profile ID.
     */
    private function authenticatedProfileId(Request $request): string|JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $supabaseRequest = Http::timeout(10)
            ->withHeaders([
                'apikey' => config('services.supabase.anon_key'),
                'Authorization' => "Bearer {$token}",
            ]);

        if (! config('services.supabase.verify_ssl', true)) {
            $supabaseRequest->withoutVerifying();
        }

        try {
            $response = $supabaseRequest->get(
                rtrim((string) config('services.supabase.url'), '/').'/auth/v1/user'
            );
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json([
                'message' => 'The authentication service is temporarily unavailable.',
            ], 503);
        }

        $profileId = $response->successful() ? $response->json('id') : null;

        if (! is_string($profileId) || $profileId === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $profileId;
    }

    /**
     * Get the company owned or administered by the authenticated profile.
     */
    private function companyIdForProfile(string $profileId): ?string
    {
        $ownedCompanyId = LogisticsCompany::query()
            ->where('owner_profile_id', $profileId)
            ->value('id');

        if (is_string($ownedCompanyId)) {
            return $ownedCompanyId;
        }

        $staffCompanyId = DB::table('logistics_admin_details')
            ->where('profile_id', $profileId)
            ->value('logistics_company_id');

        return is_string($staffCompanyId) ? $staffCompanyId : null;
    }
}
