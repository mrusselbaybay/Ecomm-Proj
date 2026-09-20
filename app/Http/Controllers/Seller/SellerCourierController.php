<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\LogisticsCompany;
use Illuminate\Http\JsonResponse;

class SellerCourierController extends Controller
{
    /**
     * GET /api/seller/couriers
     *
     * Real logistics companies registered on the platform (public.
     * logistics_companies), filtered to ones actually able to take
     * shipments — approved by an admin (`status`) and not suspended/
     * deactivated (`account_status`) — same two-column check
     * EnsureUserIsSeller already applies to a seller's own profile.
     * Feeds the Courier/Carrier dropdown on Prepare Shipment and
     * Courier Handover instead of a free-text field, so a seller picks
     * from real registered couriers rather than typing an arbitrary
     * name that may not match any company on file.
     */
    public function index(): JsonResponse
    {
        $couriers = LogisticsCompany::query()
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->orderBy('company_name')
            ->get(['id', 'company_name'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->company_name,
            ])
            ->values();

        return response()->json(['data' => $couriers]);
    }
}
