import { computed, reactive, ref } from 'vue';

import { buyerApi } from './useBuyerApi';
import { authHeaders } from './useBuyerSession';
import { fetchJson, storeEndpoint } from './useStores';
import { useToasts } from './useToasts';

/*
|--------------------------------------------------------------------------
| useBuyerChat — buyer <-> seller messaging (the Messages modal)
|--------------------------------------------------------------------------
|
| Backed by /api/buyer/messages/* (App\Http\Controllers\Buyer\
| MessageController). Module-level state so the inbox, the selected
| thread, drafts, unsent photos and sends in flight all survive closing
| and reopening the modal (MessagesModal.vue, mounted once in Dashboard).
|
| Opening: the header's Messages button (openChat) or a page's Message
| seller button (messageSeller), which goes straight to that seller's
| thread. When there is none yet, a "new conversation" screen keyed
| `new:<seller>:<order>` holds the draft; the first send creates the
| conversation, so sellers never see empty threads.
|
| Updates: the project has no realtime channel the buyer can safely
| subscribe to (the messages table has no row-level security), so while
| the modal is open this polls: the open thread every THREAD_MS for
| messages newer than the last one it has, the inbox every INBOX_MS.
| While it's closed only the inbox is refreshed, every CLOSED_MS, for the
| header badge.
| Polling pauses in a hidden tab and catches up at once when the tab is
| shown again or the network comes back, so nothing is missed. Messages
| are merged by id, so a poll can never show one twice.
|
| Read state: a thread is marked read only when it is open on screen (the
| inbox loading never marks anything). Buyer messages show "Sent" or
| "Read" from the server's read_at; there is no delivery or presence data,
| so neither is shown.
|
*/

const THREAD_MS = 8000;
const INBOX_MS = 20000;
const CLOSED_MS = 60000;

export const IMAGE_RULES = {
    mimes: ['image/jpeg', 'image/png', 'image/webp'],
    maxBytes: 5 * 1024 * 1024,
    maxCount: 4
};

const conversations = ref([]);
const inboxLoaded = ref(false);
const inboxLoading = ref(false);
const inboxError = ref('');
const unreadFromServer = ref(0);

const activeId = ref(null);

const chatOpen = ref(false);

/** A seller with no thread yet: { key, sellerId, seller, sellerLogo, sellerCategory, product, order }. */
const composeTarget = ref(null);

/** Bumped to ask the open modal to show a conversation: { conversationId, seq }. */
const chatRequest = ref(null);

/** Conversation id -> the product the buyer came from (sent with the next message). */
const askingAbout = reactive({});

let returnFocusTo = null;
let requestSeq = 0;

const toasts = useToasts();

/** id -> { loaded, loading, error, hasMore, loadingOlder, messages, product, order, pending } */
const threads = reactive({});

/** id -> { text, files: [{ key, file, url, error }] } */
const drafts = reactive({});

const totalUnread = computed(() => {
    if (!inboxLoaded.value) {
        return unreadFromServer.value;
    }

    return conversations.value.reduce((sum, c) => sum + (c.unread || 0), 0);
});

function thread(id) {
    if (!threads[id]) {
        threads[id] = { loaded: false, loading: false, error: '', hasMore: false, loadingOlder: false, messages: [], product: null, order: null, pending: [] };
    }

    return threads[id];
}

export function draftFor(id) {
    if (!drafts[id]) {
        drafts[id] = { text: '', files: [], quickQuestionKey: null, productId: null, variantId: null, orderId: null, contactSeller: false };
    }

    return drafts[id];
}

function upsertConversation(row) {
    const index = conversations.value.findIndex(c => c.id === row.id);

    if (index === -1) {
        conversations.value = [row, ...conversations.value];
    } else {
        conversations.value[index] = { ...conversations.value[index], ...row };
    }

    sortInbox();
}

function sortInbox() {
    conversations.value = [...conversations.value].sort((a, b) => (b.updatedAt || '').localeCompare(a.updatedAt || ''));
}

/** Adds server messages, never twice, keeping (at, id) order. */
function mergeMessages(t, incoming, { prepend = false } = {}) {
    const known = new Set(t.messages.map(m => m.id));
    const fresh = incoming.filter(m => !known.has(m.id));

    if (!fresh.length) {
        return [];
    }

    t.messages = prepend ? [...fresh, ...t.messages] : [...t.messages, ...fresh];

    // A pending bubble whose message has now arrived some other way.
    const ids = new Set(fresh.map(m => m.id));
    t.pending = t.pending.filter(p => !p.serverId || !ids.has(p.serverId));

    return fresh;
}

