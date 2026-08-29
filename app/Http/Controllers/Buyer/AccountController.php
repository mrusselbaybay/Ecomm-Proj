<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\UpdateProfileRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buyer account (the public.profiles row for the authenticated buyer).
 *
 * Profile edits used to go straight from the browser to Supabase
 * (useBuyerAccount.js), which meant Profile::booted()'s guard against
 * role escalation never ran on that path. Routing the write through here
 * puts every profile update behind server-side validation + that model
 * guard again. Password changes stay on Supabase Auth (updateUser) —
 * that's the auth record, not this table.
 */
class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->transform($request->user())]);
    }

    /**
     * PUT /api/buyer/account/profile
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $request->user();
        $data = $request->validated();

        $profile->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'middle_initial' => isset($data['middle_initial']) && $data['middle_initial'] !== ''
                ? mb_strtoupper(mb_substr($data['middle_initial'], 0, 1))
                : null,
            'sex' => $data['sex'] ?? null,
            'contact_no' => $data['contact_no'] ?? null,
            'birthday' => $data['birthday'] ?? null,
        ]);

        try {
            $profile->save();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Could not save your profile. Please check the values and try again.',
            ], 422);
        }

        return response()->json(['data' => $this->transform($profile->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform($profile): array
    {
        return [
            'id' => $profile->id,
            'first_name' => $profile->first_name,
            'middle_initial' => $profile->middle_initial,
            'last_name' => $profile->last_name,
            'sex' => $profile->sex,
            'contact_no' => $profile->contact_no,
            'birthday' => $profile->birthday?->toDateString(),
            'email' => $profile->email,
            'role' => $profile->role,
            'account_status' => $profile->account_status,
        ];
    }
}
