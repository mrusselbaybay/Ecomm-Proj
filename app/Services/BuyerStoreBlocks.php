<?php

namespace App\Services;

use App\Models\BuyerStoreBlock;
use App\Models\Conversation;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;

class BuyerStoreBlocks
{
    public function isBlocked(string $buyerId, string $sellerId): bool
    {
        return BuyerStoreBlock::query()->where('buyer_id', $buyerId)->where('seller_id', $sellerId)->exists();
    }

    /** @return list<string> */
    public function sellerIds(string $buyerId): array
    {
        return BuyerStoreBlock::query()->where('buyer_id', $buyerId)->pluck('seller_id')->all();
    }

    /** @param Builder<\App\Models\Product> $query */
    public function constrainProducts(Builder $query, ?Profile $buyer): Builder
    {
        $query->whereDoesntHave('seller.sellerDetail', fn (Builder $details) => $details->where('report_hold', true));
        $query->where(fn (Builder $q) => $q->whereNull('products.report_hold')->orWhere('products.report_hold', false));

        if ($buyer) {
            $query->whereNotIn('products.seller_id', BuyerStoreBlock::query()
                ->select('seller_id')->where('buyer_id', $buyer->id));
        }

        return $query;
    }

    /** @param Builder<Profile> $query */
    public function constrainStores(Builder $query, ?Profile $buyer, bool $includeReportHeld = false): Builder
    {
        if (! $includeReportHeld) {
            $query->where(fn (Builder $q) => $q->whereNull('seller_details.report_hold')->orWhere('seller_details.report_hold', false));
        }

        if ($buyer) {
            $query->whereNotIn('profiles.id', BuyerStoreBlock::query()
                ->select('seller_id')->where('buyer_id', $buyer->id));
        }

        return $query;
    }

    public function block(Profile $buyer, string $sellerId): void
    {
        BuyerStoreBlock::query()->firstOrCreate(['buyer_id' => $buyer->id, 'seller_id' => $sellerId]);

        // Archive only social direct threads. Conversations with order context
        // remain available so shipping, fulfillment and disputes can continue.
        Conversation::query()
            ->where('buyer_id', $buyer->id)
            ->where('seller_id', $sellerId)
            ->where('type', 'direct')
            ->whereNull('order_id')
            ->with('participantRecords')
            ->get()
            ->each(fn (Conversation $conversation) => $conversation->archiveFor($buyer->id));
    }
}
