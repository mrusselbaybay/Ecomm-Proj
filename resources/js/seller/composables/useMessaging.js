// resources/js/seller/composables/useMessaging.js
//
// ---------------------------------------------------------------
// STATUS: backed by a real Laravel implementation —
// App\Http\Controllers\Seller\MessageController + the shared
// conversations/messages tables (see routes/seller.php's `messages`
// group). This composable was originally written UI-only, against just
// the documented contract below, back when no backend existed yet; the
// controller was built later to match this contract exactly, so this
// file and Messages.vue needed zero changes when it landed. The 404
// -> `backendMissing` handling in apiFetch() is now purely a defensive
// fallback (e.g. a misconfigured deploy), not the expected path.
//
// Auth: same pattern as every other seller composable — the current
// Supabase access token is forwarded as `Authorization: Bearer <token>`
// (see useSeller.js's getSupabase()). Every query is scoped server-side
// to the authenticated seller's own conversations (see
// MessageController::findForSeller()) — another seller's conversation
// resolves as a plain 404, never a 403 that would leak its existence.
//
// ---------------------------------------------------------------
// API CONTRACT (implemented — see MessageController)
// ---------------------------------------------------------------
//
// GET /api/seller/messages/export
//   Same search/status filters as /conversations, no pagination.
//   -> CSV file, one row per conversation (buyer, order #, status,
//      unread count, last message + sender, last activity) — a
//      conversation-list export, not a full per-message transcript.
//
// GET /api/seller/messages/conversations
//   Query: search?, status? (all|unread|needs_response|resolved|archived),
//          page?, per_page?
//   -> { data: Conversation[], meta: {
//        currentPage, lastPage, perPage, total,
//        statusCounts: { all, unread, needsResponse, resolved, archived }
//      } }
//
//   Conversation = {
//     id, status: 'open'|'resolved'|'archived',
//     buyer: { id, name, initials },
//     order: { id, orderNumber, status } | null,
//     product: { id, name, image, variant } | null,
//     lastMessage: { body, senderRole: 'buyer'|'seller', createdAt } | null,
//     unreadCount, needsResponse: bool, updatedAt
//   }
//
// GET /api/seller/messages/conversations/{id}
//   -> { data: ConversationDetail }
//   ConversationDetail = Conversation & {
//     order: { id, orderNumber, status, total, deliveryStatus, timeline } | null,
//     // timeline: real order_status_history rows, oldest -> newest:
//     //   [{ status, note, at }] — same source Deliveries/Courier Handover
//     //   already read from, not a fabricated "live tracking" feed.
//     product: { id, name, variant, image, quantity } | null,
//   }
//
// GET /api/seller/messages/conversations/{id}/messages
//   Query: before? (cursor: a message id, for scrolling up into history)
//          after?  (cursor: a message id, for polling in new messages —
//                   see pollNewMessages() below; this project has no
//                   realtime/websocket infrastructure, so "new message
//                   arrived" is detected by polling this on an interval
//                   rather than a push subscription)
//          limit? (default 30)
//   -> { data: Message[] (oldest -> newest), meta: { hasMore, nextCursor } }
//   `hasMore`/`nextCursor` describe the `before` direction (older
//   history) regardless of which cursor param was used to fetch.
//
//   Message = {
//     id, conversationId, senderRole: 'buyer'|'seller', body,
//     attachments: [{ id, name, url, mime, size }],
//     status: 'sent'|'delivered'|'read'|null, // null/absent for buyer messages
//     createdAt, readAt
//   }
//
// POST /api/seller/messages/conversations/{id}/messages
//   Body: { body, attachment_ids?: string[] }
//   -> { data: Message }
//
// POST /api/seller/messages/attachments  (multipart/form-data, field "file")
//   Server validates type + size (documented client-side limits below
//   are what the backend is expected to enforce too — see
//   ALLOWED_ATTACHMENT_TYPES / MAX_ATTACHMENT_BYTES).
//   -> { data: { id, name, url, mime, size } }
//
// PUT /api/seller/messages/conversations/{id}/status
//   Body: { status: 'open'|'resolved'|'archived' }
//   -> { data: ConversationDetail }
//
// PUT /api/seller/messages/conversations/{id}/read
//   Marks every unread buyer message in the conversation as read.
//   -> { data: { unreadCount: 0 } }
//
// GET /api/seller/messages/unread-count
//   -> { data: { count } }   (polled for the sidebar badge)
//
// POST /api/seller/messages/conversations/{id}/report
//   Body: { reason: string }
//   -> { data: { reported: true } }
//   NOTE: a `complaints` table already exists in the project's schema
//   (complainant_id/respondent_id/order_id/type/subject/description/
//   status/priority) but has no Laravel model, controller, or routes on
//   this branch — it looks owned by the admin/complaints feature on a
//   different branch. This endpoint is written assuming "report buyer"
//   would eventually insert a `type: 'report'` row there, but that's a
//   cross-role (seller -> admin moderation) decision this composable
//   deliberately doesn't make unilaterally — see Messages.vue's summary
//   for why this stays contract-only rather than reaching into that
//   table directly.
// ---------------------------------------------------------------

