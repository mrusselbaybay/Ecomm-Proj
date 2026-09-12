<template>
    <section class="messages-card" :class="{ 'has-active': !!activeId }">
        <aside class="thread-list">
            <div class="panel-heading">
                <div><p class="eyebrow">Inbox</p><h2>Seller messages</h2><p class="heading-copy">Conversations for shipments handled by your team.</p></div>
                <button class="icon-button" :disabled="loading" aria-label="Refresh conversations" title="Refresh conversations" @click="loadConversations">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5M4 13a8.1 8.1 0 0 0 15.5 2M20 20v-5h-5" /></svg>
                </button>
            </div>

            <div class="search-row">
                <label class="thread-search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <span class="sr-only">Search seller conversations</span>
                    <input v-model="search" type="search" placeholder="Search seller, order, or region" @input="onSearchInput" />
                </label>
                <button
                    type="button"
                    class="archived-shortcut"
                    :class="{ active: statusFilter === 'archived' }"
                    title="View archived conversations"
                    aria-label="View archived conversations"
                    @click="setStatusFilter('archived')"
                >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4" /></svg>
                    <span v-if="tabCount('archived')" class="archived-shortcut-badge">{{ tabCount('archived') }}</span>
                </button>
            </div>

            <div class="filter-tabs">
                <button
                    v-for="tab in [
                        { key: 'all', label: 'All' },
                        { key: 'unread', label: 'Unread' },
                        { key: 'needs_response', label: 'Needs Reply' },
                        { key: 'resolved', label: 'Resolved' },
                        { key: 'archived', label: 'Archived' },
                    ]"
                    :key="tab.key"
                    type="button"
                    class="filter-tab"
                    :class="{ active: statusFilter === tab.key }"
                    @click="setStatusFilter(tab.key)"
                >
                    {{ tab.label }}
                    <span v-if="tabCount(tab.key)" class="filter-tab-count">{{ tabCount(tab.key) }}</span>
                </button>
            </div>

            <p v-if="error" class="error-copy">{{ error }}</p>

            <div v-if="loading && conversations.length === 0" class="skeleton-list" aria-hidden="true">
                <div v-for="n in 4" :key="n" class="skeleton-row">
                    <span class="skeleton-avatar"></span>
                    <span class="skeleton-lines">
                        <span class="skeleton-line" style="width: 60%"></span>
                        <span class="skeleton-line" style="width: 85%"></span>
                    </span>
                </div>
            </div>
            <p v-else-if="conversations.length === 0" class="empty-copy">Conversations appear after your company assigns an accepted rider to a seller's parcel.</p>
            <p v-else-if="filteredConversations.length === 0" class="empty-copy">No conversations match your search.</p>

            <div v-else class="thread-scroll" @scroll="onListScroll">
                <button v-for="conversation in filteredConversations" :key="conversation.id" class="thread-button" :class="{ active: conversation.id === activeId, unread: conversation.unread > 0 }" @click="openConversation(conversation.id)">
                    <img v-if="conversation.seller.avatarUrl" class="avatar" :src="conversation.seller.avatarUrl" :alt="conversation.seller.name" loading="lazy">
                    <span v-else class="avatar">{{ initials(conversation.seller.name) }}</span>
                    <span class="thread-copy">
                        <span class="thread-top"><strong>{{ conversation.seller.name }}</strong><time>{{ formatListTime(conversation.last_message_at) }}</time></span>
                        <small>Order #{{ conversation.order.number }} - {{ conversation.shipment.region }}</small>
                        <span>{{ conversation.last_message || 'Shipment conversation ready' }}</span>
                    </span>
                    <b v-if="conversation.unread" class="unread-badge">{{ conversation.unread }}</b>
                </button>

                <div v-if="isLoadingMoreConversations" class="list-loading-more" aria-hidden="true">
                    <span class="spinner"></span>
                </div>
            </div>
        </aside>

        <div class="chat-panel">
            <header v-if="activeConversation" class="chat-heading">
                <button class="back-button" type="button" aria-label="Back to conversations" @click="closeConversation">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <img v-if="activeConversation.seller.avatarUrl" class="avatar" :src="activeConversation.seller.avatarUrl" :alt="activeConversation.seller.name">
                <span v-else class="avatar">{{ initials(activeConversation.seller.name) }}</span>
                <div class="chat-title">
                    <p class="eyebrow">Seller</p><h3>{{ activeConversation.seller.name }}</h3>
                    <div class="context-tags">
                        <span>Order #{{ activeConversation.order.number }}</span><span>{{ activeConversation.shipment.region }}</span><span>Rider: {{ activeConversation.shipment.rider }}</span>
                        <span v-if="activeConversation.status && activeConversation.status !== 'open'" class="status-tag" :class="activeConversation.status">{{ activeConversation.status }}</span>
                    </div>
                </div>
                <button
                    v-if="!activeConversation.archived"
                    class="icon-button"
                    type="button"
                    aria-label="Archive conversation"
                    title="Archive conversation"
                    :disabled="isUpdatingStatus"
                    @click="confirmArchive"
                >
                    <span v-if="isUpdatingStatus && pendingStatusAction?.id === activeId" class="spinner"></span>
                    <svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4" /></svg>
                </button>
                <button
                    v-else
                    class="icon-button"
                    type="button"
                    aria-label="Unarchive conversation"
                    title="Unarchive conversation"
                    :disabled="isUpdatingStatus"
                    @click="confirmUnarchive"
                >
                    <span v-if="isUpdatingStatus && pendingStatusAction?.id === activeId" class="spinner"></span>
                    <svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8" /><path d="M12 17V9m0 0-3 3m3-3 3 3" /></svg>
                </button>
                <button class="icon-button danger" type="button" aria-label="Delete conversation" title="Delete conversation" @click="pendingDeleteId = activeId; deleteError = ''">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6" /><path d="M10 11v6" /><path d="M14 11v6" /></svg>
                </button>
            </header>

            <div v-if="activeConversation" ref="messageBody" class="message-body" @scroll="onMessageBodyScroll">
                <div v-if="isLoadingOlderMessages" class="loading-older" aria-hidden="true"><span class="spinner"></span></div>

                <div v-for="message in messages" :key="message.id" class="message-row" :class="message.from === 'logistics' ? 'mine' : 'theirs'">
                    <div class="bubble" :class="{ 'bubble-media': message.attachments?.length && !message.text }">
                        <div v-if="message.attachments?.length" class="bubble-attachments">
                            <a
                                v-for="attachment in message.attachments"
                                :key="attachment.id"
                                :href="attachment.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="bubble-attachment"
                            >
                                <img v-if="attachment.mime?.startsWith('image/')" :src="attachment.url" :alt="attachment.name" loading="lazy" />
                                <video v-else-if="attachment.mime?.startsWith('video/')" :src="attachment.url" muted playsinline preload="metadata"></video>
                                <span v-else class="bubble-attachment-file">{{ attachment.name }}</span>
                            </a>
                        </div>
                        <p v-if="message.text">{{ message.text }}</p>
                        <time v-if="message.status !== 'sending' && message.status !== 'failed'">{{ formatTime(message.at) }}</time>
                        <time v-else-if="message.status === 'sending'">Sending…</time>
                        <span v-else class="send-failed">Failed to send</span>
                    </div>
                </div>
                <div v-if="loadingMessages" class="conversation-empty"><p>Loading this order's messages...</p></div>
                <div v-else-if="messages.length === 0" class="conversation-empty"><strong>No messages yet</strong><p>Start the conversation about this assigned shipment.</p></div>
            </div>

            <p v-if="activeConversation && activeConversation.status && activeConversation.status !== 'open'" class="composer-notice">
                This conversation is {{ activeConversation.status }}.
                <button type="button" class="retry-link" :disabled="isUpdatingStatus" @click="setConversationStatus(activeId, 'open')">Reopen it</button>
                to keep replying.
            </p>
            <form v-else-if="activeConversation" class="composer" @submit.prevent="send">
                <div v-if="stagedAttachments.length" class="staged-attachments">
                    <div v-for="attachment in stagedAttachments" :key="attachment.localId" class="staged-attachment">
                        <img v-if="attachment.previewUrl && !attachment.isVideo" :src="attachment.previewUrl" :alt="attachment.name" />
                        <video v-else-if="attachment.previewUrl" :src="attachment.previewUrl" muted playsinline preload="metadata"></video>
                        <span v-else class="staged-attachment-file">{{ attachment.name }}</span>
                        <span v-if="attachment.uploading" class="staged-attachment-status">Uploading…</span>
                        <span v-else-if="attachment.error" class="staged-attachment-status error">{{ attachment.error }}</span>
                        <button type="button" class="staged-attachment-remove" :aria-label="`Remove ${attachment.name}`" @click="removeStagedAttachment(attachment.localId)">&times;</button>
                    </div>
                </div>
                <p v-if="attachmentError" class="error-copy small">{{ attachmentError }}</p>
                <div class="composer-row">
                    <button type="button" class="icon-button" :disabled="stagedAttachments.length >= 5" aria-label="Attach image, video, or PDF" @click="fileInput?.click()">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" /></svg>
                    </button>
                    <input ref="fileInput" type="file" class="sr-only" accept="image/png,image/jpeg,image/webp,application/pdf,video/mp4,video/webm,video/quicktime" multiple @change="onFilePicked" />
                    <textarea v-model="draft" rows="2" maxlength="4000" placeholder="Message the seller about this shipment..."></textarea>
                    <button class="btn-primary" :disabled="!canSend || sending">{{ sending ? 'Sending...' : 'Send' }}</button>
                </div>
            </form>

            <div v-else class="empty-chat">
                <span class="empty-chat-icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 10h8M8 14h4" /><path d="M21 12c0 4.4-4 8-9 8-1.1 0-2.1-.2-3-.5L3 21l1.5-3.8A7.3 7.3 0 0 1 3 13c0-4.4 4-8 9-8s9 3.6 9 7Z" /></svg></span>
                <strong>Select a conversation</strong><p>Choose a seller shipment from the inbox to view its messages.</p>
            </div>
        </div>

        <!-- Archive / unarchive confirm -->
        <div v-if="pendingStatusAction" class="modal-overlay" role="dialog" aria-modal="true" :aria-label="pendingStatusAction.status === 'archived' ? 'Archive conversation' : 'Unarchive conversation'" @click.self="pendingStatusAction = null">
            <div class="modal-panel modal-sm">
                <div class="modal-header">
                    <h3>{{ pendingStatusAction.status === 'archived' ? 'Archive this conversation?' : 'Unarchive this conversation?' }}</h3>
                    <button type="button" class="modal-close" aria-label="Close" @click="pendingStatusAction = null">&times;</button>
                </div>
                <p class="modal-desc">
                    {{ pendingStatusAction.status === 'archived'
                        ? "It moves out of your inbox into the Archived filter — the seller isn't affected and can still message you."
                        : 'It moves back into your main inbox.' }}
                </p>
                <p v-if="statusActionError" class="error-copy">{{ statusActionError }}</p>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" @click="pendingStatusAction = null">Cancel</button>
                    <button type="button" class="btn-primary" :disabled="isUpdatingStatus" @click="runStatusAction">
                        <span v-if="isUpdatingStatus" class="spinner spinner-sm"></span>
                        {{ isUpdatingStatus
                            ? (pendingStatusAction.status === 'archived' ? 'Archiving…' : 'Unarchiving…')
                            : (pendingStatusAction.status === 'archived' ? 'Archive' : 'Unarchive') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete conversation confirm -->
        <div v-if="pendingDeleteId" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Delete conversation" @click.self="pendingDeleteId = null">
            <div class="modal-panel modal-sm">
                <div class="modal-header">
                    <h3>Delete this conversation?</h3>
                    <button type="button" class="modal-close" aria-label="Close" @click="pendingDeleteId = null">&times;</button>
                </div>
                <p class="modal-desc">This removes it from your inbox. The seller still sees their side, and it'll come back if either of you sends a new message.</p>
                <p v-if="deleteError" class="error-copy">{{ deleteError }}</p>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" @click="pendingDeleteId = null">Cancel</button>
                    <button type="button" class="btn-danger" :disabled="isDeletingConversation" @click="runDeleteConversation">
                        {{ isDeletingConversation ? 'Deleting…' : 'Delete' }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const { logisticsFetch } = useLogistics();

const MESSAGES_PAGE_SIZE = 10;
const POLL_MS = 15000;
const ALLOWED_ATTACHMENT_TYPES = [
    'image/png', 'image/jpeg', 'image/webp', 'application/pdf',
    'video/mp4', 'video/webm', 'video/quicktime',
];
const VIDEO_ATTACHMENT_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
const MAX_VIDEO_ATTACHMENT_BYTES = 50 * 1024 * 1024;

const conversations = ref([]);
const conversationsMeta = ref({
    currentPage: 1, lastPage: 1, perPage: 20, total: 0,
    statusCounts: { all: 0, unread: 0, needsResponse: 0, resolved: 0, archived: 0 },
});
const isLoadingMoreConversations = ref(false);
const activeId = ref(null);
const messages = ref([]);
const messagesMeta = ref({ hasMore: false, nextCursor: null });
const isLoadingOlderMessages = ref(false);
const draft = ref('');
const search = ref('');
const statusFilter = ref('all');
const loading = ref(false);
const loadingMessages = ref(false);
const sending = ref(false);
const error = ref('');
const messageBody = ref(null);
const pendingDeleteId = ref(null);
const isDeletingConversation = ref(false);
const deleteError = ref('');
const isUpdatingStatus = ref(false);
const pendingStatusAction = ref(null); // { id, status: 'archived' | 'open' } | null
const statusActionError = ref('');
const stagedAttachments = ref([]);
const attachmentError = ref('');
const fileInput = ref(null);
const activeConversation = computed(() => conversations.value.find(item => item.id === activeId.value) || null);
// The server already applies search + status filtering and returns
// newest-first — this just guards against a stale ordering while a debounced
// search request is still in flight, matching the previous purely-client
// behaviour without re-implementing the filter itself.
const filteredConversations = computed(() =>
    [...conversations.value].sort((first, second) => timestamp(second.last_message_at) - timestamp(first.last_message_at)));
const hasUploadingAttachment = computed(() => stagedAttachments.value.some(a => a.uploading));
const canSend = computed(() => {
    const hasText = draft.value.trim().length > 0;
    const hasAttachment = stagedAttachments.value.some(a => a.uploaded);

    return !hasUploadingAttachment.value && (hasText || hasAttachment);
});
let pollTimer = null;
let polling = false;
let messageRequestVersion = 0;
let searchDebounce = null;

// Switching Chat A -> Chat B -> Chat A shouldn't refetch Chat A's history
// from scratch — keep whatever was last loaded for each conversation.
const messagesCache = new Map();

async function request(path, options = {}) {
    const response = await logisticsFetch(`/api/logistics/messages${path}`, options);
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.message || 'Messaging request failed.');
    return payload;
}
async function api(path, options = {}) {
    return (await request(path, options)).data;
}
async function apiWithMeta(path, options = {}) {
    const payload = await request(path, options);
    return { data: payload.data, meta: payload.meta };
}

function buildQuery(extra = {}) {
    const params = new URLSearchParams();
    if (search.value.trim()) params.set('search', search.value.trim());
    if (statusFilter.value && statusFilter.value !== 'all') params.set('status', statusFilter.value);
    for (const [key, value] of Object.entries(extra)) params.set(key, value);

    const qs = params.toString();
    return qs ? `?${qs}` : '';
}

async function loadConversations(silent = false) {
    if (!silent) { loading.value = true; error.value = ''; }
    try {
        const { data, meta } = await apiWithMeta(`/conversations${buildQuery()}`);
        conversations.value = data;
        conversationsMeta.value = meta;
    } catch (exception) {
        error.value = exception.message;
    } finally {
        if (!silent) loading.value = false;
    }
}

// Debounced so typing doesn't fire a request per keystroke — matches
// Seller\Messages.vue's search behaviour.
function onSearchInput() {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => loadConversations(), 350);
}

function setStatusFilter(status) {
    if (statusFilter.value === status) return;
    statusFilter.value = status;
    loadConversations();
}

function tabCount(key) {
    const counts = conversationsMeta.value.statusCounts || {};
    const map = { all: counts.all, unread: counts.unread, needs_response: counts.needsResponse, resolved: counts.resolved, archived: counts.archived };

    return map[key] || 0;
}

// Infinite scroll for the inbox list — the next page only loads once the
// list is actually scrolled near its bottom, instead of loading every
// conversation up front.
async function loadMoreConversations() {
    if (isLoadingMoreConversations.value || loading.value) return;
    if (conversationsMeta.value.currentPage >= conversationsMeta.value.lastPage) return;

    isLoadingMoreConversations.value = true;
    try {
        const nextPage = conversationsMeta.value.currentPage + 1;
        const { data, meta } = await apiWithMeta(`/conversations${buildQuery({ page: nextPage })}`);
        conversations.value = [...conversations.value, ...data];
        conversationsMeta.value = meta;
    } catch {
        // Silent — a failed "load more" just lets the user retry by scrolling again.
    } finally {
        isLoadingMoreConversations.value = false;
    }
}
function onListScroll(event) {
    const el = event.target;
    if (el.scrollHeight - el.scrollTop - el.clientHeight < 150) {
        loadMoreConversations();
    }
}

// Poll target for the sidebar: patches unread/preview/status on already-
// loaded conversations and prepends genuinely new ones, instead of
// replacing the whole list (which would also discard any pages already
// scrolled into via loadMoreConversations()).
async function syncConversationMeta() {
    try {
        const { data, meta } = await apiWithMeta(`/conversations${buildQuery()}`);
        conversationsMeta.value = meta || conversationsMeta.value;
        for (const incoming of data || []) {
            const local = conversations.value.find(c => c.id === incoming.id);
            if (local) {
                local.unread = incoming.unread;
                local.last_message = incoming.last_message;
                local.last_message_at = incoming.last_message_at;
                local.status = incoming.status;
            } else {
                conversations.value.unshift(incoming);
            }
        }
    } catch {
        // Background refresh — a transient miss just retries next tick.
    }
}

async function openConversation(id) {
    const requestVersion = ++messageRequestVersion;
    activeId.value = id;
    error.value = '';

    const cached = messagesCache.get(id);
    messages.value = cached ? cached.messages : [];
    messagesMeta.value = cached ? cached.meta : { hasMore: false, nextCursor: null };
    loadingMessages.value = !cached;

    try {
        const [, messagesResult] = await Promise.all([
            api(`/conversations/${id}`),
            apiWithMeta(`/conversations/${id}/messages?limit=${MESSAGES_PAGE_SIZE}`),
        ]);
        if (requestVersion !== messageRequestVersion || activeId.value !== id) return;

        const loaded = chronological(messagesResult.data);
        messages.value = loaded;
        messagesMeta.value = messagesResult.meta;
        messagesCache.set(id, { messages: loaded, meta: messagesResult.meta });

        const item = conversations.value.find(conversation => conversation.id === id);
        if (item) item.unread = 0;

        await nextTick();
        if (messageBody.value) messageBody.value.scrollTop = messageBody.value.scrollHeight;
    } catch (exception) {
        if (requestVersion === messageRequestVersion) error.value = exception.message;
    } finally {
        if (requestVersion === messageRequestVersion) loadingMessages.value = false;
    }
}

// Scroll-to-top-of-thread continuation — older history only loads in as
// the rider actually scrolls up to it, preserving scroll position so the
// view doesn't jump once it's prepended.
async function loadOlderMessages() {
    const id = activeId.value;
    if (!id || !messagesMeta.value.hasMore || isLoadingOlderMessages.value) return;

    isLoadingOlderMessages.value = true;
    const nextCursor = messagesMeta.value.nextCursor;

    try {
        const { data, meta } = await apiWithMeta(
            `/conversations/${id}/messages?limit=${MESSAGES_PAGE_SIZE}&before=${encodeURIComponent(nextCursor)}`,
        );
        if (activeId.value !== id) return;

        messages.value = [...chronological(data), ...messages.value];
        messagesMeta.value = meta;
        messagesCache.set(id, { messages: messages.value, meta });
    } catch {
        // Silent — a failed "load older" just lets the user retry by scrolling again.
    } finally {
        isLoadingOlderMessages.value = false;
    }
}
function onMessageBodyScroll() {
    const el = messageBody.value;
    if (!el || el.scrollTop >= 60) return;

    const prevHeight = el.scrollHeight;
    loadOlderMessages().then(() => {
        nextTick(() => {
            el.scrollTop = el.scrollHeight - prevHeight;
        });
    });
}

// Optimistic send: the message appears immediately with a "Sending…"
// state, then gets swapped for the server's copy (or flipped to "Failed"),
// instead of waiting on the network before showing anything.
async function send() {
    const body = draft.value.trim();
    const uploaded = stagedAttachments.value.filter(a => a.uploaded).map(a => a.uploaded);
    if ((!body && uploaded.length === 0) || sending.value || !activeId.value || hasUploadingAttachment.value) return;

    const conversationId = activeId.value;
    const attachmentIds = uploaded.map(a => a.id);
    draft.value = '';
    clearStagedAttachments();

    const localId = `local-${Date.now()}`;
    messages.value = chronological([
        ...messages.value,
        { id: localId, from: 'logistics', text: body, attachments: uploaded, at: new Date().toISOString(), status: 'sending' },
    ]);
    await nextTick();
    if (messageBody.value) messageBody.value.scrollTop = messageBody.value.scrollHeight;

    sending.value = true;
    try {
        const message = await api(`/conversations/${conversationId}/messages`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ body: body || null, attachment_ids: attachmentIds }),
        });
        const idx = messages.value.findIndex(m => m.id === localId);
        if (idx !== -1) messages.value[idx] = message;
        messagesCache.set(conversationId, { messages: messages.value, meta: messagesMeta.value });

        // Patch the sidebar preview locally instead of refetching the whole
        // conversation list just to show what was just sent.
        const item = conversations.value.find(conversation => conversation.id === conversationId);
        if (item) {
            item.last_message = body || (uploaded.length === 1 ? 'Sent an attachment' : 'Sent attachments');
            item.last_message_at = message.at;
        }
    } catch (exception) {
        error.value = exception.message;
        const idx = messages.value.findIndex(m => m.id === localId);
        if (idx !== -1) messages.value[idx] = { ...messages.value[idx], status: 'failed' };
    } finally {
        sending.value = false;
    }
}
function closeConversation() { ++messageRequestVersion; activeId.value = null; messages.value = []; loadingMessages.value = false; }

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

