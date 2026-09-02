<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\ReportReviewRequest;
use App\Http\Requests\Seller\RespondToReviewRequest;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backs resources/js/seller/components/Feedback.vue via
 * resources/js/seller/composables/useFeedback.js, following the same
 * pattern as SellerOrderController/SellerProductController: every query
 * is scoped by seller_id so a seller can only ever read or respond to
 * reviews attached to their own products, and never learns whether a
 * review outside that scope exists (404s, not 403s — see respond()
 * below, matching SellerOrderController::show()'s comment on why).
 *
 * Deliberately does NOT expose: sentiment (no real sentiment-analysis
 * pipeline behind it) or "helpful" vote counts — neither is backed by a
 * real column/table, and fabricating numbers for them would violate the
 * whole point of connecting this page to real data.
 *
 * "Report inappropriate review" (spec section 7) IS backed now, by the
 * review_reports table — see report(). It's a seller-facing flag plus a
 * Log::warning; there is no admin branch in this repo to build the
 * moderation side against yet, so a reported review just shows an
 * "under review" state to the seller until that exists.
 *
 * "Average rating per product" (spec section 7) is served by products().
 */
class SellerFeedbackController extends Controller
{
    private const DEFAULT_PER_PAGE = 10;

    private const MAX_PER_PAGE = 50;

    private const TREND_WINDOW_DAYS = 30;

    /**
     * GET /api/seller/feedback
     *
     * Query params (all optional): search, rating (1-5), status
     * (responded|unanswered|low_rating), sort
     * (newest|oldest|highest|lowest), date_from, date_to (Y-m-d),
     * page, per_page.
     *
     * "Most helpful" is intentionally not a sort option — there is no
     * helpful-vote column/table to sort by.
     */
    public function index(Request $request): JsonResponse
    {
        $seller = $request->user();

        // Built once without the status filter so the tab counts below
        // and the actual list agree on what "matches the current
        // search/rating/date range" means, and so a status count is never
        // computed against a query that's already been narrowed by a
        // *different* status.
        $base = $this->commonFilters($seller->id, $request);

        $statusCounts = [
            'all' => (clone $base)->count(),
            'unanswered' => (clone $base)->whereNull('seller_response')->count(),
            'lowRating' => (clone $base)->where('rating', '<=', 2)->count(),
            'responded' => (clone $base)->whereNotNull('seller_response')->count(),
        ];

        $query = $this->applyStatus(clone $base, $request->string('status')->toString());

        match ($request->string('sort')->toString() ?: 'newest') {
            'oldest' => $query->orderBy('created_at'),
            'highest' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            'lowest' => $query->orderBy('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $perPage = min((int) ($request->integer('per_page') ?: self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $paginated = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Review $r) => $this->transform($r))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'perPage' => $paginated->perPage(),
                'total' => $paginated->total(),
                'statusCounts' => $statusCounts,
            ],
        ]);
    }

    /**
     * GET /api/seller/feedback/summary
     *
     * Deliberately ignores search/rating/status/date filters — these
     * headline numbers describe the seller's whole review population,
     * not whatever subset happens to be showing in the filtered list.
     *
     * `trend` is only present when there's a full prior 30-day window to
     * compare against (both periods have at least one review) — an
     * empty or partial comparison period would produce a meaningless or
     * wildly misleading percentage, so it's omitted rather than shown
     * with fake confidence.
     */
    public function summary(Request $request): JsonResponse
    {
        $seller = $request->user();

        $reviews = Review::where('seller_id', $seller->id)
            ->get(['id', 'rating', 'seller_response', 'created_at', 'responded_at']);
        $total = $reviews->count();

        $ratingDistribution = [];
        for ($star = 5; $star >= 1; $star--) {
            $count = $reviews->where('rating', $star)->count();
            $ratingDistribution[] = [
                'rating' => $star,
                'count' => $count,
                'percent' => $total > 0 ? round($count / $total * 100, 1) : 0,
            ];
        }

        $responded = $reviews->whereNotNull('seller_response');
        $respondedCount = $responded->count();
        $unansweredCount = $total - $respondedCount;
        $lowRatingUnansweredCount = $reviews
            ->whereNull('seller_response')
            ->filter(fn (Review $r) => $r->rating <= 2)
            ->count();

        $avgResponseHours = null;
        if ($respondedCount > 0) {
            $totalHours = $responded->sum(fn (Review $r) => $r->created_at->diffInMinutes($r->responded_at) / 60);
            $avgResponseHours = round($totalHours / $respondedCount, 1);
        }

        return response()->json([
            'data' => [
                'totalReviews' => $total,
                'overallRating' => $total > 0 ? round($reviews->avg('rating'), 1) : null,
                'ratingDistribution' => $ratingDistribution,
                'respondedCount' => $respondedCount,
                'unansweredCount' => $unansweredCount,
                'lowRatingUnansweredCount' => $lowRatingUnansweredCount,
                'responseRate' => $total > 0 ? round($respondedCount / $total * 100, 1) : 0,
                'avgResponseTimeHours' => $avgResponseHours,
                'trend' => $this->computeTrend($seller->id),
            ],
        ]);
    }

    /**
     * PUT /api/seller/feedback/{id}/respond
     *
     * Handles both the first response and edits to an existing one.
     * `responded_at` is set once (first response only) so avg-response-
     * time keeps measuring "time to first reply"; a later edit stamps
     * `response_edited_at` instead so the UI can label it "Edited"
     * without disturbing that metric.
     *
     * Re-submitting the exact same request twice (e.g. a doubled click
     * before the button's loading state kicked in) is naturally
     * idempotent — the second call just overwrites with the same text —
     * rather than creating a duplicate.
     */
    public function respond(RespondToReviewRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();

        $review = Review::where('seller_id', $seller->id)->where('id', $id)->first();

        if (! $review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        if (is_null($review->seller_response)) {
            $review->responded_at = now();
        } else {
            $review->response_edited_at = now();
        }

        $review->seller_response = $request->validated('response');
        $review->responded_by = $seller->id;
        $review->save();

        return response()->json([
            'data' => $this->transform($review->fresh(['product', 'buyer', 'orderItem.order', 'reports'])),
        ]);
    }

    /**
     * POST /api/seller/feedback/{id}/report
     *
     * Flags a review on one of the seller's own products as
     * inappropriate. Idempotent per (review, seller): re-submitting while
     * a report is still 'pending' just updates the reason/details rather
     * than stacking duplicates. Once an (as-yet-hypothetical) admin tool
     * has moved the report to a terminal status, this returns 409 instead
     * of silently reopening it.
     *
     * A Log::warning is emitted so the report is visible in logs until
     * that admin surface exists — same approach as
     * MessageController::report().
     */
    public function report(ReportReviewRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();

        $review = Review::where('seller_id', $seller->id)->where('id', $id)->first();

        if (! $review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $existing = ReviewReport::where('review_id', $review->id)
            ->where('seller_id', $seller->id)
            ->first();

        if ($existing && ! $existing->isOpen()) {
            return response()->json([
                'message' => 'This review has already been reviewed by our moderation team.',
                'data' => $this->transform($review->fresh(['product', 'buyer', 'orderItem.order', 'reports'])),
            ], 409);
        }

        $report = $existing ?? new ReviewReport([
            'review_id' => $review->id,
            'seller_id' => $seller->id,
        ]);
        $report->reason = $request->validated('reason');
        $report->details = $request->validated('details');
        $report->status = 'pending';
        $report->save();

        Log::warning('Seller reported a review as inappropriate.', [
            'review_id' => $review->id,
            'seller_id' => $seller->id,
            'product_id' => $review->product_id,
            'reason' => $report->reason,
            'report_id' => $report->id,
        ]);

        return response()->json([
            'data' => $this->transform($review->fresh(['product', 'buyer', 'orderItem.order', 'reports'])),
        ]);
    }

    /**
     * GET /api/seller/feedback/products
     *
     * Average rating + review volume broken down by product, for the
     * seller's whole catalogue of reviewed products. Aggregated in PHP
     * off a single seller-scoped fetch (the same shape summary() uses) —
     * no per-product queries, and no SQL-dialect assumptions. Ignores the
     * list filters for the same reason summary() does: this describes the
     * whole population, not the current filtered view.
     */
    public function products(Request $request): JsonResponse
    {
        $seller = $request->user();

        $reviews = Review::where('seller_id', $seller->id)
            ->get(['id', 'product_id', 'product_name', 'rating', 'seller_response', 'created_at']);

        $productIds = $reviews->pluck('product_id')->filter()->unique()->values();
        $products = $productIds->isNotEmpty()
            ? Product::whereIn('id', $productIds)->get(['id', 'name', 'images'])->keyBy('id')
            : collect();

        $data = $reviews
            ->groupBy(fn (Review $r) => $r->product_id ?? '__none__')
            ->map(function ($group, $key) use ($products) {
                $productId = $key === '__none__' ? null : $key;
                $product = $productId ? $products->get($productId) : null;
                $count = $group->count();
                $lastReviewAt = $group->max('created_at');

                return [
                    'productId' => $productId,
                    'name' => $product?->name
                        ?? $group->firstWhere('product_name', '!=', null)?->product_name
                        ?? 'Unknown product',
                    'image' => ($product?->images ?? [])[0]['url'] ?? null,
                    'reviewCount' => $count,
                    'averageRating' => round($group->avg('rating'), 2),
                    'unansweredCount' => $group->whereNull('seller_response')->count(),
                    'positiveCount' => $group->where('rating', '>=', 4)->count(),
                    'negativeCount' => $group->where('rating', '<=', 2)->count(),
                    'lastReviewAt' => optional($lastReviewAt)->toIso8601String(),
                ];
            })
            ->sortByDesc('reviewCount')
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/seller/feedback/export
     *
     * Same filters as index() (search/rating/status/date range, minus
     * pagination) streamed out as CSV. Built with fputcsv over a
     * php://temp stream rather than pulling in a CSV package — Laravel's
     * stdlib already covers this.
     */
    public function export(Request $request): Response
    {
        $seller = $request->user();

        $query = $this->applyStatus(
            $this->commonFilters($seller->id, $request),
            $request->string('status')->toString(),
        );

        /** @var Collection<int, Review> $reviews */
        $reviews = $query->orderByDesc('created_at')->get();

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['Date', 'Product', 'Variant', 'Order #', 'Buyer', 'Rating', 'Comment', 'Status', 'Seller Response', 'Responded At', 'Reported']);

        foreach ($reviews as $review) {
            $report = $review->relationLoaded('reports') ? $review->reports->first() : null;

            fputcsv($handle, [
                $review->created_at?->format('Y-m-d H:i'),
                $review->product_name ?? $review->product?->name,
                $review->orderItem?->variant,
                $review->orderItem?->order?->order_number,
                $review->buyer?->full_name ?? 'Anonymous',
                $review->rating,
                $review->comment,
                $review->is_responded ? ($review->is_edited ? 'Responded (edited)' : 'Responded') : 'Unanswered',
                $review->seller_response,
                $review->responded_at?->format('Y-m-d H:i'),
                $report ? ($report->isOpen() ? 'Reported (under review)' : 'Reported ('.$report->status.')') : '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'feedback-export-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Seller-scoped query with search/rating/date-range applied, but NOT
     * status — kept separate so index() can compute all four status tab
     * counts off the exact same base (via clone) without them drifting
     * out of sync with each other or with export().
     */
    private function commonFilters(string $sellerId, Request $request): Builder
    {
        $query = Review::with([
            'product', 'buyer', 'orderItem.order',
            // Scoped here so a seller only ever sees their own report on a
            // review, never another seller's (can't happen today given a
            // review belongs to one seller, but keeps the invariant explicit).
            'reports' => fn ($q) => $q->where('seller_id', $sellerId)->latest(),
        ])->where('seller_id', $sellerId);

        if ($productId = $request->string('product_id')->toString()) {
            $query->where('product_id', $productId);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'ilike', "%{$search}%")
                    ->orWhere('comment', 'ilike', "%{$search}%")
                    ->orWhereHas('buyer', function ($bq) use ($search) {
                        $bq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('orderItem.order', function ($oq) use ($search) {
                        $oq->where('order_number', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($rating = $request->integer('rating')) {
            $query->where('rating', $rating);
        }

        if ($dateFrom = $request->string('date_from')->toString()) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->toString()) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    private function applyStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'responded' => $query->whereNotNull('seller_response'),
            'unanswered' => $query->whereNull('seller_response'),
            'low_rating' => $query->where('rating', '<=', 2),
            default => $query,
        };
    }

    private function computeTrend(string $sellerId): ?array
    {
        $now = now();
        $periodStart = $now->copy()->subDays(self::TREND_WINDOW_DAYS);
        $prevStart = $now->copy()->subDays(self::TREND_WINDOW_DAYS * 2);

        $current = Review::where('seller_id', $sellerId)
            ->where('created_at', '>=', $periodStart)
            ->get(['rating']);

        $previous = Review::where('seller_id', $sellerId)
            ->whereBetween('created_at', [$prevStart, $periodStart])
            ->get(['rating']);

        if ($current->isEmpty() || $previous->isEmpty()) {
            return null;
        }

        return [
            'windowDays' => self::TREND_WINDOW_DAYS,
            'reviewCountChange' => $current->count() - $previous->count(),
            'ratingChange' => round($current->avg('rating') - $previous->avg('rating'), 2),
        ];
    }

    private function transform(Review $review): array
    {
        return [
            'id' => $review->id,
            'product' => [
                'id' => $review->product_id,
                'name' => $review->product_name ?? $review->product?->name,
                // Matches the shape Inventory.vue already expects
                // (product.images[0].url), not a bare string.
                'image' => ($review->product?->images ?? [])[0]['url'] ?? null,
                'variant' => $review->orderItem?->variant,
            ],
            'orderNumber' => $review->orderItem?->order?->order_number,
            'buyer' => [
                'name' => $review->buyer?->full_name ?? 'Anonymous',
                'initials' => $this->initialsFor($review->buyer?->full_name),
            ],
            'rating' => $review->rating,
            'comment' => $review->comment,
            'images' => $review->images ?? [],
            'isResponded' => $review->is_responded,
            'isEdited' => $review->is_edited,
            'report' => $this->reportFor($review),
            'sellerResponse' => $review->seller_response,
            'respondedAt' => optional($review->responded_at)->toIso8601String(),
            'responseEditedAt' => optional($review->response_edited_at)->toIso8601String(),
            'createdAt' => optional($review->created_at)->toIso8601String(),
            'date' => optional($review->created_at)->format('F d, Y'),
        ];
    }

    /**
     * The seller's own report on this review, if any — read only from the
     * already-scoped `reports` relation (loaded in commonFilters /
     * respond), never a fresh query. `null` when the review has not been
     * reported.
     */
    private function reportFor(Review $review): ?array
    {
        if (! $review->relationLoaded('reports')) {
            return null;
        }

        $report = $review->reports->first();

        if (! $report) {
            return null;
        }

        return [
            'status' => $report->status,
            'reason' => $report->reason,
            'details' => $report->details,
            'isOpen' => $report->isOpen(),
            'reportedAt' => optional($report->created_at)->toIso8601String(),
        ];
    }

    private function initialsFor(?string $name): string
    {
        if (! $name) {
            return '?';
        }

        $parts = preg_split('/\s+/', trim($name));
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: '?';
    }
}
