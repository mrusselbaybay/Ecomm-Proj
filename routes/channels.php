<?php

use App\Models\Conversation;
use App\Models\Profile;
use App\Policies\ConversationPolicy;
use Illuminate\Support\Facades\Broadcast;

// An open message thread — only its participants may listen.
Broadcast::channel('conversation.{conversationId}', function (Profile $user, string $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation && app(ConversationPolicy::class)->view($user, $conversation);
});

// A user's own inbox list.
Broadcast::channel('inbox.{profileId}', fn (Profile $user, string $profileId) => $user->id === $profileId);
