<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateComplaintRequest;
use App\Mail\ComplaintStatusChanged;
use App\Models\Complaint;
use App\Models\ComplaintUpdate;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Complaint::query()->with([
            'complainant:id,first_name,last_name,email,role',
            'respondent:id,first_name,last_name,email,role',
            'assignedAdmin:id,first_name,last_name',
            'order:id,order_number',
        ]);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($priority = $request->string('priority')->toString()) {
            $query->where('priority', $priority);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($query) use ($search): void {
                $query->where('subject', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhereHas('complainant', function ($profileQuery) use ($search): void {
                        $profileQuery->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('order', fn ($orderQuery) => $orderQuery
                        ->where('order_number', 'ilike', "%{$search}%"));
            });
        }

        $complaints = $query
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Complaint $complaint): array => $this->complaintData($complaint));

        return response()->json([
            'complaints' => $complaints,
            'summary' => [
                'open' => Complaint::query()->whereNotIn('status', ['resolved', 'dismissed'])->count(),
                'pending' => Complaint::query()->where('status', 'pending')->count(),
                'under_review' => Complaint::query()->whereIn('status', ['under_review', 'awaiting_response'])->count(),
                'resolved' => Complaint::query()->where('status', 'resolved')->count(),
            ],
        ]);
    }

    public function show(Complaint $complaint): JsonResponse
    {
        $complaint->load([
            'complainant:id,first_name,last_name,email,contact_no,role',
            'respondent:id,first_name,last_name,email,contact_no,role',
            'assignedAdmin:id,first_name,last_name',
            'order:id,order_number,status,total',
            'updates.admin:id,first_name,last_name',
        ]);

        return response()->json([
            'complaint' => $this->complaintData($complaint, true),
            'admins' => Profile::query()
                ->where('role', Profile::ROLE_ADMIN)
                ->where('account_status', 'active')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Profile $admin): array => [
                    'id' => $admin->id,
                    'full_name' => $admin->full_name,
                ]),
        ]);
    }

    public function update(UpdateComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $complaint->loadMissing(['complainant', 'respondent']);
        $data = $request->validated();
        $oldStatus = $complaint->status;
        $newStatus = $data['status'];

        if ($oldStatus !== $newStatus && ! $complaint->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "A complaint cannot move from {$oldStatus} to {$newStatus}.",
            ], 422);
        }

        DB::transaction(function () use ($request, $complaint, $data, $oldStatus, $newStatus): void {
            $complaint->update([
                'status' => $newStatus,
                'priority' => $data['priority'],
                'assigned_admin_id' => $data['assigned_admin_id'] ?? null,
                'resolution' => $data['resolution'] ?? null,
                'resolved_at' => $newStatus === 'resolved' ? now() : null,
            ]);

            ComplaintUpdate::create([
                'complaint_id' => $complaint->id,
                'admin_id' => $request->user()->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $data['notes'],
                'is_internal' => $data['is_internal'],
            ]);
        });

        if (! $data['is_internal']) {
            $recipients = collect([$complaint->complainant, $complaint->respondent])
                ->filter()
                ->pluck('email')
                ->filter()
                ->unique();

            foreach ($recipients as $email) {
                Mail::to($email)->queue(new ComplaintStatusChanged(
                    complaintSubject: $complaint->subject,
                    status: $newStatus,
                    notes: $data['notes'],
                    resolution: $data['resolution'] ?? null,
                ));
            }
        }

        return response()->json([
            'message' => $data['is_internal']
                ? 'Internal case update saved.'
                : 'Case updated and participant notifications were queued.',
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
            'created_at' => $complaint->created_at?->toIso8601String(),
            'complainant' => $this->profileData($complaint->complainant),
            'respondent' => $this->profileData($complaint->respondent),
            'assigned_admin' => $complaint->assignedAdmin ? [
                'id' => $complaint->assignedAdmin->id,
                'full_name' => $complaint->assignedAdmin->full_name,
            ] : null,
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
}
