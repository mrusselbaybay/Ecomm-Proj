<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ParcelLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/logistics/orders/{orderNumber}/location
 *
 * Ingest for real courier GPS pings. A courier client (mobile app / device)
 * posts its current position for an order it is carrying; OrderTrackingService
 * then serves the newest ping as the live parcel position and the recent
 * pings as a trail.
 *
 * SCOPING CAVEAT: verifying that the caller is actually the courier assigned
 * to this order needs the parcel_assignments table (rider_profile_id /
 * logistics_company_id), which lives on the feature/pickup_courier branch and
 * isn't modeled here. Until it is, this only checks that the caller is an
 * authenticated logistics-side user. That is fine for now because no real
 * courier client exists yet — `tracking:simulate` writes pings directly to
 * the table without going through this endpoint. When assignments land, add
 * the "caller is on this delivery" check here.
 */
class ParcelLocationController extends Controller
{
    private const LOGISTICS_ROLES = ['courier', 'driver', 'logistics_admin', 'logistics'];

    public function store(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, self::LOGISTICS_ROLES, true)) {
            return response()->json(['message' => 'Only logistics accounts can post locations.'], 403);
        }

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'recorded_at' => ['nullable', 'date'],
            'speed_kph' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'heading' => ['nullable', 'integer', 'between:0,359'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $order = Order::where('order_number', ltrim($orderNumber, '#'))->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $ping = ParcelLocation::create([
            'order_id' => $order->id,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'recorded_at' => $data['recorded_at'] ?? now(),
            'source' => $user->role,
            'speed_kph' => $data['speed_kph'] ?? null,
            'heading' => $data['heading'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'id' => $ping->id,
                'recordedAt' => $ping->recorded_at->toIso8601String(),
            ],
        ], 201);
    }
}
