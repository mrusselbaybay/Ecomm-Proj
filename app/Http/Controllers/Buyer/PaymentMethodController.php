<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StorePaymentMethodRequest;
use App\Http\Requests\Buyer\UpdatePaymentMethodRequest;
use App\Models\BuyerPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buyer's saved payment methods (buyer_payment_methods). Scoped to the
 * authenticated buyer. Only non-sensitive/tokenised fields are ever
 * accepted or returned — see StorePaymentMethodRequest.
 */
class PaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $methods = BuyerPaymentMethod::query()
            ->where('buyer_profile_id', $request->user()->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $methods->map(fn (BuyerPaymentMethod $m) => $this->transform($m)),
        ]);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $data = $request->validated();

        $method = DB::transaction(function () use ($buyer, $data) {
            $existingCount = BuyerPaymentMethod::where('buyer_profile_id', $buyer->id)->count();
            $makePrimary = (bool) ($data['is_primary'] ?? false) || $existingCount === 0;

            if ($makePrimary) {
                BuyerPaymentMethod::where('buyer_profile_id', $buyer->id)->update(['is_primary' => false]);
            }

            return BuyerPaymentMethod::create([
                'buyer_profile_id' => $buyer->id,
                'type' => $data['type'],
                'brand' => $data['type'] === 'card' ? ($data['brand'] ?? null) : null,
                'last4' => $data['type'] === 'card' ? ($data['last4'] ?? null) : null,
                'holder' => $data['type'] === 'card' ? ($data['holder'] ?? null) : null,
                'exp_month' => $data['type'] === 'card' ? ($data['exp_month'] ?? null) : null,
                'exp_year' => $data['type'] === 'card' ? ($data['exp_year'] ?? null) : null,
                'provider' => $data['type'] === 'wallet' ? ($data['provider'] ?? null) : null,
                'phone_masked' => $data['type'] === 'wallet' ? ($data['phone_masked'] ?? null) : null,
                'label' => $data['label'] ?? null,
                'is_primary' => $makePrimary,
            ]);
        });

        return response()->json(['data' => $this->transform($method)], 201);
    }

    public function update(UpdatePaymentMethodRequest $request, string $id): JsonResponse
    {
        $method = $this->findForBuyer($request, $id);

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($request, $method, $data) {
            $method->fill(collect($data)->only(['holder', 'exp_month', 'exp_year', 'label'])->all());

            if (array_key_exists('is_primary', $data) && $data['is_primary']) {
                BuyerPaymentMethod::where('buyer_profile_id', $request->user()->id)
                    ->whereKeyNot($method->id)
                    ->update(['is_primary' => false]);
                $method->is_primary = true;
            }

            $method->save();
        });

        return response()->json(['data' => $this->transform($method->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $method = $this->findForBuyer($request, $id);

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        $wasPrimary = $method->is_primary;

        DB::transaction(function () use ($request, $method, $wasPrimary) {
            $method->delete();

            if ($wasPrimary) {
                $next = BuyerPaymentMethod::where('buyer_profile_id', $request->user()->id)
                    ->orderByDesc('created_at')
                    ->first();

                $next?->update(['is_primary' => true]);
            }
        });

        return response()->json(['message' => 'Payment method removed.']);
    }

    public function setPrimary(Request $request, string $id): JsonResponse
    {
        $method = $this->findForBuyer($request, $id);

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        DB::transaction(function () use ($request, $method) {
            BuyerPaymentMethod::where('buyer_profile_id', $request->user()->id)->update(['is_primary' => false]);
            $method->update(['is_primary' => true]);
        });

        return response()->json(['data' => $this->transform($method->fresh())]);
    }

    private function findForBuyer(Request $request, string $id): ?BuyerPaymentMethod
    {
        return BuyerPaymentMethod::where('buyer_profile_id', $request->user()->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(BuyerPaymentMethod $method): array
    {
        if ($method->type === 'wallet') {
            return [
                'id' => $method->id,
                'type' => 'wallet',
                'provider' => $method->provider,
                'phoneMasked' => $method->phone_masked,
                'label' => $method->label,
                'isPrimary' => (bool) $method->is_primary,
            ];
        }

        return [
            'id' => $method->id,
            'type' => 'card',
            'brand' => $method->brand,
            'last4' => $method->last4,
            'holder' => $method->holder,
            'expMonth' => $method->exp_month,
            'expYear' => $method->exp_year,
            'label' => $method->label,
            'isPrimary' => (bool) $method->is_primary,
        ];
    }
}
