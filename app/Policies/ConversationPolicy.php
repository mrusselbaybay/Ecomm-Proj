<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Profile;

class ConversationPolicy
{
    public function viewAny(Profile $user): bool
    {
        return $this->hasActiveMessagingAccount($user);
    }

    public function view(Profile $user, Conversation $conversation): bool
    {
        if ($user->role === Profile::ROLE_ADMIN && $conversation->type !== 'support') {
            return false;
        }

        return $conversation->hasActiveParticipant($user->id);
    }

    public function create(Profile $user): bool
    {
        return $this->hasActiveMessagingAccount($user) && $user->role !== Profile::ROLE_ADMIN;
    }

    public function sendMessage(Profile $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $conversation->isWritable()
            && $this->hasValidContext($conversation);
    }

    public function markRead(Profile $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function report(Profile $user, Conversation $conversation): bool
    {
        return $conversation->type !== 'support' && $this->view($user, $conversation);
    }

    public function resolve(Profile $user, Conversation $conversation): bool
    {
        return $conversation->type !== 'support' && $this->view($user, $conversation);
    }

    public function close(Profile $user, Conversation $conversation): bool
    {
        return $conversation->type !== 'support' && $this->view($user, $conversation);
    }

    private function hasActiveMessagingAccount(Profile $user): bool
    {
        return $user->status === 'approved' && $user->account_status === 'active';
    }

    private function hasValidContext(Conversation $conversation): bool
    {
        if ($conversation->type === 'support') {
            return $conversation->support_ticket_id !== null;
        }

        if ($conversation->type === 'product') {
            return $conversation->product_id !== null
                && $conversation->buyer_id !== null
                && $conversation->seller_id !== null
                && $conversation->product?->seller_id === $conversation->seller_id;
        }

        if ($conversation->type === 'order') {
            return $conversation->order_id !== null
                && $conversation->buyer_id !== null
                && $conversation->seller_id !== null
                && $conversation->order?->buyer_profile_id === $conversation->buyer_id
                && $conversation->order?->seller_id === $conversation->seller_id;
        }

        return false;
    }
}
