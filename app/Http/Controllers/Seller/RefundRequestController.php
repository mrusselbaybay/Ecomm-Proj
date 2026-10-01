<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderReturnRequest;
use App\Services\Payments\RefundRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RefundRequestController extends Controller
{
    private const WITH = ['order:id,order_number', 'orderItem:id,product_name,variant', 'buyer:id,first_name,last_name,email'];

    public function __construct(private RefundRequestService $service) {}

    /** GET /api/seller/refund-requests?status=pending|approved|rejected|all */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])]])['status'] ?? 'pending';
        $sellerId = $request->user()->id;

        $requests = OrderReturnRequest::with(self::WITH)
            ->where('seller_id', $sellerId)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->limit(100)
            ->get();

        $counts = OrderReturnRequest::where('seller_id', $sellerId)
            ->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'data' => [
                'items' => $requests->map(fn (OrderReturnRequest $r) => $this->transform($r)),
                'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ]],
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide(fn () => $this->service->approve($this->find($request, $id), $request->user()));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:10', 'max:500']], [
            'note.required' => 'Please describe why you are declining this request.',
            'note.min' => 'Please describe why you are declining in at least 10 characters.',
        ])['note'];

        return $this->decide(fn () => $this->service->reject($this->find($request, $id), $request->user(), $note));
    }

    /** POST /api/seller/refund-requests/approve-all — each approval is independent. */
    public function approveAll(Request $request): JsonResponse
    {
        $approved = 0;
        $failed = [];

        OrderReturnRequest::with('order:id,order_number')
            ->where('seller_id', $request->user()->id)
            ->where('status', 'pending')
            ->oldest()
            ->get()
            ->each(function (OrderReturnRequest $r) use ($request, &$approved, &$failed) {
                try {
                    $this->service->approve($r, $request->user());
                    $approved++;
                } catch (ValidationException $e) {
                    $failed[] = [
                        'id' => $r->id,
                        'orderNumber' => $r->order?->order_number,
                        'message' => collect($e->errors())->flatten()->first(),
                    ];
                }
            });

        return response()->json(['approved' => $approved, 'failed' => $failed]);
    }

    private function find(Request $request, string $id): OrderReturnRequest
    {
        return OrderReturnRequest::where('seller_id', $request->user()->id)->findOrFail($id);
    }

    private function decide(callable $action): JsonResponse
    {
        try {
            $result = $action();
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json(['data' => $this->transform($result->load(self::WITH))]);
    }

    private function transform(OrderReturnRequest $r): array
    {
        return [
            'id' => $r->id,
            'orderNumber' => $r->order?->order_number,
            'productName' => $r->orderItem?->product_name ?? 'Item',
            'variant' => $r->orderItem?->variant,
            'buyerName' => trim(($r->buyer?->first_name ?? '').' '.($r->buyer?->last_name ?? '')) ?: ($r->buyer?->email ?? 'Buyer'),
            'requestType' => $r->request_type,
            'reason' => $r->reason,
            'reasonLabel' => $r->reasonLabel(),
            'details' => $r->details,
            'quantity' => $r->quantity,
            'amount' => (float) $r->estimated_amount,
            'refundedAmount' => $r->refunded_amount !== null ? (float) $r->refunded_amount : null,
            'evidence' => $r->evidence ?? [],
            'status' => $r->status,
            'resolutionNote' => $r->resolution_note,
            'returnShippingFee' => $r->return_shipping_fee !== null ? (float) $r->return_shipping_fee : null,
            'returnedAt' => optional($r->returned_at)->toIso8601String(),
            'submittedAt' => optional($r->created_at)->toIso8601String(),
        ];
    }
}