function findStagedAttachment(localId) {
    return stagedAttachments.value.find(a => a.localId === localId);
}

function onFilePicked(event) {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    attachmentError.value = '';

    for (const file of files) {
        if (stagedAttachments.value.length >= 5) {
            attachmentError.value = 'You can attach up to 5 files per message.';
            break;
        }

        const validationError = validateAttachment(file);

        if (validationError) {
            attachmentError.value = validationError;
            continue;
        }

        const localId = `attachment-${Date.now()}-${Math.random()}`;
        const isVideo = file.type.startsWith('video/');
        const previewUrl = file.type.startsWith('image/') || isVideo ? URL.createObjectURL(file) : null;

        stagedAttachments.value.push({
            localId, name: file.name, previewUrl, isVideo,
            uploading: true, uploaded: null, error: '',
        });

        const formData = new FormData();
        formData.append('file', file);

        api('/attachments', { method: 'POST', body: formData })
            .then(uploaded => {
                const staged = findStagedAttachment(localId);
                if (staged) { staged.uploaded = uploaded; staged.uploading = false; }
            })
            .catch(exception => {
                const staged = findStagedAttachment(localId);
                if (staged) { staged.error = exception.message || 'Upload failed.'; staged.uploading = false; }
            });
    }
}

function removeStagedAttachment(localId) {
    const staged = findStagedAttachment(localId);
    if (staged?.previewUrl) URL.revokeObjectURL(staged.previewUrl);
    stagedAttachments.value = stagedAttachments.value.filter(a => a.localId !== localId);
}

