<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Product;
use App\Models\Profile;
use App\Services\BuyerStoreBlocks;
use App\Services\SellerNotifier;
use App\Support\MarketplaceReportReasons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MarketplaceSafetyController extends Controller
{
    public function __construct(private BuyerStoreBlocks $blocks, private SellerNotifier $sellerNotifier) {}

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['product', 'store'])],
            'target_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:80'],
            'details' => ['required', 'string', 'max:2000'],
            'anonymous' => ['sometimes', 'boolean'],
            'evidence' => ['sometimes', 'array', 'max:8'],
            'evidence.*' => ['string', 'max:500', 'regex:/^[A-Za-z0-9_\/-]+\.[A-Za-z0-9]{2,8}$/'],
        ]);

        $reasons = $data['target_type'] === 'product'
            ? MarketplaceReportReasons::PRODUCT
            : MarketplaceReportReasons::STORE;
        abort_unless(isset($reasons[$data['reason']]), 422, 'Choose a valid report reason.');

        if ($data['target_type'] === 'product') {
            $product = Product::query()->with('seller.sellerDetail')->findOrFail($data['target_id']);
            $seller = $product->seller;
            abort_if(! $seller || $seller->id === $request->user()->id, 422, 'You cannot report your own listing.');
            $productId = $product->id;
            $storeId = null;
            $subject = 'Product report: '.$product->name;
            $about = 'Product: '.$product->name;
        } else {
            $seller = Profile::query()->with('sellerDetail')->whereKey($data['target_id'])->where('role', 'seller')->firstOrFail();
            abort_if($seller->id === $request->user()->id, 422, 'You cannot report your own shop.');
            $productId = null;
            $storeId = $seller->id;
            $storeName = $seller->sellerDetail?->business_name ?: $seller->full_name;
            $subject = 'Shop report: '.$storeName;
            $about = 'Shop: '.$storeName;
        }

        $hasOpenReport = Complaint::query()
            ->where('complainant_id', $request->user()->id)
            ->where('report_reason', $data['reason'])
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->when($productId, fn ($query) => $query->where('target_product_id', $productId))
            ->when($storeId, fn ($query) => $query->where('target_store_id', $storeId))
            ->exists();
        abort_if($hasOpenReport, 422, 'You already have an open report for this reason.');

        $dismissedReports = Complaint::query()
            ->where('complainant_id', $request->user()->id)
            ->whereNotNull('report_reason')
            ->where('status', 'dismissed')
            ->where('created_at', '>=', now()->subDays(90))
            ->count();
        $priority = MarketplaceReportReasons::priority($data['reason']);
        if ($dismissedReports >= 5) {
            $priority = ['urgent' => 'high', 'high' => 'normal', 'normal' => 'low', 'low' => 'low'][$priority];
        }

        $targetName = $productId ? $product->name : $storeName;
        $sellerName = $productId
            ? ($seller->sellerDetail?->business_name ?: $seller->full_name)
            : $storeName;

        $sellerHoldApplied = false;
        $complaint = DB::transaction(function () use ($request, $data, $productId, $storeId, $seller, $subject, $about, $targetName, $sellerName, $dismissedReports, $priority, &$sellerHoldApplied): Complaint {
            $complaint = Complaint::query()->create([
                'complainant_id' => $request->user()->id,
                'respondent_id' => $seller->id,
                'target_product_id' => $productId,
                'target_store_id' => $storeId,
                'report_reason' => $data['reason'],
                'reporter_is_anonymous' => (bool) ($data['anonymous'] ?? false),
                'reporter_name_snapshot' => $request->user()->full_name,
                'target_name_snapshot' => $targetName,
                'seller_name_snapshot' => $sellerName,
                'reporter_dismissed_count' => $dismissedReports,
                'urgent_review_due_at' => $priority === 'urgent' ? now()->addHours(4) : null,
                'type' => $productId ? 'product_report' : 'store_report',
                'subject' => $subject,
                'description' => $about."\nReason: ".($productId ? MarketplaceReportReasons::PRODUCT : MarketplaceReportReasons::STORE)[$data['reason']]
                    .(blank($data['details'] ?? null) ? '' : "\n\nReporter details:\n".trim($data['details'])),
                'evidence' => $data['evidence'] ?? [],
                'priority' => $priority,
            ]);

            if ($priority === 'urgent') {
                if ($productId) {
                    $sellerHoldApplied = ! (bool) Product::query()->whereKey($productId)->lockForUpdate()->value('report_hold');
                    Product::query()->whereKey($productId)->update(['report_hold' => true]);
                } else {
                    $sellerHoldApplied = ! (bool) DB::table('seller_details')->where('profile_id', $storeId)->lockForUpdate()->value('report_hold');
                    DB::table('seller_details')->where('profile_id', $storeId)->update(['report_hold' => true]);
                }
            }

            return $complaint;
        });

        if ($sellerHoldApplied) {
            $this->sellerNotifier->notify(
                sellerId: $seller->id,
                type: 'report_visibility_hold',
                title: 'Listing temporarily hidden',
                body: 'A listing in your shop has been temporarily hidden from discovery while BuyTheWay reviews a report. Reviews are prioritized for a prompt decision. No action is needed unless our team contacts you.',
                data: ['complaintId' => $complaint->id, 'targetType' => $productId ? 'product' : 'store'],
                dedupeKey: "report_visibility_hold:{$complaint->id}",
            );
        }

        return response()->json(['data' => ['id' => $complaint->id, 'priority' => $complaint->priority]], 201);
    }

    public function block(Request $request, string $storeId): JsonResponse
    {
        $store = Profile::query()->whereKey($storeId)->where('role', 'seller')->firstOrFail();
        abort_if($store->id === $request->user()->id, 422, 'You cannot block your own shop.');

        $this->blocks->block($request->user(), $store->id);

        return response()->json(['message' => 'Shop blocked. Its listings and social chat are hidden from you.']);
    }

    public function blockedStores(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->blocks->sellerIds($request->user()->id)]);
    }

    public function reportNotifications(Request $request): JsonResponse
    {
        $rows = DB::table('notifications')
            ->where('notifiable_type', Profile::class)
            ->where('notifiable_id', $request->user()->id)
            ->where('type', 'report_terminal_update')
            ->latest()
            ->limit(20)
            ->get(['id', 'data', 'created_at']);

        return response()->json(['data' => $rows->map(fn ($row): array => [
            'id' => $row->id,
            ...((array) json_decode($row->data, true)),
            'created_at' => $row->created_at,
        ])]);
    }
}