import { ref, computed } from 'vue';
import { getSupabase } from './useSeller';

const ALLOWED_ATTACHMENT_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024; // 10MB
const UNREAD_POLL_MS = 30000;
const MESSAGE_POLL_MS = 15000;
const MESSAGES_PAGE_SIZE = 30;

const conversations = ref([]);
const conversationsMeta = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 20,
    total: 0,
    statusCounts: { all: 0, unread: 0, needsResponse: 0, resolved: 0, archived: 0 },
});
const isLoadingConversations = ref(false);
const conversationsError = ref('');
const backendMissing = ref(false);

const filters = ref({ search: '', status: 'all', page: 1 });

const activeConversationId = ref(null);
const activeConversation = ref(null);
const isLoadingActiveConversation = ref(false);
const activeConversationError = ref('');

const messages = ref([]);
const messagesMeta = ref({ hasMore: false, nextCursor: null });
const isLoadingMessages = ref(false);
const isLoadingOlderMessages = ref(false);
const messagesError = ref('');

const isSending = ref(false);
const sendError = ref('');

const isExporting = ref(false);
const exportError = ref('');

const newIncomingCount = ref(0);
let newestKnownMessageId = null;

const unreadBadgeCount = ref(0);
let unreadPollTimer = null;

async function authHeaders(extra = {}) {
    const supabase = getSupabase();
    const {
        data: { session },
    } = await supabase.auth.getSession();
    const token = session?.access_token;

    if (!token) {
        throw new Error('Not signed in.');
    }

    return {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        ...extra,
    };
}

// Every function below treats a 404 from one of THESE specific routes
// as "the messaging backend isn't deployed yet", not a broken link —
// see the module docblock. Any other status (401, 422, 500...) is a
// real error and surfaces normally.
async function apiFetch(path, options = {}) {
    const isJsonBody = options.body && !(options.body instanceof FormData);
    const headers = await authHeaders(isJsonBody ? { 'Content-Type': 'application/json' } : {});
    const response = await fetch(`/api/seller${path}`, { ...options, headers });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const err = new Error(body.message || 'Request failed.');
        err.status = response.status;

        throw err;
    }

    return body;
}

function buildQuery() {
    const params = new URLSearchParams();
    const f = filters.value;

    if (f.search) {
params.set('search', f.search);
}

    if (f.status && f.status !== 'all') {
params.set('status', f.status);
}

    if (f.page) {
params.set('page', f.page);
}

    return params.toString();
}

// Same staleness problem as openConversation() above: switching filter
// tabs (or typing a search term) fires a new request before the previous
// one necessarily finished, and without a guard the slower response could
// win the race and overwrite the list with results for a filter the
// seller isn't even looking at anymore. Aborting the previous request
// cancels it outright instead of racing it.
let loadConversationsController = null;

async function loadConversations() {
    loadConversationsController?.abort();
    const controller = new AbortController();
    loadConversationsController = controller;

    isLoadingConversations.value = true;
    conversationsError.value = '';

    try {
        const body = await apiFetch(`/messages/conversations?${buildQuery()}`, { signal: controller.signal });
        conversations.value = body.data;
        conversationsMeta.value = body.meta;
        backendMissing.value = false;
    } catch (err) {
        if (err.name === 'AbortError') {
            return; // superseded by a newer loadConversations() call — not a real error
        }

        console.error('Error loading conversations:', err);

        if (err.status === 404) {
            backendMissing.value = true;
        } else {
            conversationsError.value = err?.message || 'Something went wrong while loading your conversations.';
        }

        conversations.value = [];
    } finally {
        if (loadConversationsController === controller) {
            isLoadingConversations.value = false;
        }
    }
}