function clearStagedAttachments() {
    for (const attachment of stagedAttachments.value) {
        if (attachment.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
    }
    stagedAttachments.value = [];
    attachmentError.value = '';
}

// Archive/unarchive are per-user (Conversation::archiveFor()/
// unarchiveFor() on the backend) — hides the thread from this logistics
// account's own inbox without touching the seller's copy or blocking
// either side from sending. Returns the updated conversation on success,
// null on failure, so callers (runStatusAction() below, plus the
// composer's plain "Reopen it" link for a real resolved->open transition)
// can tell which happened.
async function setConversationStatus(id, status) {
    if (!id || isUpdatingStatus.value) return null;

    isUpdatingStatus.value = true;
    try {
        const updated = await api(`/conversations/${id}/status`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        });
        // activeConversation is a computed lookup into `conversations`, so
        // patching the array entry here is enough to update it everywhere.
        const item = conversations.value.find(conversation => conversation.id === id);
        if (item) {
            item.status = updated.status;
            item.archived = updated.archived;
        }
        return updated;
    } catch (exception) {
        error.value = exception.message;
        return null;
    } finally {
        isUpdatingStatus.value = false;
    }
}

function confirmArchive() {
    if (activeId.value) {
        statusActionError.value = '';
        pendingStatusAction.value = { id: activeId.value, status: 'archived' };
    }
}

