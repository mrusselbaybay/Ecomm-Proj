/*
|--------------------------------------------------------------------------
| Realtime helpers — messaging (Laravel Reverb via Echo)
|--------------------------------------------------------------------------
|
| Shared by buyer/seller/logistics messaging. Each subscription is only a
| "something changed, go fetch the delta" trigger: the caller re-runs its
| existing `?after=<cursor>` fetch or conversation-list sync, and this
| module only decides *when* to call it. Events carry ids, never message
| content (see App\Events\ConversationMessageCreated / InboxChanged).
|
| `client` is the page's backend client (shared/backendClient.js); its
| session token authorizes the private channels.
|
*/

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;
let echoToken = null;

function getEcho(client) {
    const token = client?.auth?.token?.();

    if (!token || !import.meta.env.VITE_REVERB_APP_KEY) {
        return null;
    }

    // A new sign-in means a new token: reconnect with it.
    if (echo && echoToken !== token) {
        echo.disconnect();
        echo = null;
    }

    if (!echo) {
        window.Pusher = Pusher;
        echoToken = token;
        echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/api/broadcasting/auth',
            auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
        });
    }

    return echo;
}

/**
 * Listen on one private channel. `onEvent` is debounced; `onReconnect`
 * fires when the socket comes back after dropping (not on first connect,
 * since callers do their own initial fetch).
 */
function subscribe(client, channelName, eventName, { onEvent, onReconnect, debounceMs }) {
    const instance = getEcho(client);

    if (!instance) {
        return { unsubscribe() {} };
    }

    let debounceTimer = null;
    let destroyed = false;
    let wasDisconnected = false;

    const trigger = () => {
        if (!onEvent) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(onEvent, debounceMs);
    };

    instance.private(channelName).listen(`.${eventName}`, trigger);

    const connection = instance.connector.pusher.connection;
    const onStateChange = ({ current }) => {
        if (destroyed) return;

        if (current === 'connected' && wasDisconnected) {
            wasDisconnected = false;
            onReconnect?.();
        } else if (current === 'unavailable' || current === 'disconnected') {
            wasDisconnected = true;
        }
    };
    connection.bind('state_change', onStateChange);

    return {
        unsubscribe() {
            destroyed = true;
            clearTimeout(debounceTimer);
            connection.unbind('state_change', onStateChange);
            instance.leave(channelName);
        },
    };
}

/** New messages in one conversation. Returns `{ unsubscribe }`. */
export function subscribeToConversationMessages(client, conversationId, { onInsert, onReconnect, debounceMs = 150 } = {}) {
    if (!conversationId) {
        return { unsubscribe() {} };
    }

    return subscribe(client, `conversation.${conversationId}`, 'message.created', {
        onEvent: onInsert,
        onReconnect,
        debounceMs,
    });
}

/** The signed-in user's conversation list changed. Returns `{ unsubscribe }`. */
export function subscribeToInbox(client, { onChange, onReconnect, debounceMs = 150 } = {}) {
    const userId = client?.auth?.userId?.();

    if (!userId) {
        return { unsubscribe() {} };
    }

    return subscribe(client, `inbox.${userId}`, 'inbox.changed', {
        onEvent: onChange,
        onReconnect,
        debounceMs,
    });
}
