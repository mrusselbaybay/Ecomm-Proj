<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\CourierApplication;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentConversationService
{
    public function findOrCreate(Profile $seller, string $parcelAssignmentId): Conversation
    {
        $assignment = ParcelAssignment::query()
            ->with(['order', 'logisticsCompany.owner'])
            ->whereKey($parcelAssignmentId)
            ->whereHas('order', fn ($query) => $query->where('seller_id', $seller->id))
            ->first();

        $company = $assignment?->logisticsCompany;
        $logistics = $company?->owner;
        $riderBelongsToCompany = $assignment?->rider_profile_id !== null
            && CourierApplication::query()
                ->where('logistics_company_id', $company?->id)
                ->where('courier_profile_id', $assignment?->rider_profile_id)
                ->where('status', CourierApplication::STATUS_ACCEPTED)
                ->exists();

        if (
            ! $assignment
            || ! $company
            || ! $logistics
            || ! $riderBelongsToCompany
            || ! in_array($company->region, ['Luzon', 'Visayas', 'Mindanao'], true)
            || $company->status !== 'approved'
            || $company->account_status !== 'active'
            || $logistics->role !== 'logistics'
            || $logistics->status !== 'approved'
            || $logistics->account_status !== 'active'
        ) {
            throw ValidationException::withMessages([
                'parcel_assignment_id' => 'This parcel does not have an eligible assigned logistics contact.',
            ]);
        }

        $contextKey = Conversation::makeContextKey('shipment', $assignment->id, [$seller->id, $logistics->id]);

        return DB::transaction(function () use ($assignment, $company, $contextKey, $logistics, $seller): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['context_key' => $contextKey],
                [
                    'type' => 'shipment',
                    'created_by' => $seller->id,
                    'seller_id' => $seller->id,
                    'order_id' => $assignment->order_id,
                    'parcel_assignment_id' => $assignment->id,
                    'logistics_company_id' => $company->id,
                    'subject' => 'Shipment '.$assignment->order?->order_number,
                    'status' => 'open',
                ],
            );

            foreach ([$seller->id, $logistics->id] as $participantId) {
                ConversationParticipant::query()->firstOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $participantId],
                    ['joined_at' => now()],
                );
            }

            return $conversation;
        });
    }
}
