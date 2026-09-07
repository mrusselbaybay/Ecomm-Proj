<template>
    <section class="messages-card" :class="{ 'has-active': !!activeId }">
        <aside class="thread-list">
            <div class="panel-heading">
                <div><p class="eyebrow">Inbox</p><h2>Seller messages</h2><p class="heading-copy">Conversations for shipments handled by your team.</p></div>
                <button class="icon-button" :disabled="loading" aria-label="Refresh conversations" title="Refresh conversations" @click="loadConversations">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5M4 13a8.1 8.1 0 0 0 15.5 2M20 20v-5h-5" /></svg>
                </button>
            </div>

            <label class="thread-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <span class="sr-only">Search seller conversations</span>
                <input v-model="search" type="search" placeholder="Search seller, order, or region" />
            </label>

            <p v-if="error" class="error-copy">{{ error }}</p>
            <p v-if="loading && conversations.length === 0" class="empty-copy">Loading conversations...</p>
            <p v-else-if="conversations.length === 0" class="empty-copy">Conversations appear after your company assigns an accepted rider to a seller's parcel.</p>
            <p v-else-if="filteredConversations.length === 0" class="empty-copy">No conversations match your search.</p>

            <div v-else class="thread-scroll">
                <button v-for="conversation in filteredConversations" :key="conversation.id" class="thread-button" :class="{ active: conversation.id === activeId, unread: conversation.unread > 0 }" @click="openConversation(conversation.id)">
                    <span class="avatar">{{ initials(conversation.seller.name) }}</span>
                    <span class="thread-copy">
                        <span class="thread-top"><strong>{{ conversation.seller.name }}</strong><time>{{ formatListTime(conversation.last_message_at) }}</time></span>
                        <small>Order #{{ conversation.order.number }} - {{ conversation.shipment.region }}</small>
                        <span>{{ conversation.last_message || 'Shipment conversation ready' }}</span>
                    </span>
                    <b v-if="conversation.unread" class="unread-badge">{{ conversation.unread }}</b>
                </button>
            </div>
        </aside>

        <div class="chat-panel">
            <header v-if="activeConversation" class="chat-heading">
                <button class="back-button" type="button" aria-label="Back to conversations" @click="closeConversation">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <span class="avatar">{{ initials(activeConversation.seller.name) }}</span>
                <div class="chat-title">
                    <p class="eyebrow">Seller</p><h3>{{ activeConversation.seller.name }}</h3>
                    <div class="context-tags"><span>Order #{{ activeConversation.order.number }}</span><span>{{ activeConversation.shipment.region }}</span><span>Rider: {{ activeConversation.shipment.rider }}</span></div>
                </div>
            </header>

            <div v-if="activeConversation" ref="messageBody" class="message-body">
                <div v-for="message in messages" :key="message.id" class="message-row" :class="message.from === 'logistics' ? 'mine' : 'theirs'">
                    <div class="bubble"><p>{{ message.text }}</p><time>{{ formatTime(message.at) }}</time></div>
                </div>
                <div v-if="loadingMessages" class="conversation-empty"><p>Loading this order's messages...</p></div>
                <div v-else-if="messages.length === 0" class="conversation-empty"><strong>No messages yet</strong><p>Start the conversation about this assigned shipment.</p></div>
            </div>

            <form v-if="activeConversation" class="composer" @submit.prevent="send">
                <textarea v-model="draft" rows="2" maxlength="4000" placeholder="Message the seller about this shipment..."></textarea>
                <button class="btn-primary" :disabled="sending || !draft.trim()">{{ sending ? 'Sending...' : 'Send' }}</button>
            </form>

            <div v-else class="empty-chat">
                <span class="empty-chat-icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 10h8M8 14h4" /><path d="M21 12c0 4.4-4 8-9 8-1.1 0-2.1-.2-3-.5L3 21l1.5-3.8A7.3 7.3 0 0 1 3 13c0-4.4 4-8 9-8s9 3.6 9 7Z" /></svg></span>
                <strong>Select a conversation</strong><p>Choose a seller shipment from the inbox to view its messages.</p>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const { logisticsFetch } = useLogistics();
const conversations = ref([]);
const activeId = ref(null);
const messages = ref([]);
const draft = ref('');
const search = ref('');
const loading = ref(false);
const loadingMessages = ref(false);
const sending = ref(false);
const error = ref('');
const messageBody = ref(null);
const activeConversation = computed(() => conversations.value.find(item => item.id === activeId.value) || null);
const filteredConversations = computed(() => {
    const query = search.value.trim().toLowerCase();
    const newestFirst = [...conversations.value].sort((first, second) =>
        timestamp(second.last_message_at) - timestamp(first.last_message_at));
    if (!query) return newestFirst;

    return newestFirst.filter(conversation => [conversation.seller.name, conversation.order.number, conversation.shipment.region, conversation.shipment.rider]
        .some(value => String(value || '').toLowerCase().includes(query)));
});
let pollTimer = null;
let polling = false;
let messageRequestVersion = 0;