/*
|--------------------------------------------------------------------------
| Inbox
|--------------------------------------------------------------------------
*/

async function loadInbox({ quiet = false } = {}) {
    if (inboxLoading.value) {
        return;
    }

    inboxLoading.value = !quiet || !inboxLoaded.value;

    if (!quiet) {
        inboxError.value = '';
    }

    try {
        const response = await fetch('/api/buyer/messages/conversations', { headers: await authHeaders() });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(body.message || 'Could not load your messages.');
            error.status = response.status;

            throw error;
        }

        // Keep the open thread's locally-known read state: if it's on
        // screen it has been read, whatever an in-flight poll says.
        conversations.value = (body.data || []).map(row => (row.id === activeId.value && threads[row.id]?.loaded ? { ...row, unread: 0 } : row));
        sortInbox();
        unreadFromServer.value = body.meta?.unread_total ?? 0;
        inboxLoaded.value = true;
        inboxError.value = '';
    } catch (err) {
        if (!quiet || !inboxLoaded.value) {
            inboxError.value = err?.status === 401
                ? 'Your session has ended. Please sign in again.'
                : err?.message || 'Could not load your messages.';
        }
    } finally {
        inboxLoading.value = false;
    }
}

async function refreshUnreadCount() {
    try {
        const data = await buyerApi('/buyer/messages/unread-count');
        unreadFromServer.value = Number(data?.count || 0);
    } catch {
        // Signed out or offline: the badge just stays as it was.
    }
}

/*
|--------------------------------------------------------------------------
| Threads
|--------------------------------------------------------------------------
*/

async function openThread(id) {
    activeId.value = id;

    const t = thread(id);

    if (t.loaded) {
        await pollThread(id);

        return;
    }

    t.loading = true;
    t.error = '';

    try {
        // Opening a thread is what marks it read on the server.
        const [data, history] = await Promise.all([
            buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}`),
            fetch(`/api/buyer/messages/conversations/${encodeURIComponent(id)}/messages`, { headers: await authHeaders() }).then(response => response.json())
        ]);

        t.messages = history.data || [];
        t.hasMore = Boolean(history.meta?.hasMore);
        t.product = data.product || null;
        t.order = data.order || null;
        t.loaded = true;

        const row = { ...data, unread: 0 };

        delete row.messages;
        delete row.hasMore;
        upsertConversation(row);
    } catch (err) {
        t.error = err?.status === 404
            ? 'This conversation isn’t available.'
            : err?.message || 'Could not load this conversation.';
    } finally {
        t.loading = false;
    }
}

function closeThread() {
    activeId.value = null;
}

async function loadOlder(id) {
    const t = thread(id);
    const first = t.messages[0];

    if (!t.hasMore || t.loadingOlder || !first) {
        return [];
    }

    t.loadingOlder = true;

    try {
        const response = await fetch(`/api/buyer/messages/conversations/${encodeURIComponent(id)}/messages?before=${encodeURIComponent(first.id)}`, { headers: await authHeaders() });
        const body = await response.json();

        if (!response.ok) {
            throw new Error(body.message || 'Could not load earlier messages.');
        }

        t.hasMore = Boolean(body.meta?.hasMore);

        return mergeMessages(t, body.data || [], { prepend: true });
    } finally {
        t.loadingOlder = false;
    }
}

/**
 * Fetches messages newer than the last one this thread has. Returns the
 * new ones (so the page can decide whether to scroll or show a pill).
 */
async function pollThread(id) {
    const t = threads[id];
    const last = t?.messages[t.messages.length - 1];

    if (!t?.loaded || !last) {
        return [];
    }

    try {
        const response = await fetch(`/api/buyer/messages/conversations/${encodeURIComponent(id)}/messages?after=${encodeURIComponent(last.id)}`, { headers: await authHeaders() });

        if (!response.ok) {
            return [];
        }

        const body = await response.json();
        const fresh = mergeMessages(t, body.data || []);

        if (fresh.length) {
            const latest = fresh[fresh.length - 1];

            upsertConversation({
                id,
                updatedAt: latest.at,
                lastMessagePreview: latest.text || (latest.attachments?.length ? 'Photo' : ''),
                lastMessageFromMe: latest.from === 'buyer'
            });
        }

        return fresh;
    } catch {
        return [];
    }
}

/** Marks the open thread read (only call while it's visible). */
async function markRead(id) {
    const row = conversations.value.find(c => c.id === id);

    if (row && row.unread > 0) {
        row.unread = 0;
    }

    try {
        await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}/read`, { method: 'PUT' });
    } catch {
        // Next poll / open will retry.
    }
}

