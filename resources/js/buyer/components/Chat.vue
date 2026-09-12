<script setup>
/*
|--------------------------------------------------------------------------
| Chat.vue — buyer <-> seller messaging popup
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Modern Multi-Pane
| Chat Popup") onto BuyTheWay's stack the same way as the rest of the buyer
| area: Tailwind utilities, inline SVG icons (the reference's iconify web
| component isn't a dependency here), #0d9488 brand teal.
|
| All state lives in useBuyerChat.js — see that file for what's real
| (open/close, thread switching, unread, contact search, sending) and
| what's a seeded placeholder (no backend, no seller replies). The
| reference's voice/video-call buttons are omitted; file attachments use
| the same validated staging flow as seller messaging.
|
| UX added on top of the static reference:
|   - Fully interactive: switch threads, send, unread clears on open.
|   - Keyboard: Enter sends, Esc closes; the input autofocuses on open.
|   - Backdrop click closes; body scroll locks while open.
|   - Thread auto-scrolls to the newest message.
|   - One pane at a time on small screens, with a back button.
|   - Empty-thread and no-search-results states.
|   - Rendered through <Teleport> so it sits above the sticky header's
|     stacking context, and focus returns to the message icon on close.
|
*/
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

import AttachmentVideoPlayer from '../../shared/AttachmentVideoPlayer.vue';
import { useBuyerChat } from '../composables/useBuyerChat';

const {
    isChatOpen,
    conversations,
    isLoading,
    isLoadingMoreConversations,
    activeConversationId,
    activeConversation,
    isViewingArchived,
    conversationsMeta,
    closeChat,
    openConversation,
    deleteConversation,
    setConversationStatus,
    showArchivedConversations,
    showInboxConversations,
    loadMoreConversations,
    loadOlderMessages,
    sendMessage,
    validateAttachment,
    uploadAttachment,
} = useBuyerChat();

const search = ref('');
const draft = ref('');
const stagedAttachments = ref([]);
const attachmentError = ref('');
const previewMedia = ref(null);
const pendingDeleteId = ref(null);
const isDeletingConversation = ref(false);
const deleteError = ref('');
const isUpdatingStatus = ref(false);

// 'list' | 'thread' — only matters below the md breakpoint, where the two
// panes don't fit side by side.
const mobileView = ref('thread');

const messageInput = ref(null);
const fileInput = ref(null);
const threadBody = ref(null);

const hasUploadingAttachment = computed(() =>
    stagedAttachments.value.some(attachment => attachment.uploading),
);

const canSend = computed(() => {
    const hasText = draft.value.trim().length > 0;
    const hasAttachment = stagedAttachments.value.some(attachment => attachment.uploaded);

    return !hasUploadingAttachment.value && (hasText || hasAttachment);
});

const filteredConversations = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return conversations.value;
    }

    return conversations.value.filter(
        convo => convo.seller.toLowerCase().includes(term)
    );
});

function lastMessageText(convo) {
    // Prefer the actually-loaded thread (present once this conversation has
    // been opened) since it reflects anything sent since the last list
    // fetch; otherwise fall back to the server's denormalised preview so an
    // unopened conversation (e.g. one a checkout just auto-started) shows
    // its real last message instead of a false "No messages yet".
    const lastMessage = convo.messages[convo.messages.length - 1];

    if (lastMessage?.text) {
        return lastMessage.text;
    }

    if (lastMessage?.attachments?.length) {
        return 'Sent an attachment';
    }

    return convo.lastMessagePreview || 'No messages yet';
}

function initials(name) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(word => word[0].toUpperCase())
        .join('');
}

function formatPrice(value) {
    return `₱${Number(value || 0).toFixed(2)}`;
}

function scrollThreadToBottom() {
    nextTick(() => {
        const el = threadBody.value;

        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    });
}

async function selectConversation(id) {
    mobileView.value = 'thread';
    // Awaited so the bottom-scroll happens once the messages have actually
    // rendered — firing it immediately raced the fetch and could leave the
    // thread scrolled to wherever it happened to be mid-load (looking like
    // it "opened at the top").
    await openConversation(id);
    scrollThreadToBottom();
}

function confirmDeleteConversation() {
    if (activeConversationId.value) {
        deleteError.value = '';
        pendingDeleteId.value = activeConversationId.value;
    }
}

async function runDeleteConversation() {
    if (!pendingDeleteId.value || isDeletingConversation.value) {
        return;
    }

    isDeletingConversation.value = true;

    try {
        await deleteConversation(pendingDeleteId.value);
        pendingDeleteId.value = null;
        mobileView.value = 'list';
    } catch (error) {
        deleteError.value = error?.message || 'Could not delete this conversation.';
    } finally {
        isDeletingConversation.value = false;
    }
}

