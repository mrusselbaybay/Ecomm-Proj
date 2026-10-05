<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSellerVerificationRequest;
use App\Models\Profile;
use App\Models\SellerVerification;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin grant / revoke of the "verified seller" badge
 * (seller_verifications). Routes run behind 'supabase.auth' + 'admin', and
 * the table itself can't be written by the anon / authenticated Supabase
 * roles, so no seller can verify themselves.
 *
 * Verification is separate from account approval: approving a seller lets
 * them trade, verifying them is an extra trust signal shown to buyers. It
 * can be granted to any seller account; the badge only appears on a store
 * page while the store is visible.
 */
class SellerVerificationController extends Controller implements HasMiddleware
{
    /**
     * Until the seller_verifications migration has run, answer clearly instead of
     * failing on a missing table.
     *
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next) {
                if (! Schema::hasTable('seller_verifications')) {
                    return response()->json(['message' => 'Seller verification is not available yet. Please try again later.'], 503);
                }

                return $next($request);
            },
        ];
    }

    /**
     * GET /api/admin/seller-verifications
     *
     * Every seller's current verification row, keyed for the admin users
     * table (sellers without a row are unverified).
     */
    public function index(): JsonResponse
    {
        $rows = SellerVerification::query()->with('verifier:id,first_name,last_name')->get();

        return response()->json([
            'data' => $rows->map(fn (SellerVerification $row) => $this->transform($row))->values(),
        ]);
    }

    /**
     * PUT /api/admin/sellers/{sellerId}/verification  { verified: bool, note?: string }
     */
    public function update(UpdateSellerVerificationRequest $request, string $sellerId): JsonResponse
    {
        $seller = Str::isUuid($sellerId) ? Profile::find($sellerId) : null;

        if (! $seller) {
            return response()->json(['message' => 'Seller not found.'], 404);
        }

        if ($seller->role !== 'seller') {
            return response()->json(['message' => 'Only seller accounts can be verified.'], 422);
        }

        $admin = $request->user();
        $verify = $request->boolean('verified');
        $note = $request->validated('note');

        $row = SellerVerification::find($seller->id) ?? new SellerVerification(['seller_id' => $seller->id]);

        if ($verify) {
            // Re-verifying keeps the original grant, so the date stays
            // honest, unless it had been revoked in between.
            if (! $row->exists || ! $row->isVerified()) {
                $row->fill([
                    'status' => SellerVerification::STATUS_VERIFIED,
                    'verified_by' => $admin->id,
                    'verified_at' => now(),
                    'revoked_by' => null,
                    'revoked_at' => null,
                ]);
            }
        } elseif ($row->exists && $row->isVerified()) {
            $row->fill([
                'status' => SellerVerification::STATUS_REVOKED,
                'revoked_by' => $admin->id,
                'revoked_at' => now(),
            ]);
        }

        if ($note !== null) {
            $row->note = $note;
        }

        if ($row->status !== null) {
            $row->save();
        }

        return response()->json([
            'data' => $row->status !== null
                ? $this->transform($row->load('verifier:id,first_name,last_name'))
                : ['sellerId' => $seller->id, 'status' => 'unverified', 'isVerified' => false, 'verifiedAt' => null, 'verifiedBy' => null, 'revokedAt' => null, 'note' => null],
        ]);
    }

    /**
     * @return array{sellerId: string, status: string, isVerified: bool, verifiedAt: string|null, verifiedBy: string|null, revokedAt: string|null, note: string|null}
     */
    private function transform(SellerVerification $row): array
    {
        return [
            'sellerId' => $row->seller_id,
            'status' => $row->status,
            'isVerified' => $row->isVerified(),
            'verifiedAt' => $row->verified_at?->toIso8601String(),
            'verifiedBy' => $row->verifier?->full_name ?: null,
            'revokedAt' => $row->revoked_at?->toIso8601String(),
            'note' => $row->note,
        ];
    }
}
