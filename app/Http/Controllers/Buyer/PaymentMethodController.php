<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\BuyerPaymentMethod;
use App\Support\CheckoutOptions;
use App\Support\SavedPaymentSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The buyer's saved payment methods (buyer_payment_methods), always scoped
 * to the signed-in buyer.
 *
 * A method is only usable — chargeable, eligible as the default — when it
 * carries a provider-issued reference and a provider integration is
 * enabled (SavedPaymentSupport). Rows saved earlier by typing card details
 * into BuyTheWay itself have no such reference: they are listed honestly
 * as unusable and can only be removed.
 *
 * Nothing here accepts card or wallet details. Removing a method never
 * touches orders: orders record their own payment method.
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
            'data' => $methods->map(fn (BuyerPaymentMethod $m) => $this->transform($m))->values(),
            'meta' => [
                'saving' => SavedPaymentSupport::toArray(),
                'checkoutMethods' => CheckoutOptions::toArray()['payment'],
            ],
        ]);
    }

    /**
     * Makes a usable method the default. Only changes a preference: no
     * payment is started.
     */
    public function setPrimary(Request $request, string $id): JsonResponse
    {
        $method = $this->findForBuyer($request, $id);

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        if (! $this->isUsable($method)) {
            return response()->json([
                'message' => 'This method isn’t connected to a payment provider, so it can’t be your default.',
            ], 422);
        }

        DB::transaction(function () use ($request, $method) {
            BuyerPaymentMethod::where('buyer_profile_id', $request->user()->id)
                ->whereKeyNot($method->id)
                ->update(['is_primary' => false]);
            $method->update(['is_primary' => true]);
        });

        return response()->json(['data' => $this->transform($method->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $method = $this->findForBuyer($request, $id);

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        DB::transaction(function () use ($request, $method) {
            $wasDefault = $method->is_primary;

            $method->delete();

            // The newest usable method takes over as default; unusable
            // ones never do.
            if ($wasDefault) {
                BuyerPaymentMethod::where('buyer_profile_id', $request->user()->id)
                    ->orderByDesc('created_at')
                    ->get()
                    ->first(fn (BuyerPaymentMethod $m) => $this->isUsable($m))
                    ?->update(['is_primary' => true]);
            }
        });

        return response()->json(['message' => 'Payment method removed.']);
    }

    private function findForBuyer(Request $request, string $id): ?BuyerPaymentMethod
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return BuyerPaymentMethod::where('buyer_profile_id', $request->user()->id)
            ->whereKey($id)
            ->first();
    }

    private function isExpired(BuyerPaymentMethod $method): bool
    {
        if ($method->type !== 'card' || ! $method->exp_year || ! $method->exp_month) {
            return false;
        }

        return now()->startOfMonth()->gt(now()->setDate((int) $method->exp_year, (int) $method->exp_month, 1)->startOfMonth());
    }

    private function isUsable(BuyerPaymentMethod $method): bool
    {
        return SavedPaymentSupport::enabled()
            && filled($method->provider_token)
            && ! $this->isExpired($method);
    }

    /**
     * Display details only — never the provider reference.
     *
     * @return array<string, mixed>
     */
    private function transform(BuyerPaymentMethod $method): array
    {
        $usable = $this->isUsable($method);
        $expired = $this->isExpired($method);

        return [
            'id' => $method->id,
            'type' => $method->type,
            'brand' => $method->type === 'card' ? $method->brand : null,
            'last4' => $method->type === 'card' ? $method->last4 : null,
            'expMonth' => $method->type === 'card' ? $method->exp_month : null,
            'expYear' => $method->type === 'card' ? $method->exp_year : null,
            'walletName' => $method->type === 'wallet' ? $method->provider : null,
            'walletId' => $method->type === 'wallet' ? $method->phone_masked : null,
            'expired' => $expired,
            'usable' => $usable,
            'isDefault' => $usable && (bool) $method->is_primary,
            'unusableReason' => $usable ? null : match (true) {
                $expired => 'expired',
                blank($method->provider_token) => 'not_linked',
                default => 'provider_unavailable',
            },
            'addedAt' => optional($method->created_at)->toIso8601String(),
        ];
    }
}