// Archive/unarchive both go through a confirmation step (item 3/6 —
// unlike a plain "reopen a resolved thread" which stays a direct action).
const pendingStatusAction = ref(null); // { id, status: 'archived' | 'open' } | null
const statusActionError = ref('');

function confirmArchiveConversation() {
    if (activeConversationId.value) {
        statusActionError.value = '';
        pendingStatusAction.value = { id: activeConversationId.value, status: 'archived' };
    }
}

function confirmUnarchiveConversation() {
    if (activeConversationId.value) {
        statusActionError.value = '';
        pendingStatusAction.value = { id: activeConversationId.value, status: 'open' };
    }
}

async function runStatusAction() {
    if (!pendingStatusAction.value || isUpdatingStatus.value) {
        return;
    }

    const { id, status } = pendingStatusAction.value;
    isUpdatingStatus.value = true;

    try {
        await setConversationStatus(id, status);
        pendingStatusAction.value = null;

        if (status === 'archived' && activeConversationId.value !== id) {
            mobileView.value = 'list';
        }
    } catch (error) {
        statusActionError.value = error?.message || 'Could not update this conversation.';
    } finally {
        isUpdatingStatus.value = false;
    }
}

// "Reopen" a resolved thread — a real shared-status transition (not
// archive-related), so it stays a direct action, no confirmation needed.
async function reopenConversation(id) {
    if (!id || isUpdatingStatus.value) {
        return;
    }

    isUpdatingStatus.value = true;

    try {
        await setConversationStatus(id, 'open');
    } catch (error) {
        console.error('Error reopening conversation:', error);
    } finally {
        isUpdatingStatus.value = false;
    }
}

async function openArchivedView() {
    mobileView.value = 'list';
    await showArchivedConversations();
}

async function closeArchivedView() {
    await showInboxConversations();
}

// Infinite scroll: only fetch the next page of sellers once the list is
// actually scrolled near its bottom, instead of loading the whole inbox
// up front.
function onListScroll(event) {
    const el = event.target;

    if (el.scrollHeight - el.scrollTop - el.clientHeight < 150) {
        loadMoreConversations();
    }
}

// Older-history pagination: scrolling near the top of the open thread
// loads the previous page of messages, preserving scroll position so the
// view doesn't jump once the older messages are prepended.
function onThreadScroll() {
    const el = threadBody.value;
    const convo = activeConversation.value;

    if (!el || !convo || el.scrollTop >= 60 || !convo.messagesMeta?.hasMore || convo.isLoadingOlderMessages) {
        return;
    }

    const prevHeight = el.scrollHeight;

    loadOlderMessages().then(() => {
        nextTick(() => {
            el.scrollTop = el.scrollHeight - prevHeight;
        });
    });
}

function handleSend() {
    const uploadedAttachments = stagedAttachments.value
        .filter(attachment => attachment.uploaded)
        .map(attachment => attachment.uploaded);
    const attachmentIds = uploadedAttachments.map(attachment => attachment.id);

    if (sendMessage(draft.value, attachmentIds, uploadedAttachments)) {
        draft.value = '';
        clearStagedAttachments();
        scrollThreadToBottom();
    }
}

function isImageAttachment(attachment) {
    return attachment.mime?.startsWith('image/');
}

function isVideoAttachment(attachment) {
    return attachment.mime?.startsWith('video/');
}

function openMediaPreview(url, isVideo, name) {
    previewMedia.value = { url, isVideo, name };
}

function previewSentAttachment(attachment) {
    openMediaPreview(attachment.url, isVideoAttachment(attachment), attachment.name);
}

function previewStagedAttachment(attachment) {
    if (!attachment.previewUrl) {
        return;
    }

    openMediaPreview(attachment.previewUrl, attachment.isVideo, attachment.name);
}

function formatFileSize(size) {
    if (!size) {
        return '';
    }

    return size >= 1024 * 1024
        ? `${(size / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(size / 1024))} KB`;
}

function findStagedAttachment(localId) {
    return stagedAttachments.value.find(attachment => attachment.localId === localId);
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
            localId,
            name: file.name,
            previewUrl,
            isVideo,
            uploading: true,
            uploaded: null,
            error: '',
        });

        uploadAttachment(file)
            .then(uploaded => {
                const staged = findStagedAttachment(localId);

                if (staged) {
                    staged.uploaded = uploaded;
                    staged.uploading = false;
                }
            })
            .catch(error => {
                const staged = findStagedAttachment(localId);

                if (staged) {
                    staged.error = error?.message || 'Upload failed.';
                    staged.uploading = false;
                }
            });
    }
}

function removeStagedAttachment(localId) {
    const staged = findStagedAttachment(localId);

    if (staged?.previewUrl) {
        URL.revokeObjectURL(staged.previewUrl);
    }

    stagedAttachments.value = stagedAttachments.value.filter(
        attachment => attachment.localId !== localId,
    );
}

