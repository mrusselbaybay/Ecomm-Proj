<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Profile;
use App\Models\SellerDetail;
use App\Models\StatusAuditLog;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * "Switch Account" between the buyer and seller roles of one account.
 *
 * `profiles.role` holds the active role, so every existing role gate keeps
 * working unchanged. Shopping is always available; selling needs an
 * approved seller application (seller_details.application_status).
 */
class RoleSwitchController extends Controller
{
    private const SWITCHABLE_ROLES = ['buyer', 'seller'];

    public const LINES_OF_BUSINESS = [
        'Pet Supplies',
        'Kids and Baby',
        'Electronics and Gadgets',
        'House and Garden',
        "Woman's Apparel",
        "Men's Apparel",
        'Sports and Outdoors',
        'Health and Beauty',
    ];

    public function show(Request $request): JsonResponse
    {
        $profile = $this->switchableProfile($request);

        return response()->json(['data' => $this->state($profile)]);
    }

    public function switch(Request $request): JsonResponse
    {
        $profile = $this->switchableProfile($request);

        // An explicit role comes from the login role picker; none toggles.
        $data = $request->validate(['role' => ['nullable', Rule::in(self::SWITCHABLE_ROLES)]]);
        $target = $data['role'] ?? ($profile->role === 'seller' ? 'buyer' : 'seller');

        if ($target === 'seller' && ! $profile->hasSellerCapability()) {
            return response()->json([
                'message' => 'Your seller application must be approved before you can start selling.',
                'data' => $this->state($profile),
            ], 409);
        }

        // Plain column write: role is not mass-assignable for a reason
        // (Profile::booted guards it), and only buyer<->seller is possible here.
        DB::table('profiles')->where('id', $profile->id)->update(['role' => $target, 'updated_at' => now()]);
        $profile->currentAccessToken()?->forceFill(['active_role' => $target])->save();

        return response()->json(['data' => $this->state($profile->refresh())]);
    }

    public function apply(Request $request): JsonResponse
    {
        $profile = $this->switchableProfile($request);
        $detail = $profile->sellerDetail;

        if ($detail?->isApproved()) {
            return response()->json(['message' => 'You can already sell — just switch accounts.'], 409);
        }

        if ($detail?->application_status === SellerDetail::APPLICATION_PENDING) {
            return response()->json(['message' => 'Your seller application is already under review.'], 409);
        }

        $hasValidId = $this->onFileValidId($profile) !== null;

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'line_of_business' => ['required', 'string', Rule::in(self::LINES_OF_BUSINESS)],
            // The ID on file from registration satisfies the requirement.
            'id_file' => [$hasValidId ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'id_type' => [$hasValidId ? 'nullable' : 'required', 'required_with:id_file', 'string', Rule::in(AuthController::ID_TYPES)],
            'business_permit' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        DB::transaction(function () use ($profile, $data, $request): void {
            SellerDetail::updateOrCreate(['profile_id' => $profile->id], [
                'business_name' => $data['business_name'],
                'line_of_business' => $data['line_of_business'],
                'application_status' => SellerDetail::APPLICATION_PENDING,
                'application_reason' => null,
                'applied_at' => now(),
            ]);

            if ($request->hasFile('id_file')) {
                $this->storeDocument($profile, 'valid_id', $request->file('id_file'), $data['id_type']);
            }
            $this->storeDocument($profile, 'business_permit', $request->file('business_permit'));

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $profile->account_status,
                'new_status' => $profile->account_status,
                'reason' => 'Applied for the seller role',
                'changed_by' => $profile->id,
            ]);
        });

        return response()->json([
            'message' => 'Application submitted! We\'ll notify you once an administrator approves it.',
            'data' => $this->state($profile->refresh()),
        ], 201);
    }

    private function switchableProfile(Request $request): Profile
    {
        /** @var Profile $profile */
        $profile = $request->user();

        abort_unless(in_array($profile->role, self::SWITCHABLE_ROLES, true), 403, 'This account cannot switch roles.');
        abort_unless($profile->status === 'approved' && $profile->account_status === 'active', 403, 'Your account is not active.');

        return $profile;
    }

    /** @return array<string, mixed> */
    private function state(Profile $profile): array
    {
        $detail = $profile->sellerDetail;
        $validId = $this->onFileValidId($profile);

        return [
            'active_role' => $profile->role,
            'can_sell' => $profile->hasSellerCapability(),
            'seller_application' => $detail ? [
                'status' => $detail->application_status ?? SellerDetail::APPLICATION_APPROVED,
                'reason' => $detail->application_reason,
                'business_name' => $detail->business_name,
                'line_of_business' => $detail->line_of_business,
                'applied_at' => $detail->applied_at?->toIso8601String(),
            ] : null,
            'valid_id_on_file' => $validId ? [
                'id_type' => $validId->id_type,
                'status' => $validId->status,
                'name' => basename($validId->storage_path),
            ] : null,
        ];
    }

    /** The latest non-rejected valid ID, which can be reused for the application. */
    private function onFileValidId(Profile $profile): ?Document
    {
        return Document::query()
            ->where('owner_kind', 'profile')
            ->where('profile_id', $profile->id)
            ->where('doc_type', 'valid_id')
            ->where('status', '!=', 'rejected')
            ->orderByDesc('created_at')
            ->first();
    }

    private function storeDocument(Profile $profile, string $docType, UploadedFile $file, ?string $idType = null): void
    {
        $path = "profile/{$profile->id}/{$docType}_".now()->timestamp.'.'.$file->getClientOriginalExtension();

        app(FileStorage::class)->upload('documents', $path, file_get_contents($file->getRealPath()), $file->getClientMimeType());

        DB::table('documents')->insert([
            'id' => (string) Str::uuid(),
            'owner_kind' => 'profile',
            'profile_id' => $profile->id,
            'doc_type' => $docType,
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'status' => 'pending',
            'id_type' => $idType,
            'created_at' => now(),
        ]);
    }
}