/** Refreshes buyer read receipts ("Sent" -> "Read") for the open thread. */
async function refreshReceipts(id) {
    const t = threads[id];

    if (!t?.loaded || !t.messages.some(m => m.from === 'buyer' && m.status !== 'read')) {
        return;
    }

    try {
        const response = await fetch(`/api/buyer/messages/conversations/${encodeURIComponent(id)}/messages`, { headers: await authHeaders() });

        if (!response.ok) {
            return;
        }

        const body = await response.json();
        const status = new Map((body.data || []).map(m => [m.id, m]));

        t.messages = t.messages.map(m => (status.has(m.id) ? { ...m, status: status.get(m.id).status, readAt: status.get(m.id).readAt } : m));
    } catch {
        // Receipts catch up on the next tick.
    }
}

/*
|--------------------------------------------------------------------------
| Sending
|--------------------------------------------------------------------------
*/

let localSeq = 0;

function isNewKey(id) {
    return String(id).startsWith('new:');
}

async function upload(id, pending) {
    const attachmentIds = await Promise.all(pending.files.map(async item => {
        const form = new FormData();
        form.append('file', item.file);
        const attachment = await buyerApi('/buyer/messages/attachments', { method: 'POST', body: form });
        return attachment.id;
    }));
    const target = pending.target;
    const asking = target ? target.product : askingAbout[id];
    let conversationId = id;
    let started = null;

    if (target) {
        started = await buyerApi('/buyer/messages/conversations', {
            method: 'POST',
            body: JSON.stringify({ seller_id: target.sellerId, order_number: target.order?.number || null, product_id: asking?.id || null, body: attachmentIds.length || pending.quickQuestionKey || pending.productId || pending.contactSeller ? null : pending.text })
        });
        conversationId = started.id;
        if (!attachmentIds.length && !pending.quickQuestionKey && !pending.productId && !pending.contactSeller) return started;
    }

    const message = await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(conversationId)}/messages`, {
        method: 'POST',
        body: JSON.stringify({ body: pending.text || null, attachment_ids: attachmentIds, product_id: pending.productId || asking?.id || null, variant_id: pending.variantId || null, order_id: pending.orderId || null, quick_question_key: pending.quickQuestionKey || null, contact_seller: pending.contactSeller || false })
    });

    if (started) return { ...started, messages: [...(started.messages || []), message, ...(message.autoReply ? [message.autoReply] : [])] };
    return message;
}

async function deliver(id, pending) {
    const t = thread(id);

    pending.status = 'sending';
    pending.error = '';
    pending.progress = pending.files.length ? 0 : null;

    try {
        const result = await upload(id, pending);

        pending.files.forEach(item => URL.revokeObjectURL(item.url));

        if (pending.target) {
            adoptStarted(id, result, pending);

            return;
        }

        pending.serverId = result.id;
        mergeMessages(t, [result]);
        if (result.autoReply) mergeMessages(t, [{ ...result.autoReply, productContext: result.autoReply.productContext || result.productContext }]);
        t.pending = t.pending.filter(p => p.localId !== pending.localId);

        upsertConversation({ id, updatedAt: result.at, lastMessagePreview: result.text || 'Photo', lastMessageFromMe: true });

        if (askingAbout[id]) {
            delete askingAbout[id];
            refreshReferences(id);
        }
    } catch (err) {
        pending.status = 'failed';
        pending.error = err?.status === 401 ? 'Your session has ended. Sign in again to send.' : err?.message || 'Your message wasn’t sent.';

        // Never fail silently behind a closed modal.
        if (!chatOpen.value) {
            const name = pending.target?.seller || conversations.value.find(c => c.id === id)?.seller || 'the seller';

            toasts.error(`Your message to ${name} wasn’t sent. Open Messages to retry.`);
        }
    }
}

/**
 * The first message created the conversation: swap the new-conversation
 * screen for the real thread, keeping anything typed meanwhile.
 */
function adoptStarted(key, data, pending) {
    const row = { ...data, unread: 0 };
    const t = thread(data.id);

    delete row.messages;
    delete row.hasMore;

    t.messages = data.messages || [];
    t.hasMore = Boolean(data.hasMore);
    t.product = data.product || null;
    t.order = data.order || null;
    t.loaded = true;
    t.pending = [...t.pending, ...thread(key).pending.filter(p => p.localId !== pending.localId)];
    upsertConversation(row);

    const leftover = drafts[key];

    if (leftover && (leftover.text || leftover.files.length)) {
        drafts[data.id] = leftover;
    }

    delete drafts[key];
    delete threads[key];

    if (composeTarget.value?.key === key) {
        composeTarget.value = null;
        activeId.value = data.id;
        chatRequest.value = { conversationId: data.id, seq: ++requestSeq };
    }
}

/** Product / order cards after the reference changed on the server. */
async function refreshReferences(id) {
    try {
        const data = await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}`);
        const t = thread(id);

        t.product = data.product || null;
        t.order = data.order || null;
        upsertConversation({ id, product: data.product ? { id: data.product.id, name: data.product.name } : null });
    } catch {
        // The card catches up next time the thread opens.
    }
}

