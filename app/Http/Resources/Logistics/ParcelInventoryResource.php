<?php

namespace App\Http\Resources\Logistics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Logistics mobile app's Inventory feature (both the "Inventory" and
 * "For Inventory" tabs, plus a scanned parcel's detail screen) — see
 * Api\Logistics\ParcelInventoryController. Unlike ParcelAssignmentResource
 * (built for the web sorting queue's phase-toggling card), this always
 * surfaces both the seller and buyer sides together, plus the
 * dimensions/weight and pickup-courier/inventory-scan audit fields the
 * Inventory spec calls for.
 */
class ParcelInventoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->order;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'is_transfer' => (bool) $this->is_transfer,
            'is_transfer_receipt' => (bool) $this->previous_assignment_id,
            'inventory_origin' => $this->inventory_origin,
            'tracking_number' => $order?->tracking_number,
            'order_number' => $order?->order_number,
            'buyer' => [
                'name' => $order?->recipient_name,
                'contact_no' => $order?->recipient_contact_no,
                'address' => collect([
                    $order?->shipping_house_no,
                    $order?->shipping_street,
                    $order?->shipping_barangay,
                    $order?->shipping_municipality_name,
                    $order?->shipping_province_name,
                ])->filter()->implode(', '),
            ],
            'seller' => [
                'name' => $order?->seller?->full_name,
                'contact_no' => $order?->seller?->contact_no,
                'shop_name' => $order?->seller?->sellerDetail?->business_name,
                'address' => collect([
                    $order?->pickup_barangay,
                    $order?->pickup_municipality_name,
                    $order?->pickup_province_name,
                ])->filter()->implode(', '),
            ],
            'courier' => $this->whenLoaded('pickedUpBy', fn (): ?array => $this->pickedUpBy ? [
                'id' => $this->pickedUpBy->id,
                'first_name' => $this->pickedUpBy->first_name,
                'last_name' => $this->pickedUpBy->last_name,
                'contact_no' => $this->pickedUpBy->contact_no,
            ] : null),
            'items' => ($order?->items ?? collect())->map(fn ($item): array => [
                'name' => $item->product_name,
                'variant' => $item->variant ?: ($item->variant_sku ?: null),
                'sku' => $item->sku ?: $item->variant_sku,
                'quantity' => (int) $item->quantity,
                'dimensions' => $item->product?->dimensions,
                'weight' => $item->product?->weight !== null ? (float) $item->product->weight : null,
            ])->values()->all(),
            'transfer_to_company' => $this->whenLoaded('transferToCompany', fn (): ?array => $this->transferToCompany ? [
                'id' => $this->transferToCompany->id,
                'company_name' => $this->transferToCompany->company_name,
            ] : null),
            'barangay_assignment' => $this->whenLoaded('barangayAssignment', fn (): ?array => $this->barangayAssignment ? [
                'id' => $this->barangayAssignment->id,
                'municipality_name' => $this->barangayAssignment->municipality_name,
                'barangay' => $this->barangayAssignment->barangay,
            ] : null),
            'rider' => $this->whenLoaded('rider', fn (): ?array => $this->rider ? [
                'id' => $this->rider->id,
                'first_name' => $this->rider->first_name,
                'last_name' => $this->rider->last_name,
                'contact_no' => $this->rider->contact_no,
            ] : null),
            // The inventory audit trail — when it landed here, when/who
            // scanned it, and which company (hub) processed it. Kept even
            // after `status` moves on past STATUS_FOR_INVENTORY, since
            // this resource's index() query is keyed on
            // inventory_scanned_at, not the current status.
            'for_inventory_at' => $this->for_inventory_at?->toISOString(),
            'inventory_scanned_at' => $this->inventory_scanned_at?->toISOString(),
            'inventory_scanned_by' => $this->whenLoaded('inventoryScannedBy', fn (): ?array => $this->inventoryScannedBy ? [
                'id' => $this->inventoryScannedBy->id,
                'first_name' => $this->inventoryScannedBy->first_name,
                'last_name' => $this->inventoryScannedBy->last_name,
            ] : null),
            'hub' => $this->whenLoaded('logisticsCompany', fn (): ?array => $this->logisticsCompany ? [
                'id' => $this->logisticsCompany->id,
                'company_name' => $this->logisticsCompany->company_name,
            ] : null),
            'confirmation_token' => $order?->confirmation_token,
            'has_confirmation_qr' => filled($order?->confirmation_token),
            'handed_off_at' => $this->handed_off_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'transferred_at' => $this->transferred_at?->toISOString(),
        ];
    }
}
