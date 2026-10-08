<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCompany;
use App\Models\SellerPayout;
use App\Services\Payments\CashFlowReport;
use App\Services\Payments\PaymentSplitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mock-escrow cash flow for the signed-in party (seller / logistics company / platform). */
class CashFlowController extends Controller
{
    public function __construct(private CashFlowReport $report) {}

    public function seller(Request $request): JsonResponse
    {
        $sellerId = $request->user()->id;
        $report = $this->report->for([PaymentSplitter::ACCOUNT_SELLER], $sellerId);
        $totals = SellerPayout::query()
            ->where('seller_id', $sellerId)
            ->whereYear('created_at', now()->year)
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross')
            ->selectRaw('COALESCE(SUM(withholding_tax), 0) AS withholding')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS net')
            ->first();

        $report['payouts'] = [
            'year' => now()->year,
            'gross_amount' => number_format((float) $totals->gross, 2, '.', ''),
            'withholding_tax' => number_format((float) $totals->withholding, 2, '.', ''),
            'net_amount' => number_format((float) $totals->net, 2, '.', ''),
        ];

        return response()->json($report);
    }

    public function logistics(Request $request): JsonResponse
    {
        $company = LogisticsCompany::query()->forMember($request->user()->id)->firstOrFail();

        return response()->json($this->report->for([
            PaymentSplitter::ACCOUNT_ORIGIN,
            PaymentSplitter::ACCOUNT_LEG,
            PaymentSplitter::ACCOUNT_LAST_MILE,
        ], $company->id));
    }

    public function platform(): JsonResponse
    {
        return response()->json($this->report->for([PaymentSplitter::ACCOUNT_PLATFORM], null));
    }
}
