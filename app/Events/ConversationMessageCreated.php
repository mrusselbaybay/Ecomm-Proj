<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "A message landed in this conversation" — open threads re-fetch their
 * `?after=<cursor>` delta; the payload is deliberately just ids.
 */
class ConversationMessageCreated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }
}
