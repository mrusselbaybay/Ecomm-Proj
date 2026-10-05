<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreAddressRequest;
use App\Http\Requests\Buyer\UpdateAddressRequest;
use App\Models\BuyerAddress;
use App\Services\BuyerAddressSync;
use App\Support\StreetCleaner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buyer's saved delivery address book (buyer_addresses). Every query is
 * scoped to the authenticated buyer's own rows, so one buyer can never
 * read or mutate another's addresses.
 */
class AddressController extends Controller
{
    public function __construct(private readonly BuyerAddressSync $sync) {}

    public function index(Request $request): JsonResponse
    {
        // First visit: the account address becomes the default saved one.
        $this->sync->seed($request->user());

        $addresses = BuyerAddress::query()
            ->where('buyer_profile_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $addresses->map(fn (BuyerAddress $a) => $this->transform($a)),
        ]);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $data = $request->validated();

        $address = DB::transaction(function () use ($buyer, $data) {
            $existingCount = BuyerAddress::where('buyer_profile_id', $buyer->id)->count();
            $makeDefault = (bool) ($data['is_default'] ?? false) || $existingCount === 0;

            if ($makeDefault) {
                BuyerAddress::where('buyer_profile_id', $buyer->id)->update(['is_default' => false]);
            }

            return BuyerAddress::create([
                'buyer_profile_id' => $buyer->id,
                'recipient_name' => $data['recipient_name'],
                'contact_no' => $data['contact_no'],
                'house_no' => $data['house_no'] ?? null,
                'line1' => StreetCleaner::clean($data['line1'], [$data['barangay'], $data['city'], $data['province'], $data['region_name']]),
                'region_name' => $data['region_name'],
                'province_code' => $data['province_code'],
                'province' => $data['province'],
                'municipality_code' => $data['municipality_code'],
                'city' => $data['city'],
                'barangay' => $data['barangay'],
                'postal_code' => $data['postal_code'] ?? null,
                'label' => $data['label'] ?? 'Home',
                'is_default' => $makeDefault,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);
        });

        $this->sync->pushDefault($buyer);

        return response()->json(['data' => $this->transform($address)], 201);
    }

    public function update(UpdateAddressRequest $request, string $id): JsonResponse
    {
        $address = $this->findForBuyer($request, $id);

        if (! $address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($request, $address, $data) {
            if (array_key_exists('is_default', $data) && $data['is_default']) {
                BuyerAddress::where('buyer_profile_id', $request->user()->id)
                    ->whereKeyNot($address->id)
                    ->update(['is_default' => false]);
            }

            $address->fill(collect($data)->only([
                'recipient_name', 'contact_no', 'house_no', 'line1', 'region_name', 'province_code', 'province',
                'municipality_code', 'city', 'barangay', 'postal_code', 'label', 'latitude', 'longitude',
            ])->all());

            if (array_key_exists('is_default', $data) && $data['is_default']) {
                $address->is_default = true;
            }

            $address->line1 = StreetCleaner::clean($address->line1, [$address->barangay, $address->city, $address->province, $address->region_name]);

            $address->save();
        });

        $this->sync->pushDefault($request->user());

        return response()->json(['data' => $this->transform($address->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $address = $this->findForBuyer($request, $id);

        if (! $address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        $wasDefault = $address->is_default;

        DB::transaction(function () use ($request, $address, $wasDefault) {
            $address->delete();

            if ($wasDefault) {
                $next = BuyerAddress::where('buyer_profile_id', $request->user()->id)
                    ->orderByDesc('last_used_at')
                    ->orderByDesc('created_at')
                    ->first();

                $next?->update(['is_default' => true]);
            }
        });

        $this->sync->pushDefault($request->user());

        return response()->json(['message' => 'Address removed.']);
    }

    public function setDefault(Request $request, string $id): JsonResponse
    {
        $address = $this->findForBuyer($request, $id);

        if (! $address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        DB::transaction(function () use ($request, $address) {
            BuyerAddress::where('buyer_profile_id', $request->user()->id)->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });

        $this->sync->pushDefault($request->user());

        return response()->json(['data' => $this->transform($address->fresh())]);
    }

    private function findForBuyer(Request $request, string $id): ?BuyerAddress
    {
        return BuyerAddress::where('buyer_profile_id', $request->user()->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(BuyerAddress $address): array
    {
        return [
            'id' => $address->id,
            'fullName' => $address->recipient_name,
            'phone' => $address->contact_no,
            'houseNo' => $address->house_no,
            'line1' => $address->line1,
            'region' => $address->region_name,
            'provinceCode' => $address->province_code,
            'province' => $address->province,
            'municipalityCode' => $address->municipality_code,
            'city' => $address->city,
            'barangay' => $address->barangay,
            'postalCode' => $address->postal_code,
            'label' => $address->label,
            'isDefault' => (bool) $address->is_default,
            'isComplete' => $address->isRoutable(),
            'pin' => $address->mapPin(),
            'lastUsedAt' => $address->last_used_at?->toIso8601String(),
        ];
    }
}
