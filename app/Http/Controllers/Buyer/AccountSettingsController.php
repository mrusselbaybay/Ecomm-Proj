<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\UpdateNotificationPreferencesRequest;
use App\Http\Requests\Buyer\UploadAvatarRequest;
use App\Models\BuyerAddress;
use App\Models\BuyerNotificationPreference;
use App\Models\Order;
use App\Models\Profile;
use App\Models\Review;
use App\Models\StoreFollow;
use App\Models\WishlistItem;
use App\Services\FileStorage;
use App\Support\Avatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buyer account settings beyond the profile fields (AccountController):
 * profile photo, optional email preferences, and a copy of the buyer's
 * own data. Every action works on the signed-in buyer only; there is no
 * profile id in any URL.
 */
class AccountSettingsController extends Controller
{
    public function __construct(private FileStorage $storage) {}

    /**
     * POST /api/buyer/account/avatar  (multipart: avatar)
     *
     * The new photo is uploaded before the old one is removed, so a failed
     * upload never leaves the buyer without their previous photo.
     */
    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $file = $request->file('avatar');
        $mime = (string) $file->getMimeType();
        $previous = $buyer->avatar_path;
        $path = $buyer->id.'/'.Str::uuid().'.'.$this->storage->extensionForMime($mime);

        try {
            $this->storage->upload(Avatar::BUCKET, $path, (string) file_get_contents($file->getRealPath()), $mime);
        } catch (\RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Your photo couldn\'t be uploaded. Please try again.'], 502);
        }

        $buyer->forceFill(['avatar_path' => $path])->save();

        if (Avatar::ownsPath($buyer->id, $previous)) {
            $this->storage->delete(Avatar::BUCKET, [$previous]);
        }

        return response()->json([
            'message' => 'Profile photo updated.',
            'data' => ['avatar_url' => $buyer->avatar_url],
        ], 201);
    }

    /**
     * DELETE /api/buyer/account/avatar
     */
    public function destroyAvatar(Request $request): JsonResponse
    {
        $buyer = $request->user();
        $previous = $buyer->avatar_path;

        if ($previous !== null) {
            $buyer->forceFill(['avatar_path' => null])->save();

            if (Avatar::ownsPath($buyer->id, $previous)) {
                $this->storage->delete(Avatar::BUCKET, [$previous]);
            }
        }

        return response()->json(['message' => 'Profile photo removed.', 'data' => ['avatar_url' => null]]);
    }

    /**
     * GET /api/buyer/account/preferences
     */
    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferencesFor($request->user())]);
    }

    /**
     * PUT /api/buyer/account/preferences  { order_updates_email?, promotions_email? }
     */
    public function updatePreferences(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $buyer = $request->user();

        $row = BuyerNotificationPreference::find($buyer->id)
            ?? new BuyerNotificationPreference(array_merge(['buyer_profile_id' => $buyer->id], BuyerNotificationPreference::DEFAULTS));

        $row->fill($request->safe()->only(['order_updates_email', 'promotions_email']))->save();

        return response()->json([
            'message' => 'Preferences saved.',
            'data' => $this->preferencesFor($buyer),
        ]);
    }

    /**
     * GET /api/buyer/account/export
     *
     * Everything BuyTheWay holds that the buyer entered or created, as a
     * JSON download: profile, addresses, orders (with items and status),
     * reviews, wishlist, followed stores and email choices. Only this
     * buyer's own rows; seller and other buyers' details are not included.
     */
    public function export(Request $request): Response
    {
        $buyer = $request->user();

        $orders = Order::query()
            ->with('items')
            ->where('buyer_profile_id', $buyer->id)
            ->orderByDesc('placed_at')
            ->get()
            ->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'placed_at' => $order->placed_at?->toIso8601String(),
                'subtotal' => (float) $order->subtotal,
                'shipping_fee' => (float) $order->shipping_fee,
                'total' => (float) $order->total,
                'recipient_name' => $order->recipient_name,
                'items' => $order->items->map(fn ($item) => [
                    'product_name' => $item->product_name,
                    'variant' => $item->variant,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])->values(),
            ]);

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'first_name' => $buyer->first_name,
                'middle_initial' => $buyer->middle_initial,
                'last_name' => $buyer->last_name,
                'email' => $buyer->email,
                'contact_no' => $buyer->contact_no,
                'sex' => $buyer->sex,
                'birthday' => $buyer->birthday?->toDateString(),
                'joined_at' => $buyer->created_at?->toIso8601String(),
            ],
            'addresses' => BuyerAddress::query()
                ->where('buyer_profile_id', $buyer->id)
                ->get(['recipient_name', 'contact_no', 'line1', 'city', 'province', 'postal_code', 'label', 'is_default']),
            'orders' => $orders,
            'reviews' => Review::query()
                ->where('buyer_id', $buyer->id)
                ->orderByDesc('created_at')
                ->get(['product_name', 'rating', 'comment', 'created_at'])
                ->map(fn (Review $review) => [
                    'product_name' => $review->product_name,
                    'rating' => (int) $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at?->toIso8601String(),
                ]),
            'wishlist' => WishlistItem::query()
                ->with('product:id,name')
                ->where('buyer_profile_id', $buyer->id)
                ->get()
                ->map(fn (WishlistItem $item) => ['product' => $item->product?->name, 'saved_at' => $item->created_at?->toIso8601String()]),
            'followed_stores' => Schema::hasTable('store_follows')
                ? StoreFollow::query()
                    ->with('seller.sellerDetail')
                    ->where('buyer_profile_id', $buyer->id)
                    ->get()
                    ->map(fn (StoreFollow $follow) => [
                        'store' => $follow->seller?->sellerDetail?->business_name,
                        'followed_at' => $follow->created_at?->toIso8601String(),
                    ])
                : [],
            'email_preferences' => $this->preferencesFor($buyer),
        ];

        $filename = 'buytheway-my-data-'.now()->format('Y-m-d').'.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{order_updates_email: bool, promotions_email: bool, saved: bool}
     */
    private function preferencesFor(Profile $buyer): array
    {
        $row = Schema::hasTable('buyer_notification_preferences')
            ? BuyerNotificationPreference::find($buyer->id)
            : null;

        return [
            'order_updates_email' => $row ? $row->order_updates_email : BuyerNotificationPreference::DEFAULTS['order_updates_email'],
            'promotions_email' => $row ? $row->promotions_email : BuyerNotificationPreference::DEFAULTS['promotions_email'],
            'saved' => (bool) $row,
        ];
    }
}
