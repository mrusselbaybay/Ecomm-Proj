<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Resources\Logistics\LogisticsApplicationResource;
use App\Mail\Logistics\ApplicationTerminated;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
     * Return a short-lived signed URL for a courier's driver's license,
     * scoped to the signed-in logistics company that received the
     * application.
     */
    public function license(Request $request, string $application): JsonResponse
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

        if (! $courierApplication || ! $courierApplication->license_path) {
            return response()->json(['message' => "Driver's license not found."], 404);
        }

        $url = $this->supabaseStorage->signedUrl($courierApplication->license_path);
        if (! $url) {
            return response()->json(['message' => "Could not generate a link to the driver's license right now."], 502);
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
            ->with(['courier.courierDetail', 'courier.address'])
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
     * Let an accepted courier go ("Fire" in the Rider Applications page).
     *
     * Mirrors the resignation-approval path: the accepted application is
     * withdrawn and the rider is pulled off every delivery area this
     * company owns, freeing them to apply elsewhere. A notification email
     * is sent best-effort — a mail failure never blocks the termination.
     */
    public function terminate(Request $request, string $application): JsonResponse
    {
        $profileId = $this->authenticatedProfileId($request);
        if ($profileId instanceof JsonResponse) {
            return $profileId;
        }

        $companyId = $this->companyIdForProfile($profileId);
        if (! $companyId) {
            return response()->json(['message' => 'No logistics company is associated with this account.'], 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;

        $courierApplication = CourierApplication::query()
            ->with('courier')
            ->whereKey($application)
            ->where('logistics_company_id', $companyId)
            ->first();

        if (! $courierApplication) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($courierApplication->status !== CourierApplication::STATUS_ACCEPTED) {
            return response()->json(['message' => 'Only a currently accepted courier can be let go.'], 422);
        }

        DB::transaction(function () use ($courierApplication, $companyId, $profileId, $reason): void {
            $courierApplication->update([
                'status' => CourierApplication::STATUS_WITHDRAWN,
                'rejection_reason' => $reason,
                'reviewed_by' => $profileId,
                'reviewed_at' => now(),
            ]);

            DB::table('logistics_delivery_area_riders')
                ->where('rider_profile_id', $courierApplication->courier_profile_id)
                ->whereIn('delivery_area_id', function ($query) use ($companyId): void {
                    $query->select('id')
                        ->from('logistics_delivery_areas')
                        ->where('logistics_company_id', $companyId);
                })
                ->delete();
        });

        $courierEmail = $courierApplication->courier?->email;
        if ($courierEmail) {
            try {
                $companyName = LogisticsCompany::query()->whereKey($companyId)->value('company_name') ?: 'The company';
                $courierName = trim(
                    ($courierApplication->courier?->first_name ?? '').' '.($courierApplication->courier?->last_name ?? '')
                ) ?: 'there';

                Mail::to($courierEmail)->send(new ApplicationTerminated($courierName, $companyName, $reason));
            } catch (\Throwable $e) {
                Log::error('Failed to send application-terminated email', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'data' => new LogisticsApplicationResource(
                $courierApplication->refresh()->load(['courier.courierDetail', 'courier.address'])
            ),
        ]);
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
