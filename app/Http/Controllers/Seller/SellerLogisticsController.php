<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\LogisticsCompany;
use Illuminate\Http\JsonResponse;

/**
 * Read-only list of logistics companies a seller can hand a shipment to —
 * backs the Courier / Carrier dropdown on Prepare Orders / Courier
 * Handover (previously a free-text field, which meant no two sellers
 * necessarily spelled the same courier's name the same way).
 *
 * Scoped to LogisticsCompany::active() (account_status = 'active'): a
 * company still pending admin approval, or since deactivated, isn't a real
 * option for "who is picking this up right now".
 */
class SellerLogisticsController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = LogisticsCompany::active()
            ->orderBy('company_name')
            ->get(['id', 'company_name'])
            ->map(fn (LogisticsCompany $company) => [
                'id' => $company->id,
                'name' => $company->company_name,
            ])
            ->values();

        return response()->json(['data' => $companies]);
    }
}
