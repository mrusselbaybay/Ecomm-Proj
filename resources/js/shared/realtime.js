/*
|--------------------------------------------------------------------------
| Supabase Realtime helpers — messaging
|--------------------------------------------------------------------------
|
| Thin wrappers around supabase-js's postgres_changes channels, shared by
| buyer/seller/logistics messaging composables. Each role already has its
| own `getSupabase()` singleton client (useBuyerSession.js / useSeller.js /
| useLogistics.js) carrying that user's real Supabase session — pass it in
| rather than creating a client here.
|
| These deliberately don't try to hand the caller a ready-to-render message.
| A postgres_changes payload is the raw `messages` row (no signed attachment
| URLs, no sender display fields) — reshaping it correctly here would
| duplicate each backend's transformMessage()/transformParcel() logic and
| risk drifting from it. Instead every subscription is just a "something
| changed, go fetch the delta" trigger: the caller re-runs its existing
| `?after=<cursor>` fetch (already incremental, already deduped) or its
| existing conversation-list sync, and this module only decides *when* to
| call it.
|
*/

/**
 * Subscribe to new messages INSERTed into one conversation. `onInsert` is
 * called (debounced) for every INSERT event; `onReconnect` fires when the
 * channel reaches SUBSCRIBED after having previously dropped (network
 * blip, tab resume, etc.) — NOT on the very first connect, since the
 * caller already does its own initial fetch before subscribing.
 *
 * Returns `{ unsubscribe }`.
 */
export function subscribeToConversationMessages(supabase, conversationId, { onInsert, onReconnect, debounceMs = 150 } = {}) {
    if (!supabase || !conversationId) {
        return { unsubscribe() {} };
    }

    let debounceTimer = null;
    let hasConnectedOnce = false;
    let destroyed = false;

    function debouncedInsert() {
        if (!onInsert) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(onInsert, debounceMs);
    }

    const channel = supabase
        .channel(`messages:${conversationId}`)
        .on(
            'postgres_changes',
            { event: 'INSERT', schema: 'public', table: 'messages', filter: `conversation_id=eq.${conversationId}` },
            debouncedInsert,
        )
        .subscribe(status => {
            if (destroyed) return;

            if (status === 'SUBSCRIBED') {
                if (hasConnectedOnce) {
                    onReconnect?.();
                }
                hasConnectedOnce = true;
            }
        });

    return {
        unsubscribe() {
            destroyed = true;
            clearTimeout(debounceTimer);
            supabase.removeChannel(channel);
        },
    };
}

/**
 * Subscribe to conversation-row changes (new conversations, last_message_*
 * / unread_count updates on existing ones) for the inbox list. No
 * column-equality filter is possible here (a user can be the buyer, the
 * seller, the logistics owner, or a courier participant — there's no single
 * FK to filter on across roles), so this relies entirely on the table's
 * Postgres RLS policy to only deliver rows the signed-in user actually
 * participates in.
 */
export function subscribeToInbox(supabase, { onChange, onReconnect, debounceMs = 150 } = {}) {
    if (!supabase) {
        return { unsubscribe() {} };
    }

    let debounceTimer = null;
    let hasConnectedOnce = false;
    let destroyed = false;

    function debouncedChange() {
        if (!onChange) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(onChange, debounceMs);
    }

    const channel = supabase
        .channel('inbox:conversations')
        .on(
            'postgres_changes',
            { event: 'INSERT', schema: 'public', table: 'conversations' },
            debouncedChange,
        )
        .on(
            'postgres_changes',
            { event: 'UPDATE', schema: 'public', table: 'conversations' },
            debouncedChange,
        )
        .subscribe(status => {
            if (destroyed) return;

            if (status === 'SUBSCRIBED') {
                if (hasConnectedOnce) {
                    onReconnect?.();
                }
                hasConnectedOnce = true;
            }
        });

    return {
        unsubscribe() {
            destroyed = true;
            clearTimeout(debounceTimer);
            supabase.removeChannel(channel);
        },
    };
}