/**
 * Sends the draft for a thread. The draft is cleared straight away (the
 * bubble shows as "Sending…"); if sending fails the bubble keeps the
 * text and photos for Retry, or can be put back into the composer.
 */
function send(id) {
    const draft = draftFor(id);
    const text = draft.text.trim();
    const files = draft.files.filter(item => !item.error);

    if (!text && !files.length) {
        return null;
    }

    // A new conversation is created once: wait for the first send.
    if (isNewKey(id) && thread(id).pending.some(p => p.status === 'sending')) {
        return null;
    }

    const pending = reactive({
        localId: `local-${Date.now()}-${++localSeq}`,
        text,
        quickQuestionKey: draft.quickQuestionKey,
        productId: draft.productId,
        variantId: draft.variantId,
        orderId: draft.orderId,
        contactSeller: draft.contactSeller,
        files,
        at: new Date().toISOString(),
        status: 'sending',
        progress: files.length ? 0 : null,
        error: '',
        serverId: null,
        target: isNewKey(id) && composeTarget.value?.key === id ? { ...composeTarget.value } : null
    });

    thread(id).pending.push(pending);
    draft.text = '';
    draft.quickQuestionKey = null;
    draft.productId = null;
    draft.variantId = null;
    draft.orderId = null;
    draft.contactSeller = false;
    draft.files = [];

    deliver(id, pending);

    return pending;
}

function sendPreset(id, { text, quickQuestionKey = null, productId = null, variantId = null, orderId = null, contactSeller = false }) {
    const body = String(text || '').trim();

    if (!body || (isNewKey(id) && thread(id).pending.some(p => p.status === 'sending'))) {
        return null;
    }

    const pending = reactive({
        localId: `local-${Date.now()}-${++localSeq}`,
        text: body,
        quickQuestionKey,
        productId,
        variantId,
        orderId,
        contactSeller,
        files: [],
        at: new Date().toISOString(),
        status: 'sending',
        progress: null,
        error: '',
        serverId: null,
        target: isNewKey(id) && composeTarget.value?.key === id ? { ...composeTarget.value } : null
    });

    thread(id).pending.push(pending);
    deliver(id, pending);

    return pending;
}

function retry(id, localId) {
    const pending = thread(id).pending.find(p => p.localId === localId);

    if (pending && pending.status === 'failed') {
        deliver(id, pending);
    }
}

/** Puts a failed message back into the composer instead of retrying. */
function restoreToDraft(id, localId) {
    const t = thread(id);
    const pending = t.pending.find(p => p.localId === localId);

    if (!pending) {
        return;
    }

    const draft = draftFor(id);

    draft.text = [pending.text, draft.text].filter(Boolean).join('\n');
    draft.quickQuestionKey = pending.quickQuestionKey;
    draft.productId = pending.productId;
    draft.variantId = pending.variantId;
    draft.orderId = pending.orderId;
    draft.contactSeller = pending.contactSeller;
    draft.files = [...pending.files, ...draft.files].slice(0, IMAGE_RULES.maxCount);
    t.pending = t.pending.filter(p => p.localId !== localId);
}

async function setStatus(id, status) {
    const row = await buyerApi(`/buyer/messages/conversations/${encodeURIComponent(id)}/status`, {
        method: 'PUT',
        body: JSON.stringify({ status })
    });

    upsertConversation(row);

    return row;
}

