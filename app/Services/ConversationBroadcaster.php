<?php

namespace App\Services;

use App\Events\ConversationMessageCreated;
use App\Events\InboxChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pushes live chat updates over Reverb. Best-effort: a WebSocket server
 * that's down must never fail sending a message — clients also resync on
 * reconnect and on their own polling.
 */
class ConversationBroadcaster
{
    private const UNREACHABLE_KEY = 'reverb_unreachable';

    public function messageCreated(string $conversationId, string $messageId): void
    {
        $this->send(new ConversationMessageCreated($conversationId, $messageId));
        $this->inboxChanged($conversationId);
    }

    /** @param  list<string>|null  $profileIds  defaults to every active participant */
    public function inboxChanged(string $conversationId, ?array $profileIds = null): void
    {
        $profileIds ??= DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->whereNull('left_at')
            ->pluck('user_id')
            ->all();

        if ($profileIds) {
            $this->send(new InboxChanged(array_values(array_unique($profileIds)), $conversationId));
        }
    }

    private function send(object $event): void
    {
        // After a failure, skip broadcasting for a while instead of paying
        // the connect timeout on every message.
        if (Cache::has(self::UNREACHABLE_KEY)) {
            return;
        }

        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Cache::put(self::UNREACHABLE_KEY, true, 30);
            report($e);
        }
    }
}
