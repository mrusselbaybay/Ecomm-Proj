<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSellerComplianceActionRequest;
use App\Mail\SellerComplianceNotice;
use App\Models\Product;
use App\Models\SellerComplianceAction;
use App\Models\StatusAuditLog;
use App\Support\CategoryMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SellerComplianceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with([
                'seller:id,first_name,last_name,email,account_status',
                'seller.sellerDetail',
                'complianceActions' => fn ($query) => $query
                    ->with('admin:id,first_name,last_name')
                    ->latest()
                    ->limit(5),
            ]);

        $status = $request->string('status')->toString();
        $showHistory = $request->boolean('history');

        if ($showHistory) {
            $query->whereHas('complianceActions', function ($actionQuery): void {
                $actionQuery->whereIn('action', ['verify', 'remove']);
            });
        } else {
            $query->where('status', '!=', 'archived');
            $query->where(function ($reviewQuery): void {
                $reviewQuery->where('status', '!=', 'active')
                    ->orWhereDoesntHave('complianceActions', function ($actionQuery): void {
                        $actionQuery->where('action', 'verify');
                    });
            });
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhere('category', 'ilike', "%{$search}%")
                    ->orWhereHas('seller', function ($sellerQuery) use ($search): void {
                        $sellerQuery->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        if (! $showHistory && $status !== '') {
            $query->where('status', $status);
        }

        if ($categoryState = $request->string('category_state')->toString()) {
            $registeredCategory = CategoryMatcher::sqlCase('seller_details.line_of_business');
            $productCategory = CategoryMatcher::sqlCase('products.category');

            if ($categoryState === 'match') {
                $query->whereHas('seller.sellerDetail', function ($detailQuery) use ($registeredCategory, $productCategory): void {
                    $detailQuery->whereRaw(
                        "{$registeredCategory['sql']} = {$productCategory['sql']}",
                        [...$registeredCategory['bindings'], ...$productCategory['bindings']],
                    );
                });
            }

            if ($categoryState === 'mismatch') {
                $query->whereHas('seller.sellerDetail', function ($detailQuery) use ($registeredCategory, $productCategory): void {
                    $detailQuery->whereRaw(
                        "{$registeredCategory['sql']} != {$productCategory['sql']}",
                        [...$registeredCategory['bindings'], ...$productCategory['bindings']],
                    );
                });
            }
        }

        $products = $query
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product): array => $this->productData($product));

        // One conditional-aggregation query replaces four separate COUNT(*)
        // scans over the products table.
        $productCounts = Product::query()
            ->selectRaw(
                'count(*) as total, '
                ."coalesce(sum(case when status = 'active' then 1 else 0 end), 0) as active, "
                ."coalesce(sum(case when status = 'pending_review' then 1 else 0 end), 0) as pending, "
                ."coalesce(sum(case when status = 'archived' then 1 else 0 end), 0) as archived",
            )
            ->first();

        return response()->json([
            'products' => $products,
            'summary' => [
                'total' => (int) $productCounts->total,
                'active' => (int) $productCounts->active,
                'pending' => (int) $productCounts->pending,
                'archived' => (int) $productCounts->archived,
                'warnings' => SellerComplianceAction::query()
                    ->where('action', 'warn')
                    ->count(),
            ],
        ]);
    }

    public function store(
        StoreSellerComplianceActionRequest $request,
        Product $product,
    ): JsonResponse {
        $product->loadMissing('seller.sellerDetail');

        if (! $product->seller || $product->seller->role !== 'seller') {
            return response()->json([
                'message' => 'This product is not associated with a seller account.',
            ], 422);
        }

        $data = $request->validated();
        $action = $data['action'];
        $reason = $data['reason'] ?? null;
        $seller = $product->seller;

        DB::transaction(function () use (
            $request,
            $product,
            $seller,
            $data,
            $action,
            $reason,
        ): void {
            if ($action === 'verify') {
                $product->update(['status' => 'active']);
            }

            if ($action === 'remove') {
                $product->update(['status' => 'archived']);
            }

            if ($action === 'restore') {
                $product->update(['status' => 'pending_review']);
            }

            if ($action === 'suspend') {
                $oldStatus = $seller->account_status;
                $seller->update(['account_status' => 'suspended']);

                StatusAuditLog::create([
                    'entity_type' => 'profile',
                    'entity_id' => $seller->id,
                    'old_status' => $oldStatus,
                    'new_status' => 'suspended',
                    'reason' => "Seller compliance violation: {$reason}",
                    'changed_by' => $request->user()->id,
                ]);
            }

            SellerComplianceAction::create([
                'seller_id' => $seller->id,
                'product_id' => $product->id,
                'action' => $action,
                'reason' => $reason,
                'notes' => $data['notes'] ?? null,
                'admin_id' => $request->user()->id,
            ]);
        });

        if (in_array($action, ['warn', 'remove', 'suspend'], true)) {
            Mail::to($seller->email)->queue(new SellerComplianceNotice(
                sellerName: $seller->full_name,
                productName: $product->name,
                action: $action,
                reason: $reason,
            ));
        }

        return response()->json([
            'message' => match ($action) {
                'verify' => 'Product verified and made active.',
                'warn' => 'Warning recorded and queued for email delivery.',
                'remove' => 'Product moved to the archive and the seller was notified.',
                'restore' => 'Product restored to the pending review queue.',
                'suspend' => 'Seller suspended and notified.',
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(Product $product): array
    {
        $registeredCategory = $product->seller?->sellerDetail?->line_of_business;
        $categoryMatches = CategoryMatcher::matches($registeredCategory, $product->category);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category,
            'status' => $product->status,
            'price' => $product->price,
            'images' => $product->images,
            'created_at' => $product->created_at?->toIso8601String(),
            'category_matches' => (bool) $categoryMatches,
            'registered_category' => $registeredCategory,
            'seller' => $product->seller ? [
                'id' => $product->seller->id,
                'full_name' => $product->seller->full_name,
                'email' => $product->seller->email,
                'account_status' => $product->seller->account_status,
                'business_name' => $product->seller->sellerDetail?->business_name,
            ] : null,
            'compliance_actions' => $product->complianceActions
                ->map(fn (SellerComplianceAction $action): array => [
                    'id' => $action->id,
                    'action' => $action->action,
                    'reason' => $action->reason,
                    'notes' => $action->notes,
                    'created_at' => $action->created_at?->toIso8601String(),
                    'admin' => $action->admin?->full_name,
                ])
                ->values(),
        ];
    }
}
