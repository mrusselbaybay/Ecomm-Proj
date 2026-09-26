<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\ResignationRequest;
use App\Services\FileStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\AuthSession;

/**
 * The logistics-portal side of the resignation flow (the "Resignation
 * requests" panel on the Rider Applications page). Reads are listed for
 * the signed-in company; approve frees the courier (accepted application
 * -> withdrawn, pulled from this company's delivery areas), reject sends
 * it back with a reason.
 */
class ResignationRequestController extends Controller
{
    // Same private "documents" bucket every applicant/company document
    // lives in — see PickupCourierController's matching constant.
    private const DOCUMENTS_BUCKET = 'documents';

    public function __construct(private readonly FileStorage $files) {}

    /**
     * Resignation requests addressed to the signed-in company (pending
     * first), 5 per page — the panel used to pull the whole company
     * history in one shot every time it was opened.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyOrFail($request);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $requests = ResignationRequest::query()
            ->with('courier')
            ->where('logistics_company_id', $companyId)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('submitted_at')
            ->paginate(5);

        return response()->json([
            'data' => collect($requests->items())
                ->map(fn (ResignationRequest $r) => $this->present($r))
                ->values(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                // The pending badge on the Applications page needs the
                // company-wide pending count, not just this page's —
                // a second, index-backed COUNT query is far cheaper than
                // pulling every row to count them client-side.
                'pending_total' => ResignationRequest::query()
                    ->where('logistics_company_id', $companyId)
                    ->where('status', ResignationRequest::STATUS_PENDING)
                    ->count(),
            ],
        ]);
    }

    /** Signed URL for a courier's resignation letter, scoped to this company. */
    public function letter(Request $request, string $resignationRequest): JsonResponse
    {
        $companyId = $this->companyOrFail($request);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $resignation = ResignationRequest::query()
            ->whereKey($resignationRequest)
            ->where('logistics_company_id', $companyId)
            ->first();

        if (! $resignation || ! $resignation->letter_path) {
            return response()->json(['message' => 'Resignation letter not found.'], 404);
        }

        $url = $this->files->createSignedUrl(self::DOCUMENTS_BUCKET, $resignation->letter_path);
        if (! $url) {
            return response()->json(['message' => 'Could not generate a link to the letter right now.'], 502);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Approve: the courier leaves. Their 'accepted' application flips to
     * 'withdrawn' and they're removed from this company's delivery areas,
     * so they're free to apply elsewhere.
     */
    public function approve(Request $request, string $resignationRequest): JsonResponse
    {
        $companyId = $this->companyOrFail($request);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $resignation = $this->pendingForCompany($resignationRequest, $companyId);
        if ($resignation instanceof JsonResponse) {
            return $resignation;
        }

        $profileId = $this->authenticatedProfileId($request);

        DB::transaction(function () use ($resignation, $companyId, $profileId, $data): void {
            $resignation->update([
                'status' => ResignationRequest::STATUS_APPROVED,
                'decision_note' => $data['note'] ?? null,
                'reviewed_by' => is_string($profileId) ? $profileId : null,
                'reviewed_at' => now(),
            ]);

            // Free the courier: withdraw the accepted application so
            // CourierApplication::scopeActive() no longer counts it and the
            // "already employed" guard on new applications clears.
            $application = $resignation->courier_application_id
                ? CourierApplication::query()->whereKey($resignation->courier_application_id)->lockForUpdate()->first()
                : CourierApplication::query()
                    ->where('courier_profile_id', $resignation->courier_profile_id)
                    ->where('logistics_company_id', $companyId)
                    ->where('status', CourierApplication::STATUS_ACCEPTED)
                    ->lockForUpdate()
                    ->first();

            $application?->update(['status' => CourierApplication::STATUS_WITHDRAWN]);

            // Pull them off every barangay assignment owned by this company.
            DB::table('logistics_barangay_assignments')
                ->where('logistics_company_id', $companyId)
                ->where('rider_profile_id', $resignation->courier_profile_id)
                ->update(['rider_profile_id' => null]);
        });

        $resignation->refresh()->load('courier');

        return response()->json(['data' => $this->present($resignation)]);
    }

    /** Reject: the courier stays employed. A reason is required. */
    public function reject(Request $request, string $resignationRequest): JsonResponse
    {
        $companyId = $this->companyOrFail($request);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ], [
            'note.required' => 'Give the courier a reason for rejecting the resignation.',
        ]);

        $resignation = $this->pendingForCompany($resignationRequest, $companyId);
        if ($resignation instanceof JsonResponse) {
            return $resignation;
        }

        $profileId = $this->authenticatedProfileId($request);

        $resignation->update([
            'status' => ResignationRequest::STATUS_REJECTED,
            'decision_note' => $data['note'],
            'reviewed_by' => is_string($profileId) ? $profileId : null,
            'reviewed_at' => now(),
        ]);

        $resignation->refresh()->load('courier');

        return response()->json(['data' => $this->present($resignation)]);
    }

    private function pendingForCompany(string $id, string $companyId): ResignationRequest|JsonResponse
    {
        $resignation = ResignationRequest::query()
            ->whereKey($id)
            ->where('logistics_company_id', $companyId)
            ->first();

        if (! $resignation) {
            return response()->json(['message' => 'Resignation request not found.'], 404);
        }

        if ($resignation->status !== ResignationRequest::STATUS_PENDING) {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        return $resignation;
    }

    private function companyOrFail(Request $request): string|JsonResponse
    {
        $profileId = $this->authenticatedProfileId($request);
        if ($profileId instanceof JsonResponse) {
            return $profileId;
        }

        $companyId = $this->companyIdForProfile($profileId);
        if (! $companyId) {
            return response()->json(['message' => 'No logistics company is associated with this account.'], 403);
        }

        return $companyId;
    }

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

    private function companyIdForProfile(string $profileId): ?string
    {
        $companyId = LogisticsCompany::query()
            ->forMember($profileId)
            ->value('id');

        return is_string($companyId) ? $companyId : null;
    }

    /** @return array<string, mixed> */
    private function present(ResignationRequest $r): array
    {
        $courier = $r->courier;

        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'decision_note' => $r->decision_note,
            'letter_original_name' => $r->letter_original_name,
            'letter_size' => $r->letter_size,
            'has_letter' => filled($r->letter_path),
            'submitted_at' => $r->submitted_at?->toISOString(),
            'reviewed_at' => $r->reviewed_at?->toISOString(),
            'courier' => $courier ? [
                'id' => $courier->id,
                'first_name' => $courier->first_name,
                'last_name' => $courier->last_name,
                'email' => $courier->email,
                'contact_no' => $courier->contact_no,
            ] : null,
        ];
    }
}