// One row per conversation (buyer, order #, status, last message) —
// not a full per-message transcript. Same search/status filters
// currently applied to the list, same blob-download pattern
// useFeedback.js's exportCsv() already uses.
async function exportCsv() {
    isExporting.value = true;
    exportError.value = '';

    try {
        const headers = await authHeaders();
        const response = await fetch(`/api/seller/messages/export?${buildQuery()}`, { headers });

        if (!response.ok) {
            throw new Error('Export failed.');
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `messages-export-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        console.error('Error exporting conversations:', err);
        exportError.value = err?.message || 'Could not export your conversations.';
    } finally {
        isExporting.value = false;
    }
}

function setFilter(patch) {
    Object.assign(filters.value, patch);

    if (!('page' in patch)) {
        filters.value.page = 1;
    }

    loadConversations();
}

// Tracks the in-flight open request so a slow one can't clobber a
// faster one that started later — without this, clicking conversation A
// then quickly clicking B would leave both fetches racing, and if A's
// happened to resolve after B's, its stale data would silently overwrite
// the B conversation the seller is actually looking at. Aborting the
// previous request (rather than just ignoring its result) also cancels
// the underlying network call instead of leaving it to complete pointlessly.
let openRequestController = null;

async function openConversation(id) {
    openRequestController?.abort();
    const controller = new AbortController();
    openRequestController = controller;

    activeConversationId.value = id;
    activeConversation.value = null;
    messages.value = [];
    messagesMeta.value = { hasMore: false, nextCursor: null };
    isLoadingActiveConversation.value = true;
    isLoadingMessages.value = true;
    activeConversationError.value = '';
    messagesError.value = '';

    try {
        const [detailBody, messagesBody] = await Promise.all([
            apiFetch(`/messages/conversations/${encodeURIComponent(id)}`, { signal: controller.signal }),
            apiFetch(`/messages/conversations/${encodeURIComponent(id)}/messages?limit=${MESSAGES_PAGE_SIZE}`, { signal: controller.signal }),
        ]);
        activeConversation.value = detailBody.data;
        messages.value = messagesBody.data;
        messagesMeta.value = messagesBody.meta;
        newestKnownMessageId = messages.value.at(-1)?.id ?? null;
        newIncomingCount.value = 0;
        backendMissing.value = false;
        markRead(id);
    } catch (err) {
        if (err.name === 'AbortError') {
            return; // superseded by a newer openConversation() call — not a real error
        }

        console.error('Error opening conversation:', err);

        if (err.status === 404) {
            backendMissing.value = true;
        } else {
            activeConversationError.value = err?.message || "Couldn't load this conversation.";
        }
    } finally {
        // Only the still-current request gets to clear the loading state —
        // an aborted/superseded one's finally must not stomp on the newer
        // request that's still in flight.
        if (openRequestController === controller) {
            isLoadingActiveConversation.value = false;
            isLoadingMessages.value = false;
        }
    }
}

function closeActiveConversation() {
    openRequestController?.abort();
    activeConversationId.value = null;
    activeConversation.value = null;
    messages.value = [];
    newIncomingCount.value = 0;
    newestKnownMessageId = null;
}

// Polls for messages newer than the last one this client knows about —
// the honest substitute for a realtime subscription (see the module
// docblock: this project has no websocket/Supabase-Realtime wiring at
// all). Messages.vue owns the actual setInterval (see
// MESSAGE_POLL_MS below) and calls this on each tick, passing whether
// the seller is currently scrolled to the bottom — only the component
// knows real scroll position. If they're not at the bottom, new
// messages are counted in `newIncomingCount` instead of being silently
// appended-and-scrolled-to, so the UI can show a "New messages" button
// rather than yanking their scroll position.
async function pollNewMessages(isAtBottom) {
    if (!activeConversationId.value || !newestKnownMessageId) {
return;
}

    try {
        const body = await apiFetch(
            `/messages/conversations/${encodeURIComponent(activeConversationId.value)}/messages?after=${encodeURIComponent(newestKnownMessageId)}&limit=${MESSAGES_PAGE_SIZE}`,
        );

        if (!body.data.length) {
return;
}

        messages.value = [...messages.value, ...body.data];
        newestKnownMessageId = body.data.at(-1).id;

        if (isAtBottom) {
            markRead(activeConversationId.value);
        } else {
            newIncomingCount.value += body.data.filter((m) => m.senderRole === 'buyer').length;
        }
    } catch {
        // Silent — background poll; a transient failure just tries again
        // on the next tick.
    }
}
function clearNewIncoming() {
    newIncomingCount.value = 0;
}

// Called when the seller scrolls to the top of the message list.
// Prepends older messages without disturbing the messages already
// loaded, so Messages.vue can restore scroll position after the DOM
// grows upward.
async function loadOlderMessages() {
    if (!activeConversationId.value || !messagesMeta.value.hasMore || isLoadingOlderMessages.value) {
        return;
    }

    isLoadingOlderMessages.value = true;

    try {
        const body = await apiFetch(
            `/messages/conversations/${encodeURIComponent(activeConversationId.value)}/messages?limit=${MESSAGES_PAGE_SIZE}&before=${encodeURIComponent(messagesMeta.value.nextCursor)}`,
        );
        messages.value = [...body.data, ...messages.value];
        messagesMeta.value = body.meta;
    } catch (err) {
        console.error('Error loading older messages:', err);
        // Silent — this is a background scroll-triggered load, not worth
        // interrupting the conversation with an error banner. The seller
        // can just scroll again to retry.
    } finally {
        isLoadingOlderMessages.value = false;
    }
}

async function markRead(id) {
    try {
        await apiFetch(`/messages/conversations/${encodeURIComponent(id)}/read`, { method: 'PUT' });
        const convo = conversations.value.find((c) => c.id === id);

        if (convo) {
convo.unreadCount = 0;
}

        refreshUnreadCount();
    } catch {
        // Non-critical — a failed read receipt shouldn't block reading
        // the conversation itself.
    }
}

// Sends with an optimistic local message (status 'sending') so the
// bubble appears instantly; replaced with the server's authoritative
// copy on success, or flipped to 'failed' (with a Retry action) on
// failure. Prevents duplicate sends by disabling the composer via
// isSending while one is in flight.
// A message needs text OR at least one (already-uploaded) attachment,
// not necessarily both — a photo sent with no caption is a normal chat
// message. `attachmentPreviews` is optional local display data (name,
// object-URL, mime) for the staged files, purely so the optimistic
// bubble can show the real image immediately instead of appearing
// empty until the server round-trip replaces it.
async function sendMessage(conversationId, body, attachmentIds = [], attachmentPreviews = []) {
    const text = body.trim();

    if ((!text && attachmentIds.length === 0) || isSending.value) {
return null;
}

    const localId = `local-${Date.now()}`;
    const optimistic = {
        id: localId,
        conversationId,
        senderRole: 'seller',
        body: text,
        attachments: attachmentPreviews,
        status: 'sending',
        createdAt: new Date().toISOString(),
        readAt: null,
    };
    messages.value = [...messages.value, optimistic];

    isSending.value = true;
    sendError.value = '';

    try {
        const res = await apiFetch(`/messages/conversations/${encodeURIComponent(conversationId)}/messages`, {
            method: 'POST',
            body: JSON.stringify({ body: text || null, attachment_ids: attachmentIds }),
        });
        const idx = messages.value.findIndex((m) => m.id === localId);

        if (idx !== -1) {
messages.value[idx] = res.data;
}

        return res.data;
    } catch (err) {
        console.error('Error sending message:', err);

        if (err.status === 404) {
backendMissing.value = true;
}

        sendError.value = err?.message || 'Message failed to send.';
        const idx = messages.value.findIndex((m) => m.id === localId);

        if (idx !== -1) {
messages.value[idx] = { ...messages.value[idx], status: 'failed' };
}

        return null;
    } finally {
        isSending.value = false;
    }
}

async function retryMessage(localId) {
    const msg = messages.value.find((m) => m.id === localId);

    if (!msg) {
return;
}

    messages.value = messages.value.filter((m) => m.id !== localId);
    await sendMessage(msg.conversationId, msg.body);
}

async function setConversationStatus(id, status) {
    try {
        const res = await apiFetch(`/messages/conversations/${encodeURIComponent(id)}/status`, {
            method: 'PUT',
            body: JSON.stringify({ status }),
        });

        if (activeConversation.value?.id === id) {
            activeConversation.value = res.data;
        }

        loadConversations();

        return res.data;
    } catch (err) {
        console.error('Error updating conversation status:', err);

        return null;
    }
}

async function reportBuyer(id, reason) {
    try {
        await apiFetch(`/messages/conversations/${encodeURIComponent(id)}/report`, {
            method: 'POST',
            body: JSON.stringify({ reason }),
        });

        return true;
    } catch (err) {
        console.error('Error reporting buyer:', err);

        if (err.status === 404) {
backendMissing.value = true;
}

        return false;
    }
}

function validateAttachment(file) {
    if (!ALLOWED_ATTACHMENT_TYPES.includes(file.type)) {
        return 'Only PNG, JPG, WEBP, or PDF files are allowed.';
    }

    if (file.size > MAX_ATTACHMENT_BYTES) {
        return 'Files must be 10MB or smaller.';
    }

    return null;
}

async function uploadAttachment(file, onProgress) {
    const error = validateAttachment(file);

    if (error) {
throw new Error(error);
}

    const headers = await authHeaders();
    delete headers['Content-Type']; // browser sets the multipart boundary

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/seller/messages/attachments');
        Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable && onProgress) {
onProgress(Math.round((e.loaded / e.total) * 100));
}
        };
        xhr.onload = () => {
            let body = {};

            try {
                body = JSON.parse(xhr.responseText);
            } catch {
                // non-JSON response (e.g. a plain 404 page) — body stays {}
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(body.data);
            } else {
                const err = new Error(body.message || 'Upload failed.');
                err.status = xhr.status;
                reject(err);
            }
        };
        xhr.onerror = () => reject(new Error('Upload failed.'));

        const formData = new FormData();
        formData.append('file', file);
        xhr.send(formData);
    });
}

async function refreshUnreadCount() {
    try {
        const body = await apiFetch('/messages/unread-count');
        unreadBadgeCount.value = body.data.count;
    } catch {
        // Silent — this powers a sidebar badge that polls constantly;
        // surfacing an error for every failed poll would be noisy, and
        // the badge simply stays at its last known value.
    }
}

function startUnreadPolling() {
    if (unreadPollTimer) {
return;
}

    refreshUnreadCount();
    unreadPollTimer = setInterval(refreshUnreadCount, UNREAD_POLL_MS);
}
function stopUnreadPolling() {
    clearInterval(unreadPollTimer);
    unreadPollTimer = null;
}

// ---- drafts (per-conversation, localStorage-backed) ----
function draftKey(id) {
    return `nexmart_seller_message_draft_${id}`;
}
function getDraft(id) {
    try {
        return localStorage.getItem(draftKey(id)) || '';
    } catch {
        return '';
    }
}
function saveDraft(id, text) {
    try {
        if (text.trim()) {
localStorage.setItem(draftKey(id), text);
} else {
localStorage.removeItem(draftKey(id));
}
    } catch {
        // best-effort only
    }
}
function clearDraft(id) {
    try {
        localStorage.removeItem(draftKey(id));
    } catch {
        // best-effort only
    }
}

const hasConversations = computed(() => conversations.value.length > 0);

export function useMessaging() {
    return {
        conversations,
        conversationsMeta,
        isLoadingConversations,
        conversationsError,
        backendMissing,
        hasConversations,
        filters,
        setFilter,
        loadConversations,

        isExporting,
        exportError,
        exportCsv,

        activeConversationId,
        activeConversation,
        isLoadingActiveConversation,
        activeConversationError,
        openConversation,
        closeActiveConversation,
        setConversationStatus,
        reportBuyer,

        messages,
        messagesMeta,
        isLoadingMessages,
        isLoadingOlderMessages,
        messagesError,
        loadOlderMessages,

        isSending,
        sendError,
        sendMessage,
        retryMessage,

        newIncomingCount,
        pollNewMessages,
        clearNewIncoming,
        MESSAGE_POLL_MS,

        ALLOWED_ATTACHMENT_TYPES,
        MAX_ATTACHMENT_BYTES,
        validateAttachment,
        uploadAttachment,

        unreadBadgeCount,
        startUnreadPolling,
        stopUnreadPolling,
        refreshUnreadCount,

        getDraft,
        saveDraft,
        clearDraft,
    };
}