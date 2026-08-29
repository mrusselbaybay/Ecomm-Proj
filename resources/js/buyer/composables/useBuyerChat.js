import { computed, ref, watch } from 'vue';

import { buyerApi } from './useBuyerApi';

/*
|--------------------------------------------------------------------------
| useBuyerChat — buyer <-> seller messaging
|--------------------------------------------------------------------------
|
| Backed by the Laravel Buyer API (/api/buyer/messages/* ->
| App\Http\Controllers\Buyer\MessageController, conversations / messages
| tables).
|
| The exported surface is unchanged so Chat.vue and Header.vue need no
| edits. Conversation / message objects are mapped here to the exact
| shape Chat.vue already renders:
|
|   conversation: { id, seller, online, memberSince, unread, updatedAt,
|                   product: { name, price, oldPrice } | null,
|                   messages: [{ id, from: 'buyer'|'seller', text, at }] }
|
| Times are formatted to short labels here (the server returns ISO).
|
| The project has no websocket / Supabase-Realtime wiring, so while the
| popup is open this polls every POLL_MS: it re-fetches the active thread
| (picking up the seller's replies) and refreshes the other threads'
| unread badges. Optimistic "local-" bubbles are preserved across a poll.
| `startConversation` is the entry point used by the "Message Seller"
| buttons on ProductDetails.vue / OrderDetails.vue.
|
*/

const POLL_MS = 15000;

const isChatOpen = ref(false);

const conversations = ref([]);
const isLoading = ref(false);
const loadError = ref('');
const activeConversationId = ref(null);

const unreadCount = ref(0);

let loadedOnce = false;
let inFlight = null;
let unreadPrimed = false;
let pollTimer = null;

const activeConversation = computed(
    () => conversations.value.find(c => c.id === activeConversationId.value) || null,
);

const totalUnread = computed(() => {
    const fromList = conversations.value.reduce((sum, c) => sum + (c.unread || 0), 0);

    return Math.max(fromList, unreadCount.value);
});

/*
|--------------------------------------------------------------------------
| Time formatting (server sends ISO; Chat.vue shows these labels as-is)
|--------------------------------------------------------------------------
*/

function timeOfDay(date) {
    return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function threadTimeLabel(iso) {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const dayMs = 24 * 60 * 60 * 1000;

    if (date >= startOfToday) {
        return timeOfDay(date);
    }

    if (date >= new Date(startOfToday.getTime() - dayMs)) {
        return 'Yesterday';
    }

    if (date >= new Date(startOfToday.getTime() - 6 * dayMs)) {
        return date.toLocaleDateString(undefined, { weekday: 'short' });
    }

    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function mapMessage(message) {
    return {
        id: message.id,
        from: message.from === 'seller' ? 'seller' : 'buyer',
        text: message.text,
        at: threadTimeLabel(message.at) || timeOfDay(new Date()),
    };
}

function mapConversation(conversation) {
    return {
        id: conversation.id,
        seller: conversation.seller || 'NEXMART Seller',
        sellerId: conversation.sellerId || null,
        status: conversation.status || 'open',
        online: Boolean(conversation.online),
        memberSince: conversation.memberSince || null,
        unread: Number(conversation.unread || 0),
        updatedAt: threadTimeLabel(conversation.updatedAt),
        product: conversation.product
            ? {
                name: conversation.product.name,
                price: conversation.product.price,
                oldPrice: conversation.product.oldPrice,
            }
            : null,
        messages: Array.isArray(conversation.messages)
            ? conversation.messages.map(mapMessage)
            : [],
    };
}

// Server messages are authoritative; any optimistic "local-" bubbles not
// yet echoed back are re-appended so a poll / refetch never makes the
// buyer's just-sent message flicker out.
function withPendingLocal(existing, incoming) {
    const pending = (existing?.messages || []).filter(m => String(m.id).startsWith('local-'));

    return { ...incoming, messages: [...incoming.messages, ...pending] };
}

/*
|--------------------------------------------------------------------------
| Load
|--------------------------------------------------------------------------
*/

async function fetchConversations() {
    isLoading.value = true;
    loadError.value = '';

    try {
        const data = await buyerApi('/buyer/messages/conversations');
        conversations.value = (data || []).map(mapConversation);

        if (!activeConversationId.value && conversations.value.length) {
            activeConversationId.value = conversations.value[0].id;
        }

        loadedOnce = true;
    } catch (err) {
        if (err?.status && err.status !== 401) {
            loadError.value = err?.message || 'Could not load your messages.';
        }

        conversations.value = [];
    } finally {
        isLoading.value = false;
    }
}

function loadConversations({ force = false } = {}) {
    if (inFlight) {
        return inFlight;
    }

    if (loadedOnce && !force) {
        return Promise.resolve();
    }

    inFlight = fetchConversations().finally(() => {
        inFlight = null;
    });

    return inFlight;
}

async function refreshUnreadCount() {
    try {
        const data = await buyerApi('/buyer/messages/unread-count');
        unreadCount.value = Number(data?.count || 0);
    } catch (err) {
        // Silent — powers a header badge; keep the last known value.
    }
}

/*
|--------------------------------------------------------------------------
| Polling (while the popup is open)
|--------------------------------------------------------------------------
*/

// Refresh the badges / previews / new threads without disturbing the
// messages already loaded into the open thread.
async function syncConversationMeta() {
    try {
        const data = await buyerApi('/buyer/messages/conversations');

        for (const incoming of data || []) {
            const local = conversations.value.find(c => c.id === incoming.id);

            if (local) {
                local.unread = Number(incoming.unread || 0);
                local.updatedAt = threadTimeLabel(incoming.updatedAt);
                local.status = incoming.status || local.status;
                local.online = Boolean(incoming.online);
            } else {
                conversations.value.push(mapConversation(incoming));
            }
        }
    } catch (err) {
        // Background refresh — a transient miss just retries next tick.
    }
}

async function refreshActiveConversation() {
    const id = activeConversationId.value;

    if (!id) {
        return;
    }

    try {
        const data = await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}`);
        const index = conversations.value.findIndex(c => c.id === id);

        if (index !== -1) {
            conversations.value[index] = withPendingLocal(conversations.value[index], mapConversation(data));
        }
    } catch (err) {
        // Leave the thread as-is on a transient miss.
    }
}

function pollTick() {
    if (!isChatOpen.value) {
        return;
    }

    refreshActiveConversation();
    syncConversationMeta();
}

function startPolling() {
    if (pollTimer) {
        return;
    }

    pollTimer = setInterval(pollTick, POLL_MS);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

watch(isChatOpen, open => {
    if (open) {
        startPolling();
    } else {
        stopPolling();
    }
});

/*
|--------------------------------------------------------------------------
| Open / close
|--------------------------------------------------------------------------
*/

async function openChat() {
    isChatOpen.value = true;
    await loadConversations();

    if (activeConversationId.value) {
        openConversation(activeConversationId.value);
    }
}

function closeChat() {
    isChatOpen.value = false;
}

function toggleChat() {
    if (isChatOpen.value) {
        closeChat();
    } else {
        openChat();
    }
}

async function openConversation(id) {
    activeConversationId.value = id;

    try {
        const data = await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}`);
        const mapped = mapConversation(data);
        const index = conversations.value.findIndex(c => c.id === id);

        if (index !== -1) {
            conversations.value[index] = withPendingLocal(conversations.value[index], mapped);
        } else {
            conversations.value.unshift(mapped);
        }

        refreshUnreadCount();
    } catch (err) {
        // Leave whatever's already shown for this thread.
    }
}