function clearStagedAttachments() {
    for (const attachment of stagedAttachments.value) {
        if (attachment.previewUrl) {
            URL.revokeObjectURL(attachment.previewUrl);
        }
    }

    stagedAttachments.value = [];
    attachmentError.value = '';
}

function handleKeydown(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (pendingDeleteId.value) {
        pendingDeleteId.value = null;
    } else if (pendingStatusAction.value) {
        pendingStatusAction.value = null;
    } else if (previewMedia.value) {
        previewMedia.value = null;
    } else {
        closeChat();
    }
}

watch(isChatOpen, open => {
    if (typeof document !== 'undefined') {
        document.body.style.overflow = open ? 'hidden' : '';
    }

    if (open) {
        mobileView.value = 'thread';
        window.addEventListener('keydown', handleKeydown);
        scrollThreadToBottom();
        nextTick(() => messageInput.value?.focus());
    } else {
        window.removeEventListener('keydown', handleKeydown);
        search.value = '';

        // Return focus to whatever opened the popup (the header message
        // icon) so keyboard users aren't dropped at the top of the page.
        nextTick(() => {
            document.querySelector('[data-chat-trigger]')?.focus();
        });
    }
});

// Covers the chat-popup-open auto-select-first-conversation path (no
// selectConversation() click to await there) — fires once a thread's
// first message page actually finishes loading, rather than racing the
// fetch the way watching activeConversationId directly would.
watch(() => activeConversation.value?.messagesLoaded, loaded => {
    if (loaded) {
        scrollThreadToBottom();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
    clearStagedAttachments();

    if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition name="chat-fade">
            <div
                v-if="isChatOpen"
                class="fixed inset-0 z-[60] bg-slate-900/20 backdrop-blur-[2px]"
                @click="closeChat"
            ></div>
        </Transition>

        <Transition name="chat-pop">
            <div
                v-if="isChatOpen"
                class="fixed z-[61] inset-x-3 bottom-3 top-3 sm:inset-x-auto sm:top-auto sm:right-6 sm:bottom-6 sm:w-[850px] sm:h-[650px] sm:max-h-[calc(100vh-3rem)] flex flex-row overflow-hidden rounded-3xl sm:rounded-[2.5rem] border border-slate-200 bg-white"
                style="box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.15), 0 4px 12px -4px rgba(0, 0, 0, 0.1);"
                role="dialog"
                aria-modal="true"
                aria-label="Messages"
            >
                <!-- ==================================================== -->
                <!-- LEFT: CONTACTS -->
                <!-- ==================================================== -->

                <div
                    class="w-full md:w-72 shrink-0 flex-col border-r border-slate-100 bg-slate-50/60"
                    :class="mobileView === 'list' ? 'flex' : 'hidden md:flex'"
                >
                    <div class="p-5 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <button
                                    v-if="isViewingArchived"
                                    type="button"
                                    class="w-7 h-7 -ml-1 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                                    aria-label="Back to inbox"
                                    title="Back to inbox"
                                    @click="closeArchivedView"
                                >
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m15 18-6-6 6-6" />
                                    </svg>
                                </button>
                                <h2 class="text-lg font-bold text-slate-900">{{ isViewingArchived ? 'Archived' : 'Messages' }}</h2>
                            </div>
                            <button
                                type="button"
                                class="md:hidden w-8 h-8 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                                aria-label="Close messages"
                                @click="closeChat"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1 min-w-0">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
                                    </svg>
                                </span>
                                <input
                                    v-model="search"
                                    type="text"
                                    :placeholder="isViewingArchived ? 'Search archived…' : 'Search sellers…'"
                                    class="w-full pl-9 pr-3 py-2.5 bg-white border border-slate-200 rounded-xl text-xs focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                >
                            </div>
                            <button
                                v-if="!isViewingArchived"
                                type="button"
                                class="relative shrink-0 w-9 h-9 flex items-center justify-center rounded-xl border border-slate-200 text-slate-400 hover:border-[#0d9488] hover:text-[#0d9488] transition-colors"
                                title="View archived conversations"
                                aria-label="View archived conversations"
                                @click="openArchivedView"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4" />
                                </svg>
                                <span
                                    v-if="conversationsMeta.archived_total > 0"
                                    class="absolute -top-1 -right-1 min-w-3.5 h-3.5 px-1 rounded-full bg-slate-400 text-white text-[8px] font-bold flex items-center justify-center"
                                >{{ conversationsMeta.archived_total }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto px-2 pb-4 space-y-1" @scroll="onListScroll">
                        <!-- Skeleton loader while the seller list is being fetched -->
                        <div v-if="isLoading && conversations.length === 0" class="space-y-1" aria-hidden="true">
                            <div v-for="n in 5" :key="n" class="flex items-center gap-3 px-3 py-3 animate-pulse">
                                <span class="w-11 h-11 rounded-xl bg-slate-200 shrink-0"></span>
                                <div class="flex-1 min-w-0 space-y-2">
                                    <div class="flex justify-between gap-2">
                                        <span class="h-2.5 w-24 rounded-full bg-slate-200"></span>
                                        <span class="h-2.5 w-8 rounded-full bg-slate-200"></span>
                                    </div>
                                    <span class="block h-2.5 w-36 rounded-full bg-slate-200"></span>
                                </div>
                            </div>
                        </div>

                        <template v-else>
                            <button
                                v-for="convo in filteredConversations"
                                :key="convo.id"
                                type="button"
                                class="w-full text-left px-3 py-3 rounded-2xl transition-colors border"
                                :class="convo.id === activeConversationId
                                    ? 'bg-white border-teal-100 shadow-sm'
                                    : 'border-transparent hover:bg-white/70'"
                                @click="selectConversation(convo.id)"
                            >
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="convo.avatarUrl"
                                        :src="convo.avatarUrl"
                                        :alt="convo.seller"
                                        loading="lazy"
                                        class="w-11 h-11 shrink-0 rounded-xl object-cover"
                                    >
                                    <span v-else class="w-11 h-11 shrink-0 rounded-xl bg-[#0d9488]/10 text-[#0d9488] text-sm font-bold flex items-center justify-center">
                                        {{ initials(convo.seller) }}
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between items-baseline gap-2 mb-0.5">
                                            <span class="text-[13px] font-bold text-slate-900 truncate">{{ convo.seller }}</span>
                                            <span
                                                class="text-[10px] font-medium shrink-0"
                                                :class="convo.unread ? 'text-[#0d9488]' : 'text-slate-400'"
                                            >{{ convo.updatedAt }}</span>
                                        </div>
                                        <div class="flex justify-between items-center gap-2">
                                            <p
                                                class="text-[11px] truncate"
                                                :class="convo.unread ? 'text-slate-600 font-medium' : 'text-slate-400'"
                                            >{{ lastMessageText(convo) }}</p>
                                            <span
                                                v-if="convo.unread"
                                                class="shrink-0 min-w-4 h-4 px-1 bg-[#0d9488] text-[9px] font-bold text-white flex items-center justify-center rounded-full"
                                            >{{ convo.unread }}</span>
                                        </div>
                                    </div>
                                </div>
                            </button>

                            <p
                                v-if="filteredConversations.length === 0 && search.trim()"
                                class="px-3 py-6 text-center text-[11px] text-slate-400"
                            >
                                No sellers match "{{ search }}".
                            </p>
                            <p
                                v-else-if="filteredConversations.length === 0"
                                class="px-3 py-6 text-center text-[11px] text-slate-400"
                            >
                                {{ isViewingArchived ? 'No archived conversations.' : 'No messages yet.' }}
                            </p>

                            <div v-if="isLoadingMoreConversations" class="flex justify-center py-3" aria-hidden="true">
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-200 border-t-[#0d9488]"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- ==================================================== -->
                <!-- RIGHT: ACTIVE THREAD -->
                <!-- ==================================================== -->

                <div
                    class="flex-1 min-w-0 flex-col"
                    :class="mobileView === 'thread' ? 'flex' : 'hidden md:flex'"
                >
                    <template v-if="activeConversation">
                        <!-- Thread header -->
                        <div class="shrink-0 px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <button
                                    type="button"
                                    class="md:hidden p-1 -ml-1 text-slate-400 hover:text-slate-600"
                                    aria-label="Back to conversations"
                                    @click="mobileView = 'list'"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m15 18-6-6 6-6" />
                                    </svg>
                                </button>
                                <img
                                    v-if="activeConversation.avatarUrl"
                                    :src="activeConversation.avatarUrl"
                                    :alt="activeConversation.seller"
                                    class="w-10 h-10 shrink-0 rounded-2xl object-cover"
                                >
                                <span v-else class="w-10 h-10 shrink-0 rounded-2xl bg-[#0d9488]/10 text-[#0d9488] text-sm font-bold flex items-center justify-center">
                                    {{ initials(activeConversation.seller) }}
                                </span>
                                <div class="min-w-0">
                                    <h3 class="font-bold text-slate-900 leading-tight text-[15px] truncate">{{ activeConversation.seller }}</h3>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="text-[10px] text-slate-400 font-medium">Seller since {{ activeConversation.memberSince }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <button
                                    v-if="!activeConversation.archived"
                                    type="button"
                                    class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors disabled:opacity-40"
                                    aria-label="Archive conversation"
                                    title="Archive conversation"
                                    :disabled="isUpdatingStatus"
                                    @click="confirmArchiveConversation"
                                >
                                    <span v-if="isUpdatingStatus && pendingStatusAction?.id === activeConversationId" class="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-600"></span>
                                    <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4" />
                                    </svg>
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-teal-50 hover:text-[#0d9488] transition-colors disabled:opacity-40"
                                    aria-label="Unarchive conversation"
                                    title="Unarchive conversation"
                                    :disabled="isUpdatingStatus"
                                    @click="confirmUnarchiveConversation"
                                >
                                    <span v-if="isUpdatingStatus && pendingStatusAction?.id === activeConversationId" class="h-4 w-4 animate-spin rounded-full border-2 border-teal-200 border-t-[#0d9488]"></span>
                                    <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8" /><path d="M12 17V9m0 0-3 3m3-3 3 3" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-red-500 transition-colors"
                                    aria-label="Delete conversation"
                                    title="Delete conversation"
                                    @click="confirmDeleteConversation"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6" /><path d="M10 11v6" /><path d="M14 11v6" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-red-500 transition-colors"
                                    aria-label="Close messages"
                                    @click="closeChat"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Thread body -->
                        <div
                            ref="threadBody"
                            class="flex-1 overflow-y-auto p-5 space-y-5 bg-slate-50/30"
                            @scroll="onThreadScroll"
                        >
                            <div v-if="activeConversation.isLoadingOlderMessages" class="flex justify-center" aria-hidden="true">
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-200 border-t-[#0d9488]"></span>
                            </div>

                            <div class="flex justify-center">
                                <span class="px-3 py-1 bg-slate-100 rounded-full text-[9px] font-bold text-slate-400 uppercase tracking-widest">Conversation</span>
                            </div>

                            <!-- Messages -->
                            <template v-for="message in activeConversation.messages" :key="message.id">
                            <!-- Auto-generated "order placed" system message (see
                                 DirectConversationService::startForOrder()): one
                                 self-contained centered card with the actual product
                                 photo + order number + item count + total, instead of
                                 a small "Order #X" chip plus a separate text bubble
                                 repeating the same numbers as a redundant sentence. -->
                            <template v-if="message.from === 'system' && message.orderContext">
                            <div class="flex justify-end max-w-[85%] ml-auto">
                                <div class="flex w-full max-w-[320px] items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                                    <span
                                        v-if="message.orderContext.previewImage"
                                        class="h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-slate-100"
                                    >
                                        <img
                                            :src="message.orderContext.previewImage"
                                            :alt="message.orderContext.previewName || 'Order item'"
                                            loading="lazy"
                                            class="h-full w-full object-cover"
                                        >
                                    </span>
                                    <span v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-[#0d9488]/10 text-[#0d9488]">
                                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" /><path d="M3 6h18" /><path d="M16 10a4 4 0 0 1-8 0" />
                                        </svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-slate-900">{{ message.orderContext.previewName || `Order #${message.orderContext.orderNumber}` }}</p>
                                        <p class="mt-0.5 text-[13px] text-slate-500">
                                            {{ message.orderContext.itemCount }} item{{ message.orderContext.itemCount === 1 ? '' : 's' }}, Total: {{ formatPrice(message.orderContext.total) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end pr-1 text-[9px] text-slate-400">
                                {{ message.at }}
                            </div>
                            </template>

                            <template v-else>
                            <!-- Inline inquiry card: which purchase this particular message was
                                 about. A thread now covers every purchase from one seller, so
                                 this replaces the old single per-conversation "Regarding" card. -->
                            <div
                                v-if="message.productContext || message.orderContext"
                                class="flex max-w-[85%]"
                                :class="message.from === 'buyer' ? 'justify-end ml-auto' : 'justify-start'"
                            >
                                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-2.5 pr-4">
                                    <span
                                        v-if="message.productContext?.image || message.orderContext?.previewImage"
                                        class="h-11 w-11 shrink-0 overflow-hidden rounded-xl bg-slate-100"
                                    >
                                        <img
                                            :src="message.productContext?.image || message.orderContext?.previewImage"
                                            :alt="message.productContext?.name || message.orderContext?.previewName"
                                            class="h-full w-full object-cover"
                                        >
                                    </span>
                                    <span v-else class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#0d9488]/10 text-[#0d9488]">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" /><path d="M3 6h18" /><path d="M16 10a4 4 0 0 1-8 0" />
                                        </svg>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[8px] font-bold uppercase tracking-widest text-slate-400">Regarding</span>
                                        <span v-if="message.productContext" class="block truncate text-xs font-bold text-slate-900">{{ message.productContext.name }}</span>
                                        <span v-else class="block truncate text-xs font-bold text-slate-900">Order #{{ message.orderContext.orderNumber }}</span>
                                        <span v-if="message.productContext" class="block text-[11px] font-bold text-[#0d9488]">{{ formatPrice(message.productContext.price) }}</span>
                                    </span>
                                </div>
                            </div>

                            <div
                                class="flex gap-2.5 max-w-[85%]"
                                :class="message.from === 'buyer' ? 'flex-row-reverse ml-auto' : ''"
                            >
                                <img
                                    v-if="message.from === 'seller' && activeConversation.avatarUrl"
                                    :src="activeConversation.avatarUrl"
                                    :alt="activeConversation.seller"
                                    class="w-7 h-7 rounded-lg object-cover self-end shrink-0"
                                >
                                <span
                                    v-else-if="message.from === 'seller'"
                                    class="w-7 h-7 rounded-lg bg-[#0d9488]/10 text-[#0d9488] text-[10px] font-bold flex items-center justify-center self-end shrink-0"
                                >{{ initials(activeConversation.seller) }}</span>
                                <div class="space-y-1 min-w-0">
                                    <!-- Attachments render bare (no bubble chrome), laid out in a
                                         horizontal row of fixed-size thumbnails — Messenger/Instagram
                                         style, instead of stacking full-size media vertically. -->
                                    <div
                                        v-if="message.attachments?.length"
                                        class="flex flex-wrap gap-1.5"
                                        :class="message.from === 'buyer' ? 'justify-end' : 'justify-start'"
                                    >
                                        <template v-for="attachment in message.attachments" :key="attachment.id">
                                            <button
                                                v-if="isVideoAttachment(attachment)"
                                                type="button"
                                                class="group relative h-24 w-24 shrink-0 overflow-hidden rounded-2xl bg-slate-900"
                                                :aria-label="`Play ${attachment.name}`"
                                                @click="previewSentAttachment(attachment)"
                                            >
                                                <video
                                                    :src="attachment.url"
                                                    class="h-full w-full object-cover"
                                                    muted
                                                    playsinline
                                                    preload="metadata"
                                                ></video>
                                                <span class="absolute inset-0 flex items-center justify-center bg-black/25 transition-all group-hover:bg-black/40">
                                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M8 5v14l11-7z" /></svg>
                                                </span>
                                            </button>
                                            <button
                                                v-else-if="isImageAttachment(attachment)"
                                                type="button"
                                                class="group relative h-24 w-24 shrink-0 overflow-hidden rounded-2xl"
                                                :aria-label="`View ${attachment.name} full size`"
                                                @click="previewSentAttachment(attachment)"
                                            >
                                                <img
                                                    :src="attachment.url"
                                                    :alt="attachment.name"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                >
                                                <span class="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition-all group-hover:bg-black/25 group-hover:opacity-100">
                                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                                                    </svg>
                                                </span>
                                            </button>
                                            <a
                                                v-else
                                                :href="attachment.url"
                                                :download="attachment.name"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="flex shrink-0 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                                            >
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
                                                    <path d="M14 2v6h6" />
                                                </svg>
                                                <span class="min-w-0">
                                                    <span class="block truncate">{{ attachment.name }}</span>
                                                    <span class="block text-[10px] font-normal text-slate-400">{{ formatFileSize(attachment.size) }}</span>
                                                </span>
                                            </a>
                                        </template>
                                    </div>

                                    <div
                                        v-if="message.text"
                                        class="px-4 py-2.5 text-[13px] leading-relaxed"
                                        :class="message.from === 'buyer'
                                            ? 'bg-[#0d9488] text-white rounded-2xl rounded-br-sm font-medium'
                                            : 'bg-white border border-slate-100 text-slate-700 rounded-2xl rounded-bl-sm'"
                                    >
                                        <p class="whitespace-pre-wrap break-words">{{ message.text }}</p>
                                    </div>
                                </div>
                            </div>
                            <!-- Timestamp is a sibling of the row above (not nested inside
                                 it) so the avatar's self-end alignment lines up with the
                                 bubble itself, not with the bubble+timestamp combined. -->
                            <div
                                class="flex items-center gap-1 text-[9px] text-slate-400"
                                :class="message.from === 'buyer' ? 'justify-end ml-auto pr-1' : 'ml-9 pl-1'"
                            >
                                <span>{{ message.at }}</span>
                                <svg
                                    v-if="message.from === 'buyer'"
                                    viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-[#0d9488]"
                                >
                                    <path d="M18 6 7 17l-5-5" /><path d="m22 10-7.5 7.5L13 16" />
                                </svg>
                            </div>
                            </template>
                            </template>

                            <!-- Skeleton while the thread's first page of messages is loading
                                 (not shown on a revisit — see messagesLoaded in useBuyerChat.js) -->
                            <div v-if="activeConversation.isLoadingMessages" class="space-y-3" aria-hidden="true">
                                <div v-for="(width, n) in ['60%', '40%', '70%']" :key="n" class="flex" :class="n === 1 ? 'justify-end' : 'justify-start'">
                                    <span class="h-9 animate-pulse rounded-2xl bg-slate-200" :style="{ width }"></span>
                                </div>
                            </div>

                            <p
                                v-else-if="activeConversation.messages.length === 0"
                                class="text-center text-[11px] text-slate-400 py-8"
                            >
                                No messages yet — say hello 👋
                            </p>
                        </div>

                        <!-- Composer disabled for a genuinely non-writable conversation
                             (resolved — see Conversation::isWritable() on the backend,
                             which rejects a send here with a 422 regardless). Archived
                             is NOT part of this: archiving is per-user inbox organising
                             (Conversation::archiveFor()) and never blocks sending, so
                             `status` here is never 'archived'. -->
                        <div
                            v-if="activeConversation.status !== 'open'"
                            class="shrink-0 px-4 py-3 border-t border-slate-100 bg-slate-50 text-center text-[12px] text-slate-500"
                        >
                            This conversation is {{ activeConversation.status }}.
                            <button
                                type="button"
                                class="font-semibold text-[#0d9488] hover:underline disabled:opacity-50"
                                :disabled="isUpdatingStatus"
                                @click="reopenConversation(activeConversationId)"
                            >Reopen it</button>
                            to keep replying.
                        </div>

                        <!-- Input -->
                        <form
                            v-else
                            class="shrink-0 p-4 border-t border-slate-100 flex flex-col gap-2"
                            @submit.prevent="handleSend"
                        >
                            <div v-if="stagedAttachments.length" class="flex flex-wrap gap-2">
                                <div
                                    v-for="attachment in stagedAttachments"
                                    :key="attachment.localId"
                                    class="relative flex max-w-52 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2 pr-7 text-xs"
                                >
                                    <button
                                        v-if="attachment.previewUrl"
                                        type="button"
                                        class="group relative h-9 w-9 shrink-0"
                                        :aria-label="`Preview ${attachment.name}`"
                                        @click="previewStagedAttachment(attachment)"
                                    >
                                        <video
                                            v-if="attachment.isVideo"
                                            :src="attachment.previewUrl"
                                            class="h-9 w-9 rounded-lg object-cover bg-slate-900"
                                            muted
                                            playsinline
                                            preload="metadata"
                                        ></video>
                                        <img
                                            v-else
                                            :src="attachment.previewUrl"
                                            :alt="attachment.name"
                                            class="h-9 w-9 rounded-lg object-cover"
                                        >
                                        <span class="absolute inset-0 flex items-center justify-center rounded-lg bg-black/0 opacity-0 transition-all group-hover:bg-black/40 group-hover:opacity-100">
                                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                                            </svg>
                                        </span>
                                        <span
                                            v-if="attachment.uploading"
                                            class="absolute inset-0 flex items-center justify-center rounded-lg bg-slate-900/40"
                                            aria-hidden="true"
                                        >
                                            <span class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                        </span>
                                    </button>
                                    <span v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-slate-500">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
                                            <path d="M14 2v6h6" />
                                        </svg>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-700">{{ attachment.name }}</span>
                                        <span class="block text-[10px]" :class="attachment.error ? 'text-red-500' : 'text-slate-400'">
                                            {{ attachment.error || (attachment.uploading ? 'Uploading...' : 'Ready') }}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        class="absolute right-1 top-1 text-slate-400 hover:text-red-500"
                                        :aria-label="`Remove ${attachment.name}`"
                                        @click="removeStagedAttachment(attachment.localId)"
                                    >
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <p v-if="attachmentError" class="text-xs text-red-600">{{ attachmentError }}</p>

                            <div class="flex w-full items-center gap-2">
                            <button
                                type="button"
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-slate-200 text-slate-500 transition-colors hover:border-[#0d9488] hover:text-[#0d9488] disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="stagedAttachments.length >= 5"
                                aria-label="Attach image, video, or PDF"
                                @click="fileInput?.click()"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                                </svg>
                            </button>
                            <input
                                ref="fileInput"
                                type="file"
                                class="hidden"
                                accept="image/png,image/jpeg,image/webp,application/pdf,video/mp4,video/webm,video/quicktime"
                                multiple
                                @change="onFilePicked"
                            >
                            <input
                                ref="messageInput"
                                v-model="draft"
                                type="text"
                                placeholder="Write your message…"
                                class="flex-1 bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                            >
                            <button
                                type="submit"
                                class="w-11 h-11 shrink-0 flex items-center justify-center rounded-2xl bg-[#0d9488] text-white hover:bg-[#0f766e] transition-all active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="!canSend"
                                aria-label="Send message"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z" />
                                    <path d="m21.854 2.147-10.94 10.939" />
                                </svg>
                            </button>
                            </div>
                        </form>
                    </template>

                    <div
                        v-else
                        class="flex-1 flex items-center justify-center p-8 text-center text-sm text-slate-400"
                    >
                        Select a conversation to start messaging.
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Full-size media preview (staged or already-sent attachments) -->
        <Transition name="chat-fade">
            <div
                v-if="previewMedia"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/85 p-4"
                role="dialog"
                aria-modal="true"
                aria-label="Attachment preview"
                @click.self="previewMedia = null"
            >
                <button
                    type="button"
                    class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors"
                    aria-label="Close preview"
                    @click="previewMedia = null"
                >
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                    </svg>
                </button>
                <div class="w-full max-w-2xl max-h-[85vh]">
                    <AttachmentVideoPlayer
                        v-if="previewMedia.isVideo"
                        :src="previewMedia.url"
                        :name="previewMedia.name"
                    />
                    <img
                        v-else
                        :src="previewMedia.url"
                        :alt="previewMedia.name"
                        class="max-h-[85vh] w-full rounded-xl object-contain"
                    >
                </div>
            </div>
        </Transition>

        <!-- Delete conversation confirmation -->
        <Transition name="chat-fade">
            <div
                v-if="pendingDeleteId"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-label="Delete conversation"
                @click.self="pendingDeleteId = null"
            >
                <div class="w-full max-w-sm rounded-3xl bg-white p-6 shadow-xl">
                    <h3 class="text-base font-bold text-slate-900">Delete this conversation?</h3>
                    <p class="mt-1.5 text-[13px] text-slate-500">
                        This removes it from your messages. The seller will still see their side, and it'll come back if either of you sends a new message.
                    </p>
                    <p v-if="deleteError" class="mt-2 text-xs text-red-600">{{ deleteError }}</p>
                    <div class="mt-5 flex gap-2.5">
                        <button
                            type="button"
                            class="flex-1 rounded-2xl border border-slate-200 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                            @click="pendingDeleteId = null"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-2xl bg-red-500 py-2.5 text-sm font-semibold text-white hover:bg-red-600 transition-colors disabled:opacity-50"
                            :disabled="isDeletingConversation"
                            @click="runDeleteConversation"
                        >
                            {{ isDeletingConversation ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Archive / unarchive confirmation -->
        <Transition name="chat-fade">
            <div
                v-if="pendingStatusAction"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/40 p-4"
                role="dialog"
                aria-modal="true"
                :aria-label="pendingStatusAction.status === 'archived' ? 'Archive conversation' : 'Unarchive conversation'"
                @click.self="pendingStatusAction = null"
            >
                <div class="w-full max-w-sm rounded-3xl bg-white p-6 shadow-xl">
                    <h3 class="text-base font-bold text-slate-900">
                        {{ pendingStatusAction.status === 'archived' ? 'Archive this conversation?' : 'Unarchive this conversation?' }}
                    </h3>
                    <p class="mt-1.5 text-[13px] text-slate-500">
                        {{ pendingStatusAction.status === 'archived'
                            ? "It moves out of your inbox into Archived — the seller isn't affected and can still message you."
                            : 'It moves back into your main inbox.' }}
                    </p>
                    <p v-if="statusActionError" class="mt-2 text-xs text-red-600">{{ statusActionError }}</p>
                    <div class="mt-5 flex gap-2.5">
                        <button
                            type="button"
                            class="flex-1 rounded-2xl border border-slate-200 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                            @click="pendingStatusAction = null"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-2xl bg-[#0d9488] py-2.5 text-sm font-semibold text-white hover:bg-[#0f766e] transition-colors disabled:opacity-50"
                            :disabled="isUpdatingStatus"
                            @click="runStatusAction"
                        >
                            <span v-if="isUpdatingStatus" class="inline-flex items-center gap-1.5">
                                <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                {{ pendingStatusAction.status === 'archived' ? 'Archiving…' : 'Unarchiving…' }}
                            </span>
                            <span v-else>{{ pendingStatusAction.status === 'archived' ? 'Archive' : 'Unarchive' }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.chat-fade-enter-active,
.chat-fade-leave-active {
    transition: opacity 0.25s ease;
}

.chat-fade-enter-from,
.chat-fade-leave-to {
    opacity: 0;
}

.chat-pop-enter-active {
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s ease;
}

.chat-pop-leave-active {
    transition: transform 0.2s ease, opacity 0.2s ease;
}

.chat-pop-enter-from,
.chat-pop-leave-to {
    opacity: 0;
    transform: translateY(24px) scale(0.98);
}

@media (min-width: 640px) {
    .chat-pop-enter-from,
    .chat-pop-leave-to {
        transform: translateX(40px) scale(0.98);
    }
}
</style>