/*
|--------------------------------------------------------------------------
| Opening and closing the modal
|--------------------------------------------------------------------------
*/

/** Opens the modal (on a conversation when given one). */
function openChat({ conversationId = null } = {}) {
    if (!chatOpen.value) {
        returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }

    chatOpen.value = true;

    if (conversationId) {
        composeTarget.value = null;
        chatRequest.value = { conversationId, seq: ++requestSeq };
    }
}

/**
 * Message seller (product, store and order pages): straight to that
 * seller's thread — the order's own thread when there is an order — or a
 * new-conversation screen when there isn't one yet. A product travels
 * with the next message so the thread's reference follows it.
 *
 * @param {{ sellerId: string, seller?: string, sellerLogo?: string, sellerCategory?: string,
 *           product?: { id, name, price, image }, order?: { number, status, items } }} target
 */
async function messageSeller(target) {
    openChat();

    // Nothing else is shown (or typed into) while the thread is found.
    activeId.value = null;
    composeTarget.value = null;
    chatRequest.value = { pending: true, seq: ++requestSeq };

    if (!inboxLoaded.value) {
        await loadInbox();
    }

    if (!inboxLoaded.value) {
        // Signed out or offline: the modal shows why.
        chatRequest.value = null;

        return;
    }

    const orderNumber = target.order?.number ? String(target.order.number).replace(/^#/, '') : null;
    const match = conversations.value.find(c => c.sellerId === target.sellerId && (orderNumber ? c.order?.number === orderNumber : !c.order));

    if (match) {
        if (target.product) {
            askingAbout[match.id] = target.product;
        }

        openChat({ conversationId: match.id });

        return;
    }

    const key = `new:${target.sellerId}:${orderNumber || ''}`;
    const t = thread(key);

    t.loaded = true;
    t.product = target.product ? { ...target.product, oldPrice: null, available: true } : null;
    t.order = target.order ? { ...target.order, number: orderNumber } : null;

    composeTarget.value = { ...target, key, order: t.order };
    chatRequest.value = { compose: key, seq: ++requestSeq };

    // Pages don't always know the store's logo (or, for an order, its
    // name): fill them in when the store answers.
    if (!target.seller || !target.sellerLogo) {
        fetchJson(storeEndpoint(target.sellerId)).then((body) => {
            const store = body.data || {};
            const current = composeTarget.value;

            if (current?.key === key) {
                composeTarget.value = {
                    ...current,
                    seller: current.seller || store.name,
                    sellerLogo: current.sellerLogo || store.logo || null,
                    sellerCategory: current.sellerCategory || store.category || null
                };
            }
        }).catch(() => {
            // The header falls back to "Seller".
        });
    }
}

/** Drops the product chip for a thread ("not about this product"). */
function clearAskingAbout(id) {
    delete askingAbout[id];
}

function closeChat() {
    chatOpen.value = false;

    const target = returnFocusTo;

    returnFocusTo = null;

    if (target?.isConnected) {
        requestAnimationFrame(() => target.focus({ preventScroll: true }));
    }
}

/** The header's Messages button. */
function toggleChat() {
    if (chatOpen.value) {
        closeChat();
    } else {
        openChat();
    }
}

export function useBuyerChat() {
    return {
        conversations,
        inboxLoaded,
        inboxLoading,
        inboxError,
        activeId,
        threads,
        drafts,
        totalUnread,
        chatOpen,
        chatRequest,
        composeTarget,
        askingAbout,

        THREAD_MS,
        INBOX_MS,

        loadInbox,
        refreshUnreadCount,
        openThread,
        closeThread,
        loadOlder,
        pollThread,
        markRead,
        refreshReceipts,
        send,
        sendPreset,
        retry,
        restoreToDraft,
        setStatus,
        openChat,
        closeChat,
        messageSeller,
        clearAskingAbout,
        toggleChat
    };
}

// Prime the header badge once per page load (silently no-ops when
// signed out: the request 401s and is swallowed).
let unreadPrimed = false;

export function primeUnread() {
    if (unreadPrimed) {
        return;
    }

    unreadPrimed = true;
    refreshUnreadCount();

    // One page-wide timer: keeps the header badge right while the modal
    // is closed (the open modal polls on its own).
    setInterval(() => {
        if (chatOpen.value || document.hidden) {
            return;
        }

        if (inboxLoaded.value) {
            loadInbox({ quiet: true });
        } else {
            refreshUnreadCount();
        }
    }, CLOSED_MS);
}
