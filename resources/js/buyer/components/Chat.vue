<script setup>
/*
|--------------------------------------------------------------------------
| Chat.vue — buyer <-> seller messaging popup
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Modern Multi-Pane
| Chat Popup") onto NEXMART's stack the same way as the rest of the buyer
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

import { useBuyerChat } from '../composables/useBuyerChat';

const {
    isChatOpen,
    conversations,
    activeConversationId,
    activeConversation,
    closeChat,
    openConversation,
    sendMessage,
    validateAttachment,
    uploadAttachment,
} = useBuyerChat();

const search = ref('');
const draft = ref('');
const stagedAttachments = ref([]);
const attachmentError = ref('');

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
    const lastMessage = convo.messages[convo.messages.length - 1];

    if (lastMessage?.text) {
        return lastMessage.text;
    }

    return lastMessage?.attachments?.length ? 'Sent an attachment' : 'No messages yet';
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

function selectConversation(id) {
    openConversation(id);
    mobileView.value = 'thread';
    scrollThreadToBottom();
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
        const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;

        stagedAttachments.value.push({
            localId,
            name: file.name,
            previewUrl,
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
    if (event.key === 'Escape') {
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

watch(activeConversationId, scrollThreadToBottom);

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
                            <h2 class="text-lg font-bold text-slate-900">Messages</h2>
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
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
                                </svg>
                            </span>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search sellers…"
                                class="w-full pl-9 pr-3 py-2.5 bg-white border border-slate-200 rounded-xl text-xs focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                            >
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto px-2 pb-4 space-y-1">
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
                                <div class="relative shrink-0">
                                    <span class="w-11 h-11 rounded-xl bg-[#0d9488]/10 text-[#0d9488] text-sm font-bold flex items-center justify-center">
                                        {{ initials(convo.seller) }}
                                    </span>
                                    <span
                                        class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white"
                                        :class="convo.online ? 'bg-emerald-500' : 'bg-slate-300'"
                                    ></span>
                                </div>
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
                            v-if="filteredConversations.length === 0"
                            class="px-3 py-6 text-center text-[11px] text-slate-400"
                        >
                            No sellers match "{{ search }}".
                        </p>
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
                                <div class="relative shrink-0">
                                    <span class="w-10 h-10 rounded-2xl bg-[#0d9488]/10 text-[#0d9488] text-sm font-bold flex items-center justify-center">
                                        {{ initials(activeConversation.seller) }}
                                    </span>
                                    <span
                                        class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white"
                                        :class="activeConversation.online ? 'bg-emerald-500' : 'bg-slate-300'"
                                    ></span>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-bold text-slate-900 leading-tight text-[15px] truncate">{{ activeConversation.seller }}</h3>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span
                                            class="text-[10px] font-bold uppercase tracking-wider"
                                            :class="activeConversation.online ? 'text-emerald-500' : 'text-slate-400'"
                                        >{{ activeConversation.online ? 'Online now' : 'Offline' }}</span>
                                        <span class="text-[10px] text-slate-300">•</span>
                                        <span class="text-[10px] text-slate-400 font-medium">Seller since {{ activeConversation.memberSince }}</span>
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-red-500 transition-colors"
                                aria-label="Close messages"
                                @click="closeChat"
                            >
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Thread body -->
                        <div
                            ref="threadBody"
                            class="flex-1 overflow-y-auto p-5 space-y-5 bg-slate-50/30"
                        >
                            <div class="flex justify-center">
                                <span class="px-3 py-1 bg-slate-100 rounded-full text-[9px] font-bold text-slate-400 uppercase tracking-widest">Conversation</span>
                            </div>

                            <!-- Product context -->
                            <div
                                v-if="activeConversation.product"
                                class="bg-white border border-slate-200 border-l-4 border-l-[#0d9488] rounded-2xl p-4 flex items-center gap-4"
                            >
                                <span class="w-12 h-12 rounded-xl bg-[#0d9488]/10 text-[#0d9488] flex items-center justify-center shrink-0">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" /><path d="M3 6h18" /><path d="M16 10a4 4 0 0 1-8 0" />
                                    </svg>
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Regarding</p>
                                    <h4 class="text-xs font-bold text-slate-900 truncate">{{ activeConversation.product.name }}</h4>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-[11px] font-bold text-[#0d9488]">{{ formatPrice(activeConversation.product.price) }}</span>
                                        <span
                                            v-if="activeConversation.product.oldPrice"
                                            class="text-[9px] text-slate-400 font-medium line-through"
                                        >{{ formatPrice(activeConversation.product.oldPrice) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Messages -->
                            <div
                                v-for="message in activeConversation.messages"
                                :key="message.id"
                                class="flex gap-2.5 max-w-[85%]"
                                :class="message.from === 'buyer' ? 'flex-row-reverse ml-auto' : ''"
                            >
                                <span
                                    v-if="message.from === 'seller'"
                                    class="w-7 h-7 rounded-lg bg-[#0d9488]/10 text-[#0d9488] text-[10px] font-bold flex items-center justify-center self-end shrink-0"
                                >{{ initials(activeConversation.seller) }}</span>
                                <div class="space-y-1 min-w-0">
                                    <div
                                        class="px-4 py-2.5 text-[13px] leading-relaxed"
                                        :class="message.from === 'buyer'
                                            ? 'bg-[#0d9488] text-white rounded-2xl rounded-br-sm font-medium'
                                            : 'bg-white border border-slate-100 text-slate-700 rounded-2xl rounded-bl-sm'"
                                    >
                                        <p v-if="message.text" class="whitespace-pre-wrap break-words">
                                            {{ message.text }}
                                        </p>
                                        <div
                                            v-if="message.attachments?.length"
                                            class="mt-2 grid gap-2"
                                        >
                                            <a
                                                v-for="attachment in message.attachments"
                                                :key="attachment.id"
                                                :href="attachment.url"
                                                :download="isImageAttachment(attachment) ? null : attachment.name"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="block max-w-64 overflow-hidden rounded-xl border border-black/10 bg-white/95 text-slate-700"
                                            >
                                                <img
                                                    v-if="isImageAttachment(attachment)"
                                                    :src="attachment.url"
                                                    :alt="attachment.name"
                                                    class="max-h-48 w-full object-contain"
                                                    loading="lazy"
                                                >
                                                <span
                                                    v-else
                                                    class="flex items-center gap-2 px-3 py-2 text-xs font-semibold"
                                                >
                                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
                                                        <path d="M14 2v6h6" />
                                                    </svg>
                                                    <span class="min-w-0">
                                                        <span class="block truncate">{{ attachment.name }}</span>
                                                        <span class="block text-[10px] font-normal text-slate-400">{{ formatFileSize(attachment.size) }}</span>
                                                    </span>
                                                </span>
                                            </a>
                                        </div>
                                    </div>
                                    <div
                                        class="flex items-center gap-1 text-[9px] text-slate-400"
                                        :class="message.from === 'buyer' ? 'justify-end pr-1' : 'pl-1'"
                                    >
                                        <span>{{ message.at }}</span>
                                        <svg
                                            v-if="message.from === 'buyer'"
                                            viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-[#0d9488]"
                                        >
                                            <path d="M18 6 7 17l-5-5" /><path d="m22 10-7.5 7.5L13 16" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="activeConversation.messages.length === 0"
                                class="text-center text-[11px] text-slate-400 py-8"
                            >
                                No messages yet — say hello 👋
                            </p>
                        </div>

                        <!-- Input -->
                        <form
                            class="shrink-0 p-4 border-t border-slate-100 flex flex-col gap-2"
                            @submit.prevent="handleSend"
                        >
                            <div v-if="stagedAttachments.length" class="flex flex-wrap gap-2">
                                <div
                                    v-for="attachment in stagedAttachments"
                                    :key="attachment.localId"
                                    class="relative flex max-w-52 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2 pr-7 text-xs"
                                >
                                    <img
                                        v-if="attachment.previewUrl"
                                        :src="attachment.previewUrl"
                                        :alt="attachment.name"
                                        class="h-9 w-9 shrink-0 rounded-lg object-cover"
                                    >
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
                                aria-label="Attach image or PDF"
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
                                accept="image/png,image/jpeg,image/webp,application/pdf"
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
