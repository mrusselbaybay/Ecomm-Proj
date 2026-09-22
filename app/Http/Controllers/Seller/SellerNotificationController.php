<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The seller's notification inbox (header bell). Scoped to the
 * authenticated seller like every other seller controller.
 */
class SellerNotificationController extends Controller
{
    /**
     * GET /api/seller/notifications?filter=unread&page=
     */
    public function index(Request $request): JsonResponse
    {
        $seller = $request->user();

        $query = SellerNotification::where('seller_id', $seller->id)
            ->orderByDesc('created_at');

        if ($request->string('filter')->toString() === 'unread') {
            $query->whereNull('read_at');
        }

        $paginated = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (SellerNotification $n) => $this->transform($n))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'unread' => SellerNotification::where('seller_id', $seller->id)->whereNull('read_at')->count(),
            ],
        ]);
    }

    /**
     * GET /api/seller/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'count' => SellerNotification::where('seller_id', $request->user()->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    /**
     * PUT /api/seller/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = SellerNotification::where('seller_id', $request->user()->id)
            ->whereKey($id)
            ->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $this->transform($notification)]);
    }

    /**
     * PUT /api/seller/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        SellerNotification::where('seller_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }

    private function transform(SellerNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data ?? [],
            'orderNumber' => $n->data['orderNumber'] ?? null,
            'read' => $n->read_at !== null,
            'createdAt' => optional($n->created_at)->toIso8601String(),
        ];
    }
}
