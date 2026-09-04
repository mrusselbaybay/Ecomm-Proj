<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Models\CourierApplication;
use App\Models\Profile;
use App\Models\ResignationRequest;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The courier-facing side of the resignation flow (Flutter "Find Work" ->
 * "My Employer"). A courier who has an 'accepted' courier_applications row
 * submits a letter here; the logistics company reviews it via
 * Api\Logistics\ResignationRequestController.
 */
class ResignationRequestController extends Controller
{
    public function __construct(private readonly SupabaseStorageService $supabaseStorage) {}

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
            return response()->json(['message' => 'Your courier profile has not been set up yet.'], 422);
        }

        if (! in_array($profile->role, ['courier', 'logistics'], true)) {
            return response()->json(['message' => 'Only courier accounts can resign.'], 403);
        }

        return $profile;
    }

    /** The signed-in courier's 'accepted' application, or null. */
    private function acceptedApplication(string $profileId): ?CourierApplication
    {
        return CourierApplication::query()
            ->with('logisticsCompany')
            ->where('courier_profile_id', $profileId)
            ->where('status', CourierApplication::STATUS_ACCEPTED)
            ->latest('reviewed_at')
            ->first();
    }

    /** List the signed-in courier's resignation requests (newest first). */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $requests = ResignationRequest::query()
            ->with('logisticsCompany')
            ->where('courier_profile_id', $profile->id)
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (ResignationRequest $r) => $this->present($r));

        return response()->json(['data' => $requests]);
    }

    /** Submit a resignation letter for the courier's current employer. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'letter' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'letter.required' => 'Please attach your resignation letter.',
            'letter.mimes' => 'The resignation letter must be a PDF, DOC, or DOCX file.',
            'letter.max' => 'The resignation letter must not be larger than 5MB.',
        ]);

        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $application = $this->acceptedApplication($profile->id);
        if (! $application) {
            return response()->json([
                'message' => "You're not currently employed by a logistics company.",
            ], 422);
        }

        $alreadyPending = ResignationRequest::query()
            ->where('courier_profile_id', $profile->id)
            ->where('status', ResignationRequest::STATUS_PENDING)
            ->exists();

        if ($alreadyPending) {
            return response()->json([
                'message' => 'You already have a resignation request awaiting review.',
            ], 422);
        }

        $file = $request->file('letter');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $path = "profile/{$profile->id}/resignations/".(string) Str::uuid().'.'.$extension;

        try {
            $this->supabaseStorage->upload($file, $path);
        } catch (\Throwable $e) {
            Log::error('Resignation letter upload to Supabase failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to upload your resignation letter. Please try again.'], 500);
        }

        $resignation = ResignationRequest::query()->create([
            'courier_profile_id' => $profile->id,
            'logistics_company_id' => $application->logistics_company_id,
            'courier_application_id' => $application->id,
            'status' => ResignationRequest::STATUS_PENDING,
            'letter_original_name' => $file->getClientOriginalName(),
            'letter_path' => $path,
            'letter_size' => $file->getSize(),
            'reason' => $validated['reason'] ?? null,
            'submitted_at' => now(),
        ]);

        $resignation->load('logisticsCompany');

        return response()->json(['data' => $this->present($resignation)], 201);
    }

    /** Cancel the signed-in courier's own still-pending request. */
    public function destroy(Request $request, string $resignationRequest): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $resignation = ResignationRequest::query()
            ->whereKey($resignationRequest)
            ->where('courier_profile_id', $profile->id)
            ->first();

        if (! $resignation) {
            return response()->json(['message' => 'Resignation request not found.'], 404);
        }

        if ($resignation->status !== ResignationRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Only a pending request can be cancelled.'], 422);
        }

        $resignation->update(['status' => ResignationRequest::STATUS_CANCELLED]);
        $resignation->load('logisticsCompany');

        return response()->json(['data' => $this->present($resignation)]);
    }

    /** Signed URL for the courier's own resignation letter. */
    public function letter(Request $request, string $resignationRequest): JsonResponse
    {
        $profile = $this->authenticatedProfile($request);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $resignation = ResignationRequest::query()
            ->whereKey($resignationRequest)
            ->where('courier_profile_id', $profile->id)
            ->first();

        if (! $resignation || ! $resignation->letter_path) {
            return response()->json(['message' => 'No resignation letter on file.'], 404);
        }

        $url = $this->supabaseStorage->signedUrl($resignation->letter_path);
        if (! $url) {
            return response()->json(['message' => 'Could not generate a link to the letter right now.'], 502);
        }

        return response()->json(['url' => $url]);
    }

    /** @return array<string, mixed> */
    private function present(ResignationRequest $r): array
    {
        $company = $r->logisticsCompany;

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
            'logistics_company_id' => $r->logistics_company_id,
            'company_name' => $company?->company_name,
        ];
    }
}