async function api(path, options = {}) {
    const response = await logisticsFetch(`/api/logistics/messages${path}`, options);
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.message || 'Messaging request failed.');
    return payload.data;
}
async function loadConversations(silent = false) {
    if (!silent) { loading.value = true; error.value = ''; }
    try { conversations.value = await api('/conversations'); } catch (exception) { error.value = exception.message; }
    finally { if (!silent) loading.value = false; }
}
async function openConversation(id) {
    const requestVersion = ++messageRequestVersion;
    activeId.value = id;
    messages.value = [];
    loadingMessages.value = true;
    try {
        await api(`/conversations/${id}`);
        const loadedMessages = await api(`/conversations/${id}/messages`);
        if (requestVersion !== messageRequestVersion || activeId.value !== id) return;
        messages.value = chronological(loadedMessages);
        const item = conversations.value.find(conversation => conversation.id === id);
        if (item) item.unread = 0;
        await nextTick();
        if (messageBody.value) messageBody.value.scrollTop = messageBody.value.scrollHeight;
    } finally {
        if (requestVersion === messageRequestVersion) loadingMessages.value = false;
    }
}
async function send() {
    const body = draft.value.trim();
    if (!body || sending.value) return;
    sending.value = true;
    ++messageRequestVersion;
    try {
        const message = await api(`/conversations/${activeId.value}/messages`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ body }) });
        messages.value = chronological([...messages.value, message]); draft.value = '';
        await nextTick();
        if (messageBody.value) messageBody.value.scrollTop = messageBody.value.scrollHeight;
        await loadConversations();
    } catch (exception) { error.value = exception.message; }
    finally { sending.value = false; }
}
function closeConversation() { ++messageRequestVersion; activeId.value = null; messages.value = []; loadingMessages.value = false; }
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
            await loadConversations(true);
            if (activeId.value && !sending.value) {
                const conversationId = activeId.value;
                const requestVersion = ++messageRequestVersion;
                const loadedMessages = await api(`/conversations/${conversationId}/messages`);
                if (requestVersion === messageRequestVersion && activeId.value === conversationId && !sending.value) {
                    messages.value = chronological(loadedMessages);
                }
            }
        } catch {
            // A transient background failure is retried on the next interval.
        } finally { polling = false; }
    }, 30000);
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
.thread-search { display: flex; align-items: center; gap: 0.5rem; margin: 0 1rem 0.85rem; padding: 0 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.7rem; color: #94a3b8; }
.thread-search:focus-within { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
.thread-search input { width: 100%; min-width: 0; padding: 0.65rem 0; border: 0; outline: 0; background: transparent; color: #0f172a; font: inherit; font-size: 0.78rem; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
.thread-scroll { flex: 1; min-height: 0; overflow-y: auto; padding: 0.15rem 0.55rem 0.75rem; border-top: 1px solid #f1f5f9; }
.thread-button { position: relative; display: flex; width: 100%; gap: 0.7rem; margin-top: 0.35rem; padding: 0.85rem; border: 0; border-left: 3px solid transparent; border-radius: 0.75rem; background: #fff; text-align: left; cursor: pointer; }
.thread-button:hover { background: #f8fafc; }
.thread-button.active { border-left-color: #0f766e; background: #f0fdfa; }
.thread-button.unread .thread-copy strong { font-weight: 800; }
.avatar { display: grid; width: 2.55rem; height: 2.55rem; flex: none; place-items: center; border-radius: 0.8rem; background: #ccfbf1; color: #0f766e; font-size: 0.78rem; font-weight: 800; }
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
.chat-title { min-width: 0; }
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
.composer { display: flex; align-items: flex-end; gap: 0.65rem; padding: 0.85rem 1rem 1rem; border-top: 1px solid #e2e8f0; background: #fff; }
.composer textarea { flex: 1; min-height: 2.8rem; max-height: 8rem; resize: vertical; border: 1px solid #cbd5e1; border-radius: 0.8rem; padding: 0.7rem 0.8rem; font: inherit; }
.composer textarea:focus { border-color: #0f766e; outline: 0; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
.empty-copy, .error-copy { margin: 0; padding: 1.5rem; color: #64748b; font-size: 0.8rem; line-height: 1.55; text-align: center; }
.error-copy { color: #b91c1c; }
.empty-chat, .conversation-empty { display: grid; place-items: center; align-content: center; text-align: center; color: #64748b; }
.empty-chat { flex: 1; padding: 2rem; background: #f8fafc; }
.empty-chat strong, .conversation-empty strong { margin-top: 0.75rem; color: #334155; }
.empty-chat p, .conversation-empty p { max-width: 22rem; margin: 0.35rem 0 0; font-size: 0.8rem; }
.empty-chat-icon { display: grid; width: 3.5rem; height: 3.5rem; place-items: center; border-radius: 1rem; background: #ccfbf1; color: #0f766e; }
.conversation-empty { min-height: 100%; }
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
