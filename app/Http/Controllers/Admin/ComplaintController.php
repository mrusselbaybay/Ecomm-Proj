<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateComplaintRequest;
use App\Mail\ComplaintStatusChanged;
use App\Models\Complaint;
use App\Models\ComplaintUpdate;
use App\Models\BuyerNotificationPreference;
use App\Models\Profile;
use App\Services\SellerNotifier;
use App\Support\MarketplaceReportReasons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComplaintController extends Controller
{
    public function __construct(private SellerNotifier $sellerNotifier) {}

    public function index(Request $request): JsonResponse
    {
        $query = Complaint::query()->with([
            'complainant:id,first_name,last_name,email,role',
            'respondent:id,first_name,last_name,email,role',
            'order:id,order_number',
            'targetProduct:id,name,status,seller_id,report_hold',
            'targetStore.sellerDetail',
            'targetStore:id,first_name,last_name,account_status',
        ]);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($priority = $request->string('priority')->toString()) {
            $query->where('priority', $priority);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($query) use ($search): void {
                $query->whereLike('subject', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%")
                    ->orWhereHas('complainant', function ($profileQuery) use ($search): void {
                        $profileQuery->whereLike('first_name', "%{$search}%")
                            ->orWhereLike('last_name', "%{$search}%")
                            ->orWhereLike('email', "%{$search}%");
                    })
                    ->orWhereHas('order', fn ($orderQuery) => $orderQuery
                        ->whereLike('order_number', "%{$search}%"));
            });
        }

        $complaints = $query
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Complaint $complaint): array => $this->complaintData($complaint));

        // One conditional-aggregation query replaces four separate COUNT(*)
        // scans over the complaints table.
        $summary = Complaint::query()
            ->selectRaw(
                "coalesce(sum(case when status not in ('resolved', 'dismissed') then 1 else 0 end), 0) as open, "
                ."coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending, "
                ."coalesce(sum(case when status in ('under_review', 'awaiting_response') then 1 else 0 end), 0) as under_review, "
                ."coalesce(sum(case when status = 'resolved' then 1 else 0 end), 0) as resolved",
            )
            ->first();

        return response()->json([
            'complaints' => $complaints,
            'summary' => [
                'open' => (int) $summary->open,
                'pending' => (int) $summary->pending,
                'under_review' => (int) $summary->under_review,
                'resolved' => (int) $summary->resolved,
            ],
        ]);
    }

    public function show(Complaint $complaint): JsonResponse
    {
        $complaint->load([
            'complainant:id,first_name,last_name,email,contact_no,role',
            'respondent:id,first_name,last_name,email,contact_no,role',
            'order:id,order_number,status,total',
            'updates.admin:id,first_name,last_name',
            'targetProduct:id,name,status,seller_id,report_hold',
            'targetProduct.seller.sellerDetail',
            'targetStore.sellerDetail',
            'targetStore:id,first_name,last_name,account_status',
        ]);

        return response()->json([
            'complaint' => [
                ...$this->complaintData($complaint, true),
                'report_history' => $this->reportHistory($complaint),
            ],
        ]);
    }

    public function update(UpdateComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validated();
        $newStatus = $data['status'];

        $oldStatus = null;
        $reportHoldCleared = false;
        $complaint = DB::transaction(function () use ($request, $complaint, $data, $newStatus, &$oldStatus, &$reportHoldCleared): Complaint {
            $complaint = Complaint::query()->whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $complaint->status;
            if (in_array($oldStatus, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages(['status' => 'Closed cases are locked.']);
            }
            if ($oldStatus !== $newStatus && ! $complaint->canTransitionTo($newStatus)) {
                throw ValidationException::withMessages(['status' => "A complaint cannot move from {$oldStatus} to {$newStatus}."]);
            }

            $updates = [
                'status' => $newStatus,
                'priority' => $data['priority'],
                'resolution' => $data['resolution'] ?? null,
                'dismissal_reason' => $data['dismissal_reason'] ?? null,
                'resolved_at' => $newStatus === 'resolved' ? now() : null,
            ];
            if (array_key_exists('assigned_admin_id', $data)) {
                $updates['assigned_admin_id'] = $data['assigned_admin_id'];
            }
            $complaint->update($updates);

            ComplaintUpdate::create([
                'complaint_id' => $complaint->id,
                'admin_id' => $request->user()->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $data['notes'],
                'is_internal' => $data['is_internal'],
            ]);

            if ($newStatus === 'dismissed' && $complaint->report_reason) {
                $reportHoldCleared = $this->clearReportHoldIfNoOpenUrgent($complaint);
            }

            return $complaint->loadMissing(['complainant', 'respondent']);
        });

        if ($reportHoldCleared && $complaint->respondent_id) {
            $this->sellerNotifier->notify(
                sellerId: $complaint->respondent_id,
                type: 'report_visibility_restored',
                title: 'Listing visible again',
                body: 'BuyTheWay completed its review and your listing is visible in discovery again.',
                data: ['complaintId' => $complaint->id],
                dedupeKey: "report_visibility_restored:{$complaint->id}",
            );
        }

        if ($oldStatus !== $newStatus && in_array($newStatus, ['resolved', 'dismissed'], true)) {
            $reporter = $complaint->complainant;
            if ($reporter && $reporter->account_status === 'active') {
                $message = $newStatus === 'dismissed'
                    ? 'We completed our review. We did not confirm a policy violation, and no further action is needed from you.'
                    : 'We completed our review. Any changes relevant to your report are now reflected on BuyTheWay.';
                $dedupeKey = "report_terminal:{$complaint->id}:{$newStatus}";
                $inserted = DB::table('notifications')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'type' => 'report_terminal_update',
                    'notifiable_type' => Profile::class,
                    'notifiable_id' => $reporter->id,
                    'data' => json_encode([
                        'title' => $newStatus === 'dismissed' ? 'Report review complete' : 'Report resolved',
                        'message' => $message,
                        'complaint_id' => $complaint->id,
                    ], JSON_THROW_ON_ERROR),
                    'dedupe_key' => $dedupeKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $emailPreference = BuyerNotificationPreference::find($reporter->id)?->case_updates_email
                    ?? BuyerNotificationPreference::DEFAULTS['case_updates_email'];
                if ($inserted && $emailPreference && $reporter->email) {
                    Mail::to($reporter->email)->queue(new ComplaintStatusChanged(notes: $message));
                }
            }
        }

        $terminalTransition = $oldStatus !== $newStatus && in_array($newStatus, ['resolved', 'dismissed'], true);
        return response()->json([
            'message' => $terminalTransition
                ? 'Case closed and the reporter was notified.'
                : ($data['is_internal'] ? 'Internal case update saved.' : 'Case updated.'),
        ]);
    }

    /** @return array<string, mixed> */
    private function complaintData(Complaint $complaint, bool $includeDetails = false): array
    {
        $data = [
            'id' => $complaint->id,
            'type' => $complaint->type,
            'subject' => $complaint->subject,
            'description' => $complaint->description,
            'status' => $complaint->status,
            'priority' => $complaint->priority,
            'report_reason' => $complaint->report_reason,
            'report_reason_label' => $this->reportReasonLabel($complaint),
            'reporter_dismissed_count' => (int) $complaint->reporter_dismissed_count,
            'urgent_review_due_at' => $complaint->urgent_review_due_at?->toIso8601String(),
            'target' => $complaint->targetProduct ? [
                'type' => 'product',
                'id' => $complaint->targetProduct->id,
                'name' => $complaint->target_name_snapshot ?: $complaint->targetProduct->name,
                'current_name' => $complaint->target_name_snapshot && $complaint->target_name_snapshot !== $complaint->targetProduct->name ? $complaint->targetProduct->name : null,
                'status' => $complaint->targetProduct->status,
                'image_url' => collect($complaint->targetProduct->images ?? [])->first(),
                'report_hold' => (bool) $complaint->targetProduct->report_hold,
                'seller_id' => $complaint->targetProduct->seller_id,
                'seller_name' => $complaint->seller_name_snapshot ?: ($complaint->targetProduct->seller?->sellerDetail?->business_name
                    ?: $complaint->targetProduct->seller?->full_name),
                'current_seller_name' => $complaint->seller_name_snapshot && $complaint->seller_name_snapshot !== ($complaint->targetProduct->seller?->sellerDetail?->business_name ?: $complaint->targetProduct->seller?->full_name)
                    ? ($complaint->targetProduct->seller?->sellerDetail?->business_name ?: $complaint->targetProduct->seller?->full_name)
                    : null,
            ] : ($complaint->targetStore ? [
                'type' => 'store',
                'id' => $complaint->targetStore->id,
                'name' => $complaint->target_name_snapshot ?: ($complaint->targetStore->sellerDetail?->business_name
                    ?: $complaint->targetStore->full_name),
                'current_name' => $complaint->target_name_snapshot && $complaint->target_name_snapshot !== ($complaint->targetStore->sellerDetail?->business_name ?: $complaint->targetStore->full_name)
                    ? ($complaint->targetStore->sellerDetail?->business_name ?: $complaint->targetStore->full_name)
                    : null,
                'account_status' => $complaint->targetStore->account_status,
                'report_hold' => (bool) $complaint->targetStore->sellerDetail?->report_hold,
            ] : ($complaint->target_name_snapshot ? [
                'type' => str_contains($complaint->type, 'product') ? 'product' : 'store',
                'id' => $complaint->target_product_id ?: $complaint->target_store_id,
                'name' => $complaint->target_name_snapshot,
                'status' => 'deleted',
                'account_status' => 'deleted',
                'seller_name' => $complaint->seller_name_snapshot,
                'report_hold' => false,
            ] : ($complaint->type === 'store_report' || str_contains($complaint->type, 'store') ? [
                'type' => 'store',
                'id' => $complaint->target_store_id,
                'name' => $complaint->target_name_snapshot ?: 'Deleted shop',
                'account_status' => 'deleted',
                'seller_name' => $complaint->seller_name_snapshot,
                'report_hold' => false,
            ] : ($conversationId = collect($complaint->evidence ?? [])->first(fn ($item) => is_array($item) && ! empty($item['conversation_id']))
                ? [
                    'type' => 'conversation',
                    'id' => $conversationId['conversation_id'],
                    'name' => 'Conversation with '.($complaint->respondent?->full_name ?: 'Deleted user'),
                ]
                : null)))),
            'created_at' => $complaint->created_at?->toIso8601String(),
            'complainant' => $complaint->report_reason ? [
                'full_name' => $complaint->reporter_is_anonymous
                    ? 'Anonymous buyer'
                    : ($complaint->reporter_name_snapshot ?: $complaint->complainant?->full_name ?: 'Deleted user'),
                'current_name' => ! $complaint->reporter_is_anonymous
                    && $complaint->reporter_name_snapshot
                    && $complaint->reporter_name_snapshot !== $complaint->complainant?->full_name
                        ? $complaint->complainant?->full_name
                        : null,
            ] : $this->profileData($complaint->complainant),
            'respondent' => $this->profileData($complaint->respondent),
            'order' => $complaint->order ? [
                'id' => $complaint->order->id,
                'order_number' => $complaint->order->order_number,
            ] : null,
        ];

        if (! $includeDetails) {
            return $data;
        }

        return [
            ...$data,
            'evidence' => $complaint->evidence ?? [],
            'resolution' => $complaint->resolution,
            'dismissal_reason' => $complaint->dismissal_reason,
            'resolved_at' => $complaint->resolved_at?->toIso8601String(),
            'updates' => $complaint->updates->map(fn (ComplaintUpdate $update): array => [
                'id' => $update->id,
                'old_status' => $update->old_status,
                'new_status' => $update->new_status,
                'notes' => $update->notes,
                'is_internal' => $update->is_internal,
                'created_at' => $update->created_at?->toIso8601String(),
                'admin' => $update->admin?->full_name,
            ])->values(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function profileData(?Profile $profile): ?array
    {
        return $profile ? [
            'id' => $profile->id,
            'full_name' => $profile->full_name,
            'email' => $profile->email,
            'contact_no' => $profile->contact_no,
            'role' => $profile->role,
        ] : null;
    }

    /** @return list<array<string, mixed>> */
    private function reportHistory(Complaint $complaint): array
    {
        if (! $complaint->target_product_id && ! $complaint->target_store_id) {
            return [];
        }

        return Complaint::query()
            ->where(function ($query) use ($complaint): void {
                if ($complaint->target_product_id) {
                    $query->where('target_product_id', $complaint->target_product_id);
                } else {
                    $query->where('target_store_id', $complaint->target_store_id);
                }
            })
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Complaint $report): array => [
                'id' => $report->id,
                'reason' => $this->reportReasonLabel($report),
                'priority' => $report->priority,
                'status' => $report->status,
                'created_at' => $report->created_at?->toIso8601String(),
                'reporter' => $report->reporter_is_anonymous
                    ? 'Anonymous buyer'
                    : ($report->reporter_name_snapshot ?: $report->complainant?->full_name ?: 'Deleted user'),
            ])
            ->all();
    }

    private function reportReasonLabel(Complaint $complaint): ?string
    {
        return MarketplaceReportReasons::PRODUCT[$complaint->report_reason]
            ?? MarketplaceReportReasons::STORE[$complaint->report_reason]
            ?? null;
    }

    private function clearReportHoldIfNoOpenUrgent(Complaint $complaint): bool
    {
        $query = Complaint::query()
            ->where('priority', 'urgent')
            ->whereNotIn('status', ['resolved', 'dismissed']);

        if ($complaint->target_product_id) {
            $query->where('target_product_id', $complaint->target_product_id);
            if (! $query->exists()) {
                return \App\Models\Product::query()->whereKey($complaint->target_product_id)->where('report_hold', true)->update(['report_hold' => false]) > 0;
            }
        } elseif ($complaint->target_store_id) {
            $query->where('target_store_id', $complaint->target_store_id);
            if (! $query->exists()) {
                return DB::table('seller_details')->where('profile_id', $complaint->target_store_id)->where('report_hold', true)->update(['report_hold' => false]) > 0;
            }
        }

        return false;
    }
}