/*
|--------------------------------------------------------------------------
| Send
|--------------------------------------------------------------------------
|
| Kept synchronous-returning (a boolean) so Chat.vue's handleSend() works
| unchanged: the message is appended optimistically, then the POST runs in
| the background and swaps in the server's copy (or drops the optimistic
| bubble on failure).
|
*/

function sendMessage(text) {
    const convo = activeConversation.value;
    const body = (text || '').trim();

    if (!convo || !body) {
        return false;
    }

    const localId = `local-${Date.now()}`;

    convo.messages.push({
        id: localId,
        from: 'buyer',
        text: body,
        at: timeOfDay(new Date()),
    });
    convo.updatedAt = timeOfDay(new Date());

    buyerApi(`/buyer/messages/conversations/${encodeURIComponent(convo.id)}/messages`, {
        method: 'POST',
        body: JSON.stringify({ body }),
    })
        .then(message => {
            const idx = convo.messages.findIndex(m => m.id === localId);

            if (idx !== -1 && message) {
                convo.messages[idx] = mapMessage(message);
            }
        })
        .catch(err => {
            console.error('Error sending message:', err);
            convo.messages = convo.messages.filter(m => m.id !== localId);
        });

    return true;
}

/**
 * Start (or reuse) a thread with a seller and send the first message.
 * `payload` = { sellerId, orderNumber?, productId?, subject?, body }
 * (orderNumber is the display id, e.g. "#SN-40412" — the leading "#" is
 * stripped here). Returns the mapped conversation. On success the popup
 * opens on the new thread.
 */
async function startConversation(payload) {
    try {
        const data = await buyerApi('/buyer/messages/conversations', {
            method: 'POST',
            body: JSON.stringify({
                seller_id: payload.sellerId,
                order_number: payload.orderNumber ? String(payload.orderNumber).replace(/^#/, '') : null,
                product_id: payload.productId || null,
                subject: payload.subject || null,
                body: payload.body,
            }),
        });

        const mapped = mapConversation(data);
        const index = conversations.value.findIndex(c => c.id === mapped.id);

        if (index !== -1) {
            conversations.value[index] = mapped;
        } else {
            conversations.value.unshift(mapped);
        }

        loadedOnce = true;
        activeConversationId.value = mapped.id;
        isChatOpen.value = true;

        return mapped;
    } catch (err) {
        console.error('Error starting conversation:', err);

        throw err;
    }
}

export function useBuyerChat() {
    // Prime the header's unread badge once per page load. Silently no-ops
    // for a signed-out visitor (the request 401s and is swallowed).
    if (!unreadPrimed) {
        unreadPrimed = true;
        refreshUnreadCount();
    }

    return {
        isChatOpen,
        conversations,
        isLoading,
        loadError,
        activeConversationId,
        activeConversation,
        totalUnread,

        loadConversations,
        refreshUnreadCount,
        openChat,
        closeChat,
        toggleChat,
        openConversation,
        sendMessage,
        startConversation,
    };
}
