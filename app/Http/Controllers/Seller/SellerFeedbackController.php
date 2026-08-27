<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\RespondToReviewRequest;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
 * pipeline behind it), "helpful" vote counts, or a "reported" state —
 * none of those are backed by real columns/tables, and fabricating
 * numbers for them would violate the whole point of connecting this
 * page to real data. See the project summary for what's missing.
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

        if (!$review) {
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
            'data' => $this->transform($review->fresh(['product', 'buyer', 'orderItem.order'])),
        ]);
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

        $reviews = $query->orderByDesc('created_at')->get();

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['Date', 'Product', 'Variant', 'Order #', 'Buyer', 'Rating', 'Comment', 'Status', 'Seller Response', 'Responded At']);

        foreach ($reviews as $review) {
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
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'feedback-export-' . now()->format('Y-m-d') . '.csv';

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
        $query = Review::with(['product', 'buyer', 'orderItem.order'])->where('seller_id', $sellerId);

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
            'sellerResponse' => $review->seller_response,
            'respondedAt' => optional($review->responded_at)->toIso8601String(),
            'responseEditedAt' => optional($review->response_edited_at)->toIso8601String(),
            'createdAt' => optional($review->created_at)->toIso8601String(),
            'date' => optional($review->created_at)->format('F d, Y'),
        ];
    }

    private function initialsFor(?string $name): string
    {
        if (!$name) {
            return '?';
        }

        $parts = preg_split('/\s+/', trim($name));
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first . $last) ?: '?';
    }
}