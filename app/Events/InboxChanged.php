<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Your conversation list changed" (new conversation, new last message,
 * unread counts) — each recipient's inbox re-syncs its list.
 */
class InboxChanged implements ShouldBroadcastNow
{
    /** @param  list<string>  $profileIds */
    public function __construct(
        public readonly array $profileIds,
        public readonly string $conversationId,
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel("inbox.{$id}"), $this->profileIds);
    }

    public function broadcastAs(): string
    {
        return 'inbox.changed';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
