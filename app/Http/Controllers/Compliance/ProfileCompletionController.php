<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProfileCompletionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $resolver = $request->getUserResolver();
        $user = $resolver();
        abort_unless($user instanceof Profile, 401);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:30'],
            'address' => ['required', 'array'],
            'address.region_code' => ['nullable', 'string', 'max:20'],
            'address.region_name' => ['nullable', 'string', 'max:255'],
            'address.province_code' => ['required', 'string', 'max:20'],
            'address.province_name' => ['required', 'string', 'max:255'],
            'address.municipality_code' => ['required', 'string', 'max:20'],
            'address.municipality_name' => ['required', 'string', 'max:255'],
            'address.barangay' => ['required', 'string', 'max:255'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.house_no' => ['nullable', 'string', 'max:100'],
        ]);

        DB::beginTransaction();

        try {
            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'contact_no' => $data['mobile'],
            ]);

            Address::query()->updateOrCreate(
                ['profile_id' => $user->id, 'owner_kind' => 'profile'],
                $data['address'],
            );

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return response()->json(['data' => [
            'id' => $user->id,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'mobile' => $user->contact_no,
        ]]);
    }
}