function confirmUnarchive() {
    if (activeId.value) {
        statusActionError.value = '';
        pendingStatusAction.value = { id: activeId.value, status: 'open' };
    }
}

async function runStatusAction() {
    if (!pendingStatusAction.value) return;

    const { id, status } = pendingStatusAction.value;
    const result = await setConversationStatus(id, status);

    if (result) {
        pendingStatusAction.value = null;
    } else {
        statusActionError.value = 'Could not update this conversation.';
    }
}
// Removes the conversation from this rider company's own inbox only — the
// seller still sees their copy, and it reappears automatically if either
// side messages the other again (see Conversation::leaveFor()/
// reviveLeftParticipants() on the backend).
async function runDeleteConversation() {
    if (!pendingDeleteId.value || isDeletingConversation.value) return;
    isDeletingConversation.value = true;
    try {
        await api(`/conversations/${pendingDeleteId.value}`, { method: 'DELETE' });
        conversations.value = conversations.value.filter((c) => c.id !== pendingDeleteId.value);
        if (activeId.value === pendingDeleteId.value) closeConversation();
        pendingDeleteId.value = null;
    } catch (exception) {
        deleteError.value = exception.message;
    } finally {
        isDeletingConversation.value = false;
    }
}
function initials(name) { return (name || '?').split(/\s+/).map(part => part[0]).slice(0, 2).join('').toUpperCase(); }
function formatTime(value) { return value ? new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : ''; }
function formatListTime(value) { return value ? new Date(value).toLocaleDateString([], { month: 'short', day: 'numeric' }) : ''; }
function timestamp(value) { return value ? new Date(value).getTime() : 0; }
function chronological(items) { return [...items].sort((first, second) => timestamp(first.at) - timestamp(second.at)); }
onMounted(async () => {
    await loadConversations();
    pollTimer = window.setInterval(async () => {
        if (polling || document.hidden) return;
        polling = true;
        try {
            await syncConversationMeta();

            if (activeId.value && !sending.value) {
                const conversationId = activeId.value;
                const newestKnown = [...messages.value].reverse().find(m => !String(m.id).startsWith('local-'));

                if (newestKnown) {
                    const requestVersion = ++messageRequestVersion;
                    const { data } = await apiWithMeta(
                        `/conversations/${conversationId}/messages?after=${encodeURIComponent(newestKnown.id)}&limit=${MESSAGES_PAGE_SIZE}`,
                    );

                    if (requestVersion === messageRequestVersion && activeId.value === conversationId && !sending.value && data.length) {
                        const knownIds = new Set(messages.value.map(m => m.id));
                        const fresh = data.filter(m => !knownIds.has(m.id));

                        if (fresh.length) {
                            messages.value = chronological([...messages.value, ...fresh]);
                            messagesCache.set(conversationId, { messages: messages.value, meta: messagesMeta.value });
                        }
                    }
                }
            }
        } catch {
            // A transient background failure is retried on the next interval.
        } finally { polling = false; }
    }, POLL_MS);
});
onBeforeUnmount(() => window.clearInterval(pollTimer));
</script>

