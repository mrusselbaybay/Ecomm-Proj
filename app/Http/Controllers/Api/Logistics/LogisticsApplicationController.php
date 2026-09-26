<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Resources\Logistics\LogisticsApplicationResource;
use App\Mail\Logistics\ApplicationTerminated;
use App\Models\CourierApplication;
use App\Models\Document;
use App\Models\LogisticsCompany;
use App\Services\FileStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\AuthSession;

class LogisticsApplicationController extends Controller
{
    // Same private "documents" bucket every applicant/company document
    // lives in — see PickupCourierController's matching constant.
    private const DOCUMENTS_BUCKET = 'documents';

    public function __construct(private readonly FileStorage $files) {}

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

        $url = $this->files->createSignedUrl(self::DOCUMENTS_BUCKET, $courierApplication->resume_path);
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

        $url = $this->files->createSignedUrl(self::DOCUMENTS_BUCKET, $courierApplication->license_path);
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
            // Opt-in: passing this switches the response to a paginated
            // one (adds `links`/`meta`). Omitted, this keeps returning the
            // full plain array every existing caller (Applications.vue,
            // Dashboard.vue's pending-count badge) already relies on.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = CourierApplication::query()
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
            ->orderByDesc('applied_at');

        $applications = $filters['per_page'] ?? null
            ? $query->paginate($filters['per_page'])
            : $query->get();

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

            DB::table('logistics_barangay_assignments')
                ->where('logistics_company_id', $companyId)
                ->where('rider_profile_id', $courierApplication->courier_profile_id)
                ->update(['rider_profile_id' => null]);
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

    public function accept(Request $request, string $application): JsonResponse
    {
        return $this->review($request, $application, fn () => [
            'status' => CourierApplication::STATUS_ACCEPTED,
        ]);
    }

    public function reject(Request $request, string $application): JsonResponse
    {
        return $this->review($request, $application, fn () => [
            'status' => 'rejected',
            'rejection_reason' => $request->validate([
                'rejection_reason' => ['required', 'string', 'max:2000'],
            ])['rejection_reason'],
        ]);
    }

    /** interview_scheduled_at is a floating local time — stored exactly as picked. */
    public function interview(Request $request, string $application): JsonResponse
    {
        return $this->review($request, $application, function () use ($request) {
            $data = $request->validate([
                'interview_scheduled_at' => ['required', 'string', 'max:40'],
            ]);

            return [
                'interview_invited_at' => now(),
                'interview_scheduled_at' => $data['interview_scheduled_at'],
            ];
        }, stampReviewer: false);
    }

    /** Verification documents of a courier who applied to this company. */
    public function courierDocuments(Request $request, string $courier): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $applied = CourierApplication::query()
            ->where('logistics_company_id', $companyId)
            ->where('courier_profile_id', $courier)
            ->exists();

        if (! $applied) {
            return response()->json(['message' => 'Courier not found.'], 404);
        }

        return response()->json([
            'data' => Document::query()
                ->where('owner_kind', 'profile')
                ->where('profile_id', $courier)
                ->latest('created_at')
                ->get(),
        ]);
    }

    /**
     * Apply a status change to one of this company's pending applications.
     *
     * @param  \Closure(): array<string, mixed>  $changes  runs after the ownership check so validation errors never leak other companies' rows
     */
    private function review(Request $request, string $application, \Closure $changes, bool $stampReviewer = true): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request, $profileId);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $courierApplication = CourierApplication::query()
            ->whereKey($application)
            ->where('logistics_company_id', $companyId)
            ->first();

        if (! $courierApplication) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        if ($courierApplication->status !== 'pending') {
            return response()->json(['message' => 'Only pending applications can be updated.'], 422);
        }

        $courierApplication->update([
            ...$changes(),
            ...($stampReviewer ? ['reviewed_by' => $profileId, 'reviewed_at' => now()] : []),
        ]);

        return response()->json(['data' => $courierApplication->only([
            'id', 'status', 'rejection_reason', 'interview_invited_at', 'interview_scheduled_at',
        ])]);
    }

    private function resolveCompanyId(Request $request, ?string &$profileId = null): string|JsonResponse
    {
        $profileId = $this->authenticatedProfileId($request);
        if ($profileId instanceof JsonResponse) {
            return $profileId;
        }

        return $this->companyIdForProfile($profileId)
            ?? response()->json(['message' => 'No logistics company is associated with this account.'], 403);
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

        $profileId = app(AuthSession::class)->resolve($token)?->id;

        if (! $profileId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $profileId;
    }

    /**
     * Get the company owned or administered by the authenticated profile.
     */
    private function companyIdForProfile(string $profileId): ?string
    {
        $companyId = LogisticsCompany::query()
            ->forMember($profileId)
            ->value('id');

        return is_string($companyId) ? $companyId : null;
    }
}
