<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCompany;
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
        return response()->json($this->report->for([PaymentSplitter::ACCOUNT_SELLER], $request->user()->id));
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