<style scoped>
.messages-card { display: grid; grid-template-columns: minmax(19rem, 23rem) minmax(0, 1fr); height: clamp(38rem, calc(100vh - 12rem), 52rem); min-height: 38rem; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 1rem; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
.thread-list, .chat-panel { min-width: 0; min-height: 0; }
.thread-list { display: flex; flex-direction: column; border-right: 1px solid #e2e8f0; }
.panel-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: 1.25rem 1.25rem 1rem; }
.eyebrow { margin: 0 0 0.2rem; color: #64748b; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.panel-heading h2, .chat-heading h3 { margin: 0; color: #0f172a; }
.heading-copy { margin: 0.35rem 0 0; color: #64748b; font-size: 0.75rem; line-height: 1.45; }
.icon-button, .back-button { display: grid; width: 2.25rem; height: 2.25rem; flex: none; place-items: center; border: 1px solid #e2e8f0; border-radius: 0.65rem; background: #fff; color: #64748b; cursor: pointer; }
.icon-button:hover, .back-button:hover { background: #f8fafc; color: #0f766e; }
.icon-button:disabled { cursor: wait; opacity: 0.55; }
.search-row { display: flex; align-items: center; gap: 0.5rem; margin: 0 1rem 0.85rem; }
.thread-search { display: flex; align-items: center; gap: 0.5rem; flex: 1; min-width: 0; padding: 0 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.7rem; color: #94a3b8; }
/* "View archived conversations" shortcut — mirrors the buyer popup's and
   seller's own equivalent button next to their search bars. */
.archived-shortcut { position: relative; display: flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; flex: none; border: 1px solid #cbd5e1; border-radius: 0.7rem; background: #fff; color: #94a3b8; cursor: pointer; transition: color 0.15s ease, border-color 0.15s ease; }
.archived-shortcut:hover, .archived-shortcut.active { color: #0f766e; border-color: #99f6e4; }
.archived-shortcut-badge { position: absolute; top: -0.3rem; right: -0.3rem; min-width: 0.9rem; height: 0.9rem; padding: 0 0.2rem; border-radius: 999px; background: #94a3b8; color: #fff; font-size: 0.58rem; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.thread-search:focus-within { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
.thread-search input { width: 100%; min-width: 0; padding: 0.65rem 0; border: 0; outline: 0; background: transparent; color: #0f172a; font: inherit; font-size: 0.78rem; }
.filter-tabs { display: flex; flex-wrap: wrap; gap: 0.35rem; margin: 0 1rem 0.85rem; }
.filter-tab { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.32rem 0.6rem; border: 1px solid #e2e8f0; border-radius: 999px; background: #fff; color: #64748b; font-size: 0.68rem; font-weight: 700; cursor: pointer; }
.filter-tab:hover { background: #f8fafc; }
.filter-tab.active { border-color: #0f766e; background: #f0fdfa; color: #0f766e; }
.filter-tab-count { padding: 0 0.35rem; border-radius: 999px; background: rgba(100, 116, 139, 0.15); font-size: 0.62rem; }
.filter-tab.active .filter-tab-count { background: rgba(15, 118, 110, 0.15); }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
.thread-scroll { flex: 1; min-height: 0; overflow-y: auto; padding: 0.15rem 0.55rem 0.75rem; border-top: 1px solid #f1f5f9; }
.thread-button { position: relative; display: flex; width: 100%; gap: 0.7rem; margin-top: 0.35rem; padding: 0.85rem; border: 0; border-left: 3px solid transparent; border-radius: 0.75rem; background: #fff; text-align: left; cursor: pointer; }
.thread-button:hover { background: #f8fafc; }
.thread-button.active { border-left-color: #0f766e; background: #f0fdfa; }
.thread-button.unread .thread-copy strong { font-weight: 800; }
.avatar { display: grid; width: 2.55rem; height: 2.55rem; flex: none; place-items: center; border-radius: 0.8rem; background: #ccfbf1; color: #0f766e; font-size: 0.78rem; font-weight: 800; }
img.avatar { object-fit: cover; display: block; }
.thread-copy { display: grid; min-width: 0; flex: 1; gap: 0.2rem; }
.thread-copy strong, .thread-copy > span, .thread-copy small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.thread-top { display: flex; justify-content: space-between; gap: 0.5rem; }
.thread-top time { flex: none; color: #94a3b8; font-size: 0.65rem; font-weight: 600; }
.thread-copy small { color: #64748b; font-size: 0.7rem; }
.thread-copy > span:last-child { color: #94a3b8; font-size: 0.75rem; }
.unread-badge { min-width: 1.2rem; height: 1.2rem; margin: 0.05rem 0 0; padding: 0 0.35rem; border-radius: 999px; background: #0f766e; color: #fff; font-size: 0.65rem; line-height: 1.2rem; text-align: center; }
.chat-panel { display: flex; flex-direction: column; background: #fff; }
.chat-heading { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; }
.back-button { display: none; border: 0; }
.chat-title { flex: 1; min-width: 0; }
.icon-button.danger:hover { border-color: #fecaca; background: #fef2f2; color: #dc2626; }
.chat-title .eyebrow { margin-bottom: 0.05rem; }
.context-tags { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.4rem; }
.context-tags span { padding: 0.2rem 0.45rem; border-radius: 999px; background: #f1f5f9; color: #64748b; font-size: 0.68rem; font-weight: 700; }
.message-body { flex: 1; min-height: 0; overflow-y: auto; padding: 1.5rem; background: #f8fafc; }
.message-row { display: flex; margin-bottom: 0.65rem; }
.message-row.mine { justify-content: flex-end; }
.bubble { max-width: min(72%, 38rem); padding: 0.65rem 0.85rem; border: 1px solid #e2e8f0; border-radius: 1rem 1rem 1rem 0.3rem; background: #fff; color: #1e293b; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.03); }
.mine .bubble { border-color: #0f766e; border-radius: 1rem 1rem 0.3rem 1rem; background: #0f766e; color: #fff; }
.bubble p { margin: 0; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
.bubble time { display: block; margin-top: 0.3rem; font-size: 0.65rem; opacity: 0.7; }
.mine .bubble time { text-align: right; }
.send-failed { display: block; margin-top: 0.3rem; font-size: 0.65rem; color: #fecaca; text-align: right; }
.composer { display: flex; flex-direction: column; gap: 0.5rem; padding: 0.85rem 1rem 1rem; border-top: 1px solid #e2e8f0; background: #fff; }
.composer-row { display: flex; align-items: flex-end; gap: 0.65rem; }
.composer textarea { flex: 1; min-height: 2.8rem; max-height: 8rem; resize: vertical; border: 1px solid #cbd5e1; border-radius: 0.8rem; padding: 0.7rem 0.8rem; font: inherit; }
.composer textarea:focus { border-color: #0f766e; outline: 0; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
.composer-notice { margin: 0; padding: 0.9rem 1rem; border-top: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 0.78rem; text-align: center; }
.retry-link { border: 0; background: none; padding: 0; color: #0f766e; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }
.retry-link:disabled { opacity: 0.5; cursor: wait; }
.status-tag { text-transform: capitalize; }
.status-tag.archived { background: #f1f5f9; color: #64748b; }
.status-tag.resolved { background: #dcfce7; color: #15803d; }
.staged-attachments { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.staged-attachment { position: relative; display: flex; align-items: center; gap: 0.4rem; max-width: 13rem; padding: 0.4rem 1.4rem 0.4rem 0.4rem; border: 1px solid #e2e8f0; border-radius: 0.7rem; background: #f8fafc; font-size: 0.7rem; }
.staged-attachment img, .staged-attachment video { width: 2.2rem; height: 2.2rem; border-radius: 0.5rem; object-fit: cover; flex: none; background: #0f172a; }
.staged-attachment-file { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; color: #334155; }
.staged-attachment-status { color: #64748b; }
.staged-attachment-status.error { color: #b91c1c; }
.staged-attachment-remove { position: absolute; top: 0.2rem; right: 0.2rem; display: grid; width: 1.1rem; height: 1.1rem; place-items: center; border: 0; border-radius: 50%; background: rgba(15, 23, 42, 0.55); color: #fff; font-size: 0.75rem; line-height: 1; cursor: pointer; }
.bubble-attachments { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.4rem; }
.bubble-attachment { display: block; width: 6rem; height: 6rem; border-radius: 0.75rem; overflow: hidden; background: #0f172a; }
.bubble-attachment img, .bubble-attachment video { width: 100%; height: 100%; object-fit: cover; display: block; }
.bubble-attachment-file { display: flex; align-items: center; justify-content: center; height: 100%; padding: 0.4rem; color: #fff; font-size: 0.65rem; text-align: center; overflow-wrap: anywhere; }
.bubble.bubble-media { background: transparent; border: 0; box-shadow: none; padding: 0; }
.error-copy.small { padding: 0; margin: 0; text-align: left; font-size: 0.72rem; }
.empty-copy, .error-copy { margin: 0; padding: 1.5rem; color: #64748b; font-size: 0.8rem; line-height: 1.55; text-align: center; }
.error-copy { color: #b91c1c; }
.empty-chat, .conversation-empty { display: grid; place-items: center; align-content: center; text-align: center; color: #64748b; }
.empty-chat { flex: 1; padding: 2rem; background: #f8fafc; }
.empty-chat strong, .conversation-empty strong { margin-top: 0.75rem; color: #334155; }
.empty-chat p, .conversation-empty p { max-width: 22rem; margin: 0.35rem 0 0; font-size: 0.8rem; }
.empty-chat-icon { display: grid; width: 3.5rem; height: 3.5rem; place-items: center; border-radius: 1rem; background: #ccfbf1; color: #0f766e; }
.conversation-empty { min-height: 100%; }
.skeleton-list { display: flex; flex-direction: column; gap: 0.35rem; padding: 0.5rem 0.85rem; }
.skeleton-row { display: flex; align-items: center; gap: 0.7rem; padding: 0.5rem 0; }
.skeleton-avatar { width: 2.55rem; height: 2.55rem; border-radius: 0.8rem; background: #e2e8f0; flex: none; animation: skeleton-pulse 1.4s ease-in-out infinite; }
.skeleton-lines { display: grid; flex: 1; gap: 0.4rem; }
.skeleton-line { height: 0.5rem; border-radius: 999px; background: #e2e8f0; animation: skeleton-pulse 1.4s ease-in-out infinite; }
@keyframes skeleton-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
.list-loading-more, .loading-older { display: flex; justify-content: center; padding: 0.75rem 0; }
.spinner { display: inline-block; width: 1.1rem; height: 1.1rem; border-radius: 50%; border: 2px solid #d1fae5; border-top-color: #0f766e; animation: spinner-spin 0.7s linear infinite; vertical-align: middle; }
.spinner-sm { width: 0.85rem; height: 0.85rem; border-width: 2px; border-color: rgba(255, 255, 255, 0.4); border-top-color: #fff; margin-right: 0.35rem; }
@keyframes spinner-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
    .skeleton-avatar, .skeleton-line, .spinner { animation: none; }
}
@media (max-width: 760px) {
    .messages-card { grid-template-columns: 1fr; height: calc(100vh - 9rem); min-height: 34rem; }
    .thread-list { border-right: 0; }
    .chat-panel { display: none; }
    .messages-card.has-active .thread-list { display: none; }
    .messages-card.has-active .chat-panel { display: flex; }
    .back-button { display: grid; }
    .chat-heading { padding-inline: 0.75rem; }
    .message-body { padding: 1rem; }
    .bubble { max-width: 88%; }
}
@media (max-width: 420px) {
    .context-tags span:last-child { display: none; }
    .composer { align-items: stretch; flex-direction: column; }
    .composer .btn-primary { width: 100%; }
}
</style>
