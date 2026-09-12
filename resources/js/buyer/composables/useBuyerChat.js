import { computed, ref, watch } from 'vue';

import { buyerApi, buyerApiWithMeta } from './useBuyerApi';

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
|   conversation: { id, seller, memberSince, unread, updatedAt,
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
const MESSAGES_PAGE_SIZE = 10;
const ALLOWED_ATTACHMENT_TYPES = [
    'image/png', 'image/jpeg', 'image/webp', 'application/pdf',
    'video/mp4', 'video/webm', 'video/quicktime',
];
const VIDEO_ATTACHMENT_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
const MAX_VIDEO_ATTACHMENT_BYTES = 50 * 1024 * 1024;

const isChatOpen = ref(false);

const conversations = ref([]);
const isLoading = ref(false);
const isLoadingMoreConversations = ref(false);
const loadError = ref('');
const conversationsMeta = ref({ currentPage: 1, lastPage: 1, perPage: 20, total: 0, archived_total: 0 });
const activeConversationId = ref(null);
// Whether `conversations` currently holds the archived-only view or the
// default inbox — the two never coexist client-side (switching refetches
// rather than keeping two parallel lists in sync), which keeps every other
// function below (activeConversation, openConversation, polling) working
// unchanged for whichever view is active.
const isViewingArchived = ref(false);

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

// The server hands back a fresh temporary-signed URL for the same
// attachment on every fetch (list load, poll, thread refresh...). If we
// pass that straight through, the <img>/<video> src churns on every poll
// tick even though nothing changed — for video this aborts the in-flight
// playback/load (the "AbortError: play() request was interrupted by a new
// load request" console error) and forces a full re-download. Cache the
// signed URL per attachment id and keep reusing it until it's actually
// close to expiring (Laravel embeds `expires` as a unix timestamp in the
// query string), so the media element's src stays stable across polls.
const attachmentUrlCache = new Map();
const SIGNED_URL_EXPIRY_BUFFER_MS = 60000;

function signedUrlExpiryMs(url) {
    try {
        const expires = Number(new URL(url, window.location.origin).searchParams.get('expires'));

        return Number.isFinite(expires) && expires > 0 ? expires * 1000 : null;
    } catch {
        return null;
    }
}

function stabilizeAttachmentUrl(id, freshUrl) {
    if (!id || !freshUrl) {
        return freshUrl || null;
    }

    const cached = attachmentUrlCache.get(id);

    if (cached) {
        const expiresAt = signedUrlExpiryMs(cached);

        if (!expiresAt || expiresAt - Date.now() > SIGNED_URL_EXPIRY_BUFFER_MS) {
            return cached;
        }
    }

    attachmentUrlCache.set(id, freshUrl);

    return freshUrl;
}

function mapMessage(message) {
    return {
        id: message.id,
        // Collapsing anything that wasn't literally 'seller' down to
        // 'buyer' used to also swallow the 'system' role (the auto
        // "order placed" message — see DirectConversationService::
        // startForOrder()), which made it render as a right-aligned teal
        // buyer bubble with read-receipt checkmarks instead of the
        // dedicated order card in Chat.vue.
        from: message.from === 'seller' || message.from === 'system' ? message.from : 'buyer',
        text: message.text,
        attachments: Array.isArray(message.attachments)
            ? message.attachments.map(attachment => ({
                id: attachment.id,
                name: attachment.name || 'attachment',
                url: stabilizeAttachmentUrl(attachment.id, attachment.url || null),
                mime: attachment.mime || null,
                size: Number(attachment.size || 0),
            }))
            : [],
        // Which purchase (if any) this specific message was about — a
        // thread now spans every purchase from one seller, so context lives
        // per-message rather than once for the whole conversation. Carries
        // enough (item count/total/a preview name+image) for the
        // auto-generated "order placed" system message to render as one
        // self-contained card in Chat.vue instead of a bare "Order #X" chip
        // plus a separate text bubble repeating the same numbers.
        orderContext: message.orderContext
            ? {
                id: message.orderContext.id,
                orderNumber: message.orderContext.orderNumber,
                itemCount: Number(message.orderContext.itemCount || 0),
                total: message.orderContext.total,
                previewName: message.orderContext.previewName || null,
                previewImage: message.orderContext.previewImage || null,
            }
            : null,
        productContext: message.productContext
            ? {
                id: message.productContext.id,
                name: message.productContext.name,
                price: message.productContext.price,
                image: message.productContext.image || null,
            }
            : null,
        at: threadTimeLabel(message.at) || timeOfDay(new Date()),
    };
}

function mapConversation(conversation) {
    return {
        id: conversation.id,
        seller: conversation.seller || 'BuyTheWay Seller',
        sellerId: conversation.sellerId || null,
        avatarUrl: conversation.sellerAvatarUrl || null,
        status: conversation.status || 'open',
        // Per-participant (see Conversation::archiveFor()/isArchivedFor()
        // on the backend) — archiving never touches `status`, so this is a
        // separate field, not status === 'archived'.
        archived: Boolean(conversation.archived),
        memberSince: conversation.memberSince || null,
        unread: Number(conversation.unread || 0),
        updatedAt: threadTimeLabel(conversation.updatedAt),
        // The list endpoint deliberately never embeds messages (see its own
        // docblock) — this denormalised preview is the only way the sidebar
        // can show a snippet for a conversation that hasn't actually been
        // opened yet in this session, e.g. one a checkout just auto-started.
        lastMessagePreview: conversation.lastMessagePreview || null,
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
        // Older-messages pagination state for this thread — populated by
        // openConversation()/loadOlderMessages(); a bare list-load has
        // neither, so default to "nothing more known yet" rather than
        // implying the full history is already loaded.
        messagesMeta: conversation.messagesMeta || { hasMore: false, nextCursor: null },
        isLoadingOlderMessages: false,
        // True once this thread's first message page has actually loaded —
        // lets Chat.vue tell "no messages yet" apart from "still loading",
        // and tells the scroll-to-bottom watcher when a fresh load lands.
        messagesLoaded: Boolean(conversation.messagesLoaded),
        isLoadingMessages: false,
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

function conversationsQuery() {
    return isViewingArchived.value ? '?status=archived' : '';
}

async function fetchConversations() {
    isLoading.value = true;
    loadError.value = '';

    try {
        const { data, meta } = await buyerApiWithMeta(`/buyer/messages/conversations${conversationsQuery()}`);
        conversations.value = (data || []).map(mapConversation);
        conversationsMeta.value = meta || conversationsMeta.value;

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

// Switches the popup's list pane between the default inbox and the
// archived-only view (see the "view archived" shortcut next to the search
// bar in Chat.vue). A fresh fetch every time rather than keeping two lists
// synced — switching is an infrequent, deliberate navigation action, not a
// hot path, so simplicity wins over caching here.
async function showArchivedConversations() {
    isViewingArchived.value = true;
    activeConversationId.value = null;
    await fetchConversations();
}

async function showInboxConversations() {
    isViewingArchived.value = false;
    activeConversationId.value = null;
    await fetchConversations();
}

// Infinite-scroll continuation for the inbox's list of sellers — Chat.vue
// calls this when the list is scrolled near its bottom. Appends rather
// than replacing, so conversations already rendered (and any thread
// already opened into one of them) aren't disturbed.
async function loadMoreConversations() {
    if (isLoadingMoreConversations.value || isLoading.value || inFlight) {
        return;
    }

    if (conversationsMeta.value.currentPage >= conversationsMeta.value.lastPage) {
        return;
    }

    isLoadingMoreConversations.value = true;

    try {
        const nextPage = conversationsMeta.value.currentPage + 1;
        const query = conversationsQuery();
        const { data, meta } = await buyerApiWithMeta(
            `/buyer/messages/conversations${query}${query ? '&' : '?'}page=${nextPage}`,
        );

        conversations.value = [...conversations.value, ...(data || []).map(mapConversation)];
        conversationsMeta.value = meta || conversationsMeta.value;
    } catch (err) {
        // Silent — a failed "load more" just lets the user retry by scrolling again.
    } finally {
        isLoadingMoreConversations.value = false;
    }
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
        const newlyDiscovered = [];

        for (const incoming of data || []) {
            const local = conversations.value.find(c => c.id === incoming.id);

            if (local) {
                local.unread = Number(incoming.unread || 0);
                local.updatedAt = threadTimeLabel(incoming.updatedAt);
                local.status = incoming.status || local.status;
                local.lastMessagePreview = incoming.lastMessagePreview || local.lastMessagePreview;
            } else {
                newlyDiscovered.push(mapConversation(incoming));
            }
        }

        // The server already returns conversations newest-first, so a
        // thread that didn't exist locally yet (e.g. one a just-placed
        // order auto-started — see DirectConversationService::startForOrder())
        // is by definition more recent than everything already shown.
        // Prepending (in that same order) instead of appending is what
        // puts it at the top of the inbox instead of the bottom.
        if (newlyDiscovered.length) {
            conversations.value = [...newlyDiscovered, ...conversations.value];
        }
    } catch (err) {
        // Background refresh — a transient miss just retries next tick.
    }
}

// Polls only for messages newer than the last real (non-optimistic) one
// already shown, instead of re-fetching the whole conversation + its full
// message history on every tick — the previous approach re-downloaded
// everything (including attachments) every POLL_MS just to check for a
// seller reply.
async function refreshActiveConversation() {
    const convo = activeConversation.value;

    if (!convo) {
        return;
    }

    const newestKnown = [...convo.messages].reverse().find(m => !String(m.id).startsWith('local-'));

    if (!newestKnown) {
        return;
    }

    try {
        const { data } = await buyerApiWithMeta(
            `/buyer/messages/conversations/${encodeURIComponent(convo.id)}/messages?after=${encodeURIComponent(newestKnown.id)}&limit=${MESSAGES_PAGE_SIZE}`,
        );

        if (!data || !data.length) {
            return;
        }

        const target = conversations.value.find(c => c.id === convo.id);

        if (!target) {
            return;
        }

        // Defensive de-dupe: if the buyer's own just-sent message hadn't
        // finished its optimistic-replace yet when this poll fired, the
        // same message could otherwise be appended twice.
        const knownIds = new Set(target.messages.map(m => m.id));
        const fresh = data.map(mapMessage).filter(m => !knownIds.has(m.id));

        if (fresh.length) {
            target.messages = [...target.messages, ...fresh];
        }
    } catch (err) {
        // Leave the thread as-is on a transient miss.
    }
}

function pollTick() {
    if (!isChatOpen.value) {
        return;
    }

    // Nothing can be sent into an archived thread (the server rejects it —
    // Conversation::isWritable()) and syncConversationMeta() always queries
    // the *default* (non-archived) endpoint, so polling while the archived
    // view is open would do nothing useful for the open thread and would
    // wrongly pull unarchived conversations into view. Just freeze polling
    // until the buyer switches back to the inbox.
    if (isViewingArchived.value) {
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

    // The very first open of the session does the full load (and shows the
    // list skeleton while it's in flight). Every open after that used to
    // skip straight to the cached `conversations` array and never refetch
    // — so a conversation the buyer's own checkout just auto-started (see
    // DirectConversationService::startForOrder()) stayed invisible until
    // the 15s background poll happened to catch it, or the page reloaded.
    // syncConversationMeta() is the same cheap merge the poll already uses:
    // it updates/adds conversations in place without disturbing whatever
    // thread is already loaded, so reopening the popup is never a blank
    // reload, just an instant refresh of what's already on screen.
    if (loadedOnce) {
        await syncConversationMeta();
    } else {
        await loadConversations();
    }

    if (!activeConversationId.value && conversations.value.length) {
        activeConversationId.value = conversations.value[0].id;
    }

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

// Fetches conversation metadata and its most recent page of messages in
// parallel (the detail endpoint no longer inlines every message — see the
// backend's showConversation()), rather than pulling the thread's entire
// history into the popup up front.
async function openConversation(id) {
    activeConversationId.value = id;

    const existingIndex = conversations.value.findIndex(c => c.id === id);
    const alreadyLoaded = existingIndex !== -1 && conversations.value[existingIndex].messagesLoaded;

    // Skip the loading flag (and the skeleton it drives) on a revisit —
    // whatever was last loaded for this thread is shown instantly while
    // this fetch silently refreshes it in the background.
    if (existingIndex !== -1 && !alreadyLoaded) {
        conversations.value[existingIndex].isLoadingMessages = true;
    }

    try {
        const [detail, messagesResult] = await Promise.all([
            buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}`),
            buyerApiWithMeta(`/buyer/messages/conversations/${encodeURIComponent(id)}/messages?limit=${MESSAGES_PAGE_SIZE}`),
        ]);

        if (activeConversationId.value !== id) {
            return;
        }

        const mapped = mapConversation({
            ...detail,
            messages: messagesResult.data || [],
            messagesMeta: messagesResult.meta || { hasMore: false, nextCursor: null },
            messagesLoaded: true,
        });
        const index = conversations.value.findIndex(c => c.id === id);

        if (index !== -1) {
            conversations.value[index] = withPendingLocal(conversations.value[index], mapped);
        } else {
            conversations.value.unshift(mapped);
        }

        refreshUnreadCount();
    } catch (err) {
        if (existingIndex !== -1) {
            conversations.value[existingIndex].isLoadingMessages = false;
        }
        // Leave whatever's already shown for this thread.
    }
}

// Archive/unarchive (and, in principle, resolve/reopen — only the two
// archive-related transitions have UI so far). Unlike deleteConversation()
// this is a shared conversation-wide state, not a per-user hide: archiving
// blocks EITHER side from sending until someone reopens it (server-side via
// Conversation::isWritable()), matching how seller's archive already
// behaves — this just brings the same action to the buyer.
async function setConversationStatus(id, status) {
    const data = await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}/status`, {
        method: 'PUT',
        body: JSON.stringify({ status }),
    });

    const mapped = mapConversation(data);

    if (status === 'archived') {
        // Drops out of whichever view is showing it (there's only ever the
        // inbox visible when archiving, since the archived view's own
        // conversations are already archived).
        conversations.value = conversations.value.filter(c => c.id !== id);

        if (activeConversationId.value === id) {
            activeConversationId.value = conversations.value[0]?.id || null;
        }
    } else if (isViewingArchived.value) {
        // Unarchiving while looking at the archived view: it no longer
        // belongs here, so drop it rather than leave a stale archived
        // status showing next to an unarchive button that's already fired.
        conversations.value = conversations.value.filter(c => c.id !== id);

        if (activeConversationId.value === id) {
            activeConversationId.value = conversations.value[0]?.id || null;
        }
    } else {
        // Unarchiving from the inbox view (e.g. via a stale reference) —
        // patch/insert it in place rather than a full refetch.
        const index = conversations.value.findIndex(c => c.id === id);

        if (index !== -1) {
            conversations.value[index] = mapped;
        } else {
            conversations.value.unshift(mapped);
        }
    }

    return mapped;
}

// Removes the conversation from the buyer's own inbox only — the seller
// still sees their copy, and it reappears automatically if either side
// messages the other again (see Conversation::leaveFor()/reviveLeftParticipants()).
async function deleteConversation(id) {
    await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}`, { method: 'DELETE' });

    conversations.value = conversations.value.filter(c => c.id !== id);

    if (activeConversationId.value === id) {
        activeConversationId.value = conversations.value[0]?.id || null;
    }
}

// Scroll-to-top-of-thread continuation — Chat.vue calls this when the
// active thread's body is scrolled near its top, so older history only
// loads in as the buyer actually scrolls up to it.
async function loadOlderMessages() {
    const convo = activeConversation.value;

    if (!convo || !convo.messagesMeta?.hasMore || convo.isLoadingOlderMessages) {
        return;
    }

    convo.isLoadingOlderMessages = true;
    const conversationId = convo.id;
    const nextCursor = convo.messagesMeta.nextCursor;

    try {
        const { data, meta } = await buyerApiWithMeta(
            `/buyer/messages/conversations/${encodeURIComponent(conversationId)}/messages?limit=${MESSAGES_PAGE_SIZE}&before=${encodeURIComponent(nextCursor)}`,
        );
        const target = conversations.value.find(c => c.id === conversationId);

        if (!target || activeConversationId.value !== conversationId) {
            return;
        }

        target.messages = [...(data || []).map(mapMessage), ...target.messages];
        target.messagesMeta = meta || target.messagesMeta;
    } catch (err) {
        // Silent — a failed "load older" just lets the user retry by scrolling again.
    } finally {
        const target = conversations.value.find(c => c.id === conversationId);

        if (target) {
            target.isLoadingOlderMessages = false;
        }
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

function sendMessage(text, attachmentIds = [], attachmentPreviews = []) {
    const convo = activeConversation.value;
    const body = (text || '').trim();

    if (!convo || (!body && attachmentIds.length === 0)) {
        return false;
    }

    const localId = `local-${Date.now()}`;

    convo.messages.push({
        id: localId,
        from: 'buyer',
        text: body,
        attachments: attachmentPreviews,
        at: timeOfDay(new Date()),
    });
    convo.updatedAt = timeOfDay(new Date());

    buyerApi(`/buyer/messages/conversations/${encodeURIComponent(convo.id)}/messages`, {
        method: 'POST',
        body: JSON.stringify({
            body: body || null,
            attachment_ids: attachmentIds,
        }),
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

function validateAttachment(file) {
    if (!ALLOWED_ATTACHMENT_TYPES.includes(file.type)) {
        return 'Only PNG, JPG, WEBP, PDF, MP4, WEBM, or MOV files are allowed.';
    }

    const isVideo = VIDEO_ATTACHMENT_TYPES.includes(file.type);
    const maxBytes = isVideo ? MAX_VIDEO_ATTACHMENT_BYTES : MAX_ATTACHMENT_BYTES;

    if (file.size > maxBytes) {
        return isVideo ? 'Videos must be 50MB or smaller.' : 'Files must be 10MB or smaller.';
    }

    return null;
}

async function uploadAttachment(file) {
    const validationError = validateAttachment(file);

    if (validationError) {
        throw new Error(validationError);
    }

    const formData = new FormData();
    formData.append('file', file);

    return buyerApi('/buyer/messages/attachments', {
        method: 'POST',
        body: formData,
    });
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
        isLoadingMoreConversations,
        conversationsMeta,
        loadError,
        activeConversationId,
        activeConversation,
        totalUnread,
        isViewingArchived,

        loadConversations,
        loadMoreConversations,
        refreshUnreadCount,
        openChat,
        closeChat,
        toggleChat,
        openConversation,
        deleteConversation,
        setConversationStatus,
        showArchivedConversations,
        showInboxConversations,
        loadOlderMessages,
        sendMessage,
        validateAttachment,
        uploadAttachment,
        MAX_ATTACHMENT_BYTES,
        startConversation,
    };
}
