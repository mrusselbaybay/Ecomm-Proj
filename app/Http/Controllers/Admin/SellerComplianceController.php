<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSellerComplianceActionRequest;
use App\Mail\SellerComplianceNotice;
use App\Models\Product;
use App\Models\Profile;
use App\Models\SellerComplianceAction;
use App\Models\StatusAuditLog;
use App\Services\SellerNotifier;
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
                'latestModeration',
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

        return response()->json([
            'products' => $products,
            'summary' => [
                'total' => Product::query()->count(),
                'active' => Product::query()->where('status', 'active')->count(),
                'pending' => Product::query()->where('status', 'pending_review')->count(),
                'archived' => Product::query()->where('status', 'archived')->count(),
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

        // Idempotency: a double-submit (double-click, a retried request)
        // must not create a second compliance-history row for an outcome
        // that's already in effect.
        if ($action === 'verify' && $product->status === 'active') {
            return response()->json(['message' => 'Product is already verified and active.']);
        }

        if ($action === 'remove' && $product->status === 'archived') {
            return response()->json(['message' => 'Product is already removed.']);
        }

        if ($action === 'suspend' && $seller->account_status === 'suspended') {
            return response()->json(['message' => 'Seller is already suspended.']);
        }

        $complianceAction = DB::transaction(fn () => $this->recordAction(
            $product,
            $seller,
            $action,
            $reason,
            $data['notes'] ?? null,
            $request->user()->id,
        ));

        // Notifications only after the transaction has committed (see
        // SellerNotifier's own docblock for why).
        $this->notifyForAction($product->fresh(), $seller->fresh(), $action, $reason, $complianceAction->id);

        return response()->json([
            'message' => match ($action) {
                'verify' => 'Product verified and made active.',
                'warn' => 'Product flagged and the seller was notified.',
                'remove' => 'Product moved to the archive and the seller was notified.',
                'restore' => 'Product restored to the pending review queue.',
                'suspend' => 'Seller suspended and notified.',
            },
        ]);
    }

    /**
     * POST /api/admin/compliance/products/verify-all
     *
     * Bulk-verifies every product currently sitting at pending_review
     * (optionally narrowed by the same search filter the Compliance page
     * itself is using), running each one through the exact same
     * recordAction()/notifyForAction() path a single manual Verify does —
     * same compliance-history row, same notification, just looped.
     * Already-active products are skipped (idempotent against a repeat
     * click or a race with an AI auto-approval that just landed).
     */
    public function verifyAll(Request $request): JsonResponse
    {
        $query = Product::query()->where('status', 'pending_review')->with('seller');

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

        $products = $query->get()->filter(fn (Product $product) => $product->seller?->role === 'seller');

        if ($products->isEmpty()) {
            return response()->json([
                'message' => 'No products currently require verification.',
                'verified' => 0,
            ]);
        }

        $adminId = $request->user()->id;

        $processed = DB::transaction(function () use ($products, $adminId) {
            return $products->map(fn (Product $product) => [
                'product' => $product,
                'compliance_action' => $this->recordAction(
                    $product,
                    $product->seller,
                    'verify',
                    null,
                    'Bulk-verified via Verify All.',
                    $adminId,
                ),
            ]);
        });

        foreach ($processed as $entry) {
            $this->notifyForAction(
                $entry['product']->fresh(),
                $entry['product']->seller,
                'verify',
                null,
                $entry['compliance_action']->id,
            );
        }

        return response()->json([
            'message' => $processed->count().' product(s) verified.',
            'verified' => $processed->count(),
        ]);
    }

    /**
     * Applies one compliance action's side effects (product/seller status
     * change + the audit trail row) and returns the created
     * SellerComplianceAction — the single place both store() and
     * verifyAll() go through, so "verified via Verify All" and "verified
     * individually" are always the same code path with the same result.
     */
    private function recordAction(
        Product $product,
        Profile $seller,
        string $action,
        ?string $reason,
        ?string $notes,
        ?string $adminId,
    ): SellerComplianceAction {
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
                'changed_by' => $adminId,
            ]);
        }

        return SellerComplianceAction::create([
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'action' => $action,
            'reason' => $reason,
            'notes' => $notes,
            'admin_id' => $adminId,
        ]);
    }

    /**
     * Fires the seller-facing side effects of a compliance action: the
     * in-app notification (App\Services\SellerNotifier — the same inbox
     * powering order notifications) plus, for the actions that already
     * had it, the compliance-notice email. `restore` has no seller-facing
     * notification — it just reopens the review queue, nothing changed
     * for the seller yet. `$eventId` is the compliance action's own id,
     * used as the notification's dedupe key.
     */
    private function notifyForAction(
        Product $product,
        Profile $seller,
        string $action,
        ?string $reason,
        string $eventId,
    ): void {
        $notifier = app(SellerNotifier::class);

        match ($action) {
            'warn' => $notifier->productFlagged($product, $reason, $eventId),
            'verify' => $notifier->productVerified($product, $eventId),
            'remove' => $notifier->productRemoved($product, $reason, $eventId),
            'suspend' => $notifier->sellerSuspended($seller, $reason, $eventId),
            default => null,
        };

        if (in_array($action, ['verify', 'warn', 'remove', 'suspend'], true)) {
            Mail::to($seller->email)->queue(new SellerComplianceNotice(
                sellerName: $seller->full_name,
                productName: $product->name,
                action: $action,
                reason: $reason,
            ));
        }
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
                    'admin' => $action->admin_id ? $action->admin?->full_name : 'AI Moderation',
                ])
                ->values(),
            'moderation' => $product->latestModeration ? [
                'ai_status' => $product->latestModeration->ai_status->value,
                'confidence_score' => (float) $product->latestModeration->ai_confidence_score,
                'flagged_signals' => $product->latestModeration->ai_flagged_signals,
                'reasoning' => $product->latestModeration->ai_reasoning,
                'final_status' => $product->latestModeration->final_status->value,
                'needs_human_review' => $product->latestModeration->needs_human_review,
                'reviewed_at' => $product->latestModeration->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
