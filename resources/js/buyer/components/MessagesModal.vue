<script setup>
/*
|--------------------------------------------------------------------------
| MessagesModal — buyer <-> seller conversations, over the current page
|--------------------------------------------------------------------------
|
| Mounted once in Dashboard.vue; opened by the header's Messages button
| (openChat) and every Message seller button (messageSeller), so buyers
| stay on the product, store or order page they were on.
|
| Desktop: a two-column dialog (inbox + search | conversation). Below
| 900px it is full screen: the inbox first, a conversation as its own
| screen with a back button. Closing keeps everything — the selected
| conversation, drafts, chosen photos, sends in flight, and where each
| list was scrolled — because that state lives in useBuyerChat and the
| scroll positions are remembered here.
|
| Only real data is shown: store, last message, unread counts, product and
| order references, "Sent" / "Read" from the server. No presence, typing
| or response-time claims, because nothing records them.
|
| Message text is rendered as text (links detected and rendered as
| anchors, never as HTML).
|
*/
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import StoreLogo from './StoreLogo.vue';
import { IMAGE_RULES, draftFor, useBuyerChat } from '../composables/useBuyerChat';
import { useBuyerSession } from '../composables/useBuyerSession';
import { requestBuyerView } from '../composables/useBuyerNav';
import { formatPrice } from '../composables/useCategoryMeta';
import { fetchJson } from '../composables/useStores';
import { useToasts } from '../composables/useToasts';

const {
    conversations, inboxLoaded, inboxLoading, inboxError, activeId, threads,
    chatOpen, chatRequest, composeTarget, askingAbout,
    THREAD_MS, INBOX_MS,
    loadInbox, openThread, closeThread, loadOlder, pollThread, markRead, refreshReceipts,
    send, retry, restoreToDraft, setStatus, closeChat, clearAskingAbout
} = useBuyerChat();
const { buyerProfile, isLoadingSession } = useBuyerSession();
const toasts = useToasts();

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const compactQuery = window.matchMedia('(max-width: 899px)');
const isCompact = ref(compactQuery.matches);
const coarsePointer = window.matchMedia('(pointer: coarse)').matches;

function onCompactChange(event) {
    isCompact.value = event.matches;
}

const dialog = ref(null);
const closeButton = ref(null);
const searchInput = ref(null);

/*
|--------------------------------------------------------------------------
| Inbox
|--------------------------------------------------------------------------
*/

const query = ref('');
const inboxList = ref(null);
let inboxScroll = 0;

const filtered = computed(() => {
    const words = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean);

    if (!words.length) {
        return conversations.value;
    }

    return conversations.value.filter(c => words.every(word => (c.seller || '').toLowerCase().includes(word)));
});

function timeLabel(iso) {
    const date = iso ? new Date(iso) : null;

    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }

    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const day = 86400000;

    if (date >= startOfToday) {
        return date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    }

    if (date >= new Date(startOfToday.getTime() - day)) {
        return 'Yesterday';
    }

    if (date >= new Date(startOfToday.getTime() - 6 * day)) {
        return date.toLocaleDateString('en-PH', { weekday: 'short' });
    }

    return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
}

function rememberInboxScroll() {
    if (inboxList.value) {
        inboxScroll = inboxList.value.scrollTop;
    }
}

function restoreInboxScroll() {
    if (inboxList.value) {
        inboxList.value.scrollTop = inboxScroll;
    }
}

function select(id) {
    if (isCompact.value) {
        rememberInboxScroll();
    }

    showConversation(id);
}

async function backToInbox() {
    rememberHistoryScroll();
    composeTarget.value = null;
    closeThread();
    newCount.value = 0;
    await nextTick();
    restoreInboxScroll();
    dialog.value?.focus({ preventScroll: true });
}

/*
|--------------------------------------------------------------------------
| Open conversation (or the new-conversation screen)
|--------------------------------------------------------------------------
*/

const isNew = computed(() => !activeId.value && Boolean(composeTarget.value));
const currentKey = computed(() => activeId.value || composeTarget.value?.key || null);

const active = computed(() => {
    if (activeId.value) {
        return conversations.value.find(c => c.id === activeId.value) || null;
    }

    const target = composeTarget.value;

    return target
        ? { id: target.key, seller: target.seller || 'Seller', sellerId: target.sellerId, sellerLogo: target.sellerLogo || null, sellerCategory: target.sellerCategory || null, status: 'open' }
        : null;
});

const t = computed(() => (currentKey.value ? threads[currentKey.value] : null));
const draft = computed(() => (currentKey.value ? draftFor(currentKey.value) : null));

// The product the buyer came from, when this thread is about another one.
const asking = computed(() => {
    const product = activeId.value ? askingAbout[activeId.value] : null;

    return product && t.value?.loaded && product.id !== t.value.product?.id ? product : null;
});

const history = ref(null);
const nearBottom = ref(true);
const newCount = ref(0);
const announcement = ref('');

/** key -> { top, atBottom }: each thread reopens where it was left. */
const historyScroll = new Map();

function rememberHistoryScroll() {
    if (history.value && currentKey.value) {
        historyScroll.set(currentKey.value, { top: history.value.scrollTop, atBottom: nearBottom.value });
    }
}

function restoreHistoryScroll(key) {
    const saved = historyScroll.get(key);

    if (saved && !saved.atBottom && history.value) {
        history.value.scrollTop = saved.top;
        nearBottom.value = false;

        return;
    }

    scrollToBottom();
    nearBottom.value = true;
}

function scrollToBottom(smooth = false) {
    const el = history.value;

    if (el) {
        el.scrollTo({ top: el.scrollHeight, behavior: smooth && !prefersReducedMotion ? 'smooth' : 'auto' });
    }
}

function onHistoryScroll() {
    const el = history.value;

    if (!el) {
        return;
    }

    nearBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 120;

    if (nearBottom.value && newCount.value) {
        newCount.value = 0;
        markRead(activeId.value);
    }

    if (el.scrollTop < 80) {
        loadEarlier();
    }
}

async function loadEarlier() {
    const el = history.value;
    const id = activeId.value;

    if (!el || !id || !t.value?.hasMore || t.value.loadingOlder) {
        return;
    }

    const before = el.scrollHeight;
    const top = el.scrollTop;

    try {
        await loadOlder(id);
    } catch (err) {
        toasts.error(err?.message || 'Could not load earlier messages.');

        return;
    }

    await nextTick();

    // Keep the message the buyer was reading exactly where it was.
    if (history.value && activeId.value === id) {
        history.value.scrollTop = history.value.scrollHeight - before + top;
    }
}

function jumpToNew() {
    newCount.value = 0;
    scrollToBottom(true);
    markRead(activeId.value);
}

function focusComposer() {
    // On phones, focusing the box would pop the keyboard over the thread;
    // keep focus in the dialog instead (the tapped row has just gone).
    nextTick(() => {
        if (!coarsePointer) {
            composer.value?.focus({ preventScroll: true });
        } else if (!dialog.value?.contains(document.activeElement)) {
            dialog.value?.focus({ preventScroll: true });
        }
    });
}

async function showConversation(id) {
    if (id !== activeId.value || composeTarget.value) {
        rememberHistoryScroll();
        newCount.value = 0;
    }

    composeTarget.value = null;
    menuOpen.value = false;

    const opening = openThread(id);

    await nextTick();
    await opening;
    await nextTick();

    if (activeId.value === id) {
        restoreHistoryScroll(id);
        focusComposer();
    }
}

async function showCompose() {
    rememberHistoryScroll();
    newCount.value = 0;
    menuOpen.value = false;
    await nextTick();
    restoreHistoryScroll(composeTarget.value?.key);
    focusComposer();
}

/*
|--------------------------------------------------------------------------
| Rendering helpers
|--------------------------------------------------------------------------
*/

const URL_RE = /(https?:\/\/[^\s<>"']+)/g;

function segments(text) {
    return String(text || '').split(URL_RE).filter(Boolean).map(part => ({
        link: /^https?:\/\//.test(part),
        text: part
    }));
}

function dayKey(iso) {
    const d = new Date(iso);

    return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);

    if (dayKey(iso) === dayKey(today.toISOString())) {
        return 'Today';
    }

    if (dayKey(iso) === dayKey(yesterday.toISOString())) {
        return 'Yesterday';
    }

    return d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
}

function clock(iso) {
    const d = new Date(iso);

    return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
}

/** Server messages plus pending ones, with day separators. */
const rows = computed(() => {
    if (!t.value) {
        return [];
    }

    const out = [];
    let lastDay = null;
    const all = [
        ...t.value.messages.map(m => ({ kind: 'message', key: m.id, from: m.from, text: m.text, at: m.at, attachments: m.attachments || [], status: m.status })),
        ...t.value.pending.map(p => ({ kind: 'pending', key: p.localId, from: 'buyer', text: p.text, at: p.at, files: p.files, pending: p }))
    ];

    all.forEach((row, index) => {
        const key = dayKey(row.at);

        if (key !== lastDay) {
            out.push({ kind: 'day', key: `day-${key}`, label: dayLabel(row.at) });
            lastDay = key;
        }

        const next = all[index + 1];

        row.groupEnd = !next || next.from !== row.from || dayKey(next.at) !== key;
        out.push(row);
    });

    return out;
});

// The buyer's newest server message carries the Sent / Read line.
const lastOwnId = computed(() => {
    const own = t.value?.messages.filter(m => m.from === 'buyer') || [];

    return own.length ? own[own.length - 1].id : null;
});

const failedImages = ref(new Set());

function imageFailed(key) {
    failedImages.value = new Set(failedImages.value).add(key);
}

/*
|--------------------------------------------------------------------------
| Photo viewer (inside the dialog, so the focus trap covers it)
|--------------------------------------------------------------------------
*/

const viewer = ref(null);
const viewerEl = ref(null);
const viewerClose = ref(null);
let viewerReturn = null;

function openViewer(src, name, event) {
    viewerReturn = event?.currentTarget || null;
    viewer.value = { src, name };
    nextTick(() => viewerClose.value?.focus());
}

function closeViewer() {
    viewer.value = null;
    nextTick(() => viewerReturn?.focus());
}

/*
|--------------------------------------------------------------------------
| Composer
|--------------------------------------------------------------------------
*/

const composer = ref(null);
const fileInput = ref(null);

function autosize() {
    const el = composer.value;

    if (el) {
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
    }
}

watch(() => draft.value?.text, () => nextTick(autosize));

const startingNew = computed(() => isNew.value && Boolean(t.value?.pending.some(p => p.status === 'sending')));

const canSend = computed(() => Boolean(draft.value && !startingNew.value && (draft.value.text.trim() || draft.value.files.some(f => !f.error))));

function onKeydown(event) {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) {
        return;
    }

    // Phones keep Enter for new lines; the Send button sends.
    if (coarsePointer) {
        return;
    }

    event.preventDefault();
    submit();
}

function submit() {
    if (!currentKey.value || !canSend.value) {
        return;
    }

    send(currentKey.value);
    nextTick(() => {
        autosize();
        scrollToBottom(true);
    });

    // The Send button disables itself; keep typing where you were.
    focusComposer();
}

function onFilesChosen(event) {
    const chosen = [...(event.target.files || [])];

    event.target.value = '';

    if (!draft.value) {
        return;
    }

    for (const file of chosen) {
        if (draft.value.files.length >= IMAGE_RULES.maxCount) {
            toasts.error(`You can send up to ${IMAGE_RULES.maxCount} photos at a time.`);
            break;
        }

        let error = '';

        if (!IMAGE_RULES.mimes.includes(file.type)) {
            error = 'Use a JPEG, PNG or WebP photo.';
        } else if (file.size > IMAGE_RULES.maxBytes) {
            error = 'This photo is over 5 MB.';
        }

        draft.value.files.push({ key: `${file.name}-${file.size}-${Math.random()}`, file, url: error ? '' : URL.createObjectURL(file), error });
    }
}

function removeFile(key) {
    const item = draft.value.files.find(f => f.key === key);

    if (item?.url) {
        URL.revokeObjectURL(item.url);
    }

    draft.value.files = draft.value.files.filter(f => f.key !== key);
}

/*
|--------------------------------------------------------------------------
| Suggestions and references
|--------------------------------------------------------------------------
*/

const suggestions = computed(() => {
    if (!t.value || draft.value?.text) {
        return [];
    }

    if (t.value.order) {
        return ['When will my order be shipped?', 'I received the wrong item. What should I do?'];
    }

    return ['Is this item available?', 'Do you have another size or color?', 'Can you send an actual photo?'];
});

function useSuggestion(text) {
    if (!draft.value) {
        return;
    }

    draft.value.text = text;
    nextTick(() => {
        composer.value?.focus();
        composer.value?.setSelectionRange(text.length, text.length);
    });
}

/** Leaves the modal for another buyer page (the modal keeps its state). */
function goTo(view, payload = null) {
    closeChat();
    requestBuyerView(view, payload);
}

const openingProduct = ref(false);

async function viewProduct() {
    const product = t.value?.product;

    if (!product || openingProduct.value) {
        return;
    }

    openingProduct.value = true;

    try {
        const body = await fetchJson(`/api/products/${encodeURIComponent(product.id)}`);

        goTo('product', body.data || body);
    } catch (err) {
        toasts.error(err?.status === 404 ? 'That product is no longer available.' : 'Could not open the product.');
    } finally {
        openingProduct.value = false;
    }
}

function viewOrders() {
    goTo('orders');
}

function visitStore() {
    if (active.value) {
        goTo('store', { id: active.value.sellerId, name: active.value.seller, category: active.value.sellerCategory, logo: active.value.sellerLogo });
    }
}

/*
|--------------------------------------------------------------------------
| Conversation menu
|--------------------------------------------------------------------------
*/

const menuOpen = ref(false);
const menuButton = ref(null);
const menu = ref(null);

function toggleMenu() {
    menuOpen.value = !menuOpen.value;

    if (menuOpen.value) {
        nextTick(() => menu.value?.querySelector('[role="menuitem"]')?.focus());
    }
}

function closeMenu(returnFocus = true) {
    menuOpen.value = false;

    if (returnFocus) {
        nextTick(() => menuButton.value?.focus());
    }
}

function onMenuKeydown(event) {
    const items = [...(menu.value?.querySelectorAll('[role="menuitem"]') || [])];
    const index = items.indexOf(document.activeElement);

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        items[(index + step + items.length) % items.length]?.focus();
    } else if (event.key === 'Tab') {
        closeMenu(false);
    }
}

async function toggleResolved() {
    closeMenu();

    const next = active.value?.status === 'resolved' ? 'open' : 'resolved';

    try {
        await setStatus(activeId.value, next);
        toasts.success(next === 'resolved' ? 'Marked as resolved.' : 'Conversation reopened.');
    } catch (err) {
        toasts.error(err?.message || 'Could not update this conversation.');
    }
}

function handleDocumentClick(event) {
    if (menuOpen.value && !menu.value?.contains(event.target) && !menuButton.value?.contains(event.target)) {
        closeMenu(false);
    }
}

/*
|--------------------------------------------------------------------------
| Polling, visibility and reconnects (only while open)
|--------------------------------------------------------------------------
*/

let threadTimer = null;
let inboxTimer = null;
let receiptTick = 0;

async function tickThread() {
    const id = activeId.value;

    if (!id || document.hidden || !chatOpen.value) {
        return;
    }

    const fresh = await pollThread(id);
    const incoming = fresh.filter(m => m.from === 'seller');

    if (incoming.length && activeId.value === id) {
        await nextTick();

        if (nearBottom.value) {
            scrollToBottom(true);
            markRead(id);
        } else {
            newCount.value += incoming.length;
        }

        announcement.value = `${incoming.length} new ${incoming.length === 1 ? 'message' : 'messages'} from ${active.value?.seller || 'the seller'}`;
    }

    // Read receipts change less often; check them every few ticks.
    receiptTick = (receiptTick + 1) % 4;

    if (receiptTick === 0) {
        refreshReceipts(id);
    }
}

function startTimers() {
    stopTimers();
    threadTimer = setInterval(tickThread, THREAD_MS);
    inboxTimer = setInterval(() => !document.hidden && loadInbox({ quiet: true }), INBOX_MS);
}

function stopTimers() {
    clearInterval(threadTimer);
    clearInterval(inboxTimer);
    threadTimer = null;
    inboxTimer = null;
}

function catchUp() {
    if (document.hidden || !chatOpen.value || !buyerProfile.value) {
        return;
    }

    loadInbox({ quiet: true });
    tickThread();
}

/*
|--------------------------------------------------------------------------
| Mobile keyboard: size the dialog to the visible viewport
|--------------------------------------------------------------------------
*/

const viewport = ref(null);

function onViewportResize() {
    const vv = window.visualViewport;

    viewport.value = isCompact.value && vv ? { height: vv.height, top: vv.offsetTop } : null;
}

const dialogStyle = computed(() => (viewport.value ? { height: `${viewport.value.height}px`, top: `${viewport.value.top}px` } : null));

/*
|--------------------------------------------------------------------------
| Modal behaviour: scroll lock, inert page, focus trap, Escape
|--------------------------------------------------------------------------
*/

const overlay = ref(null);
let lockedStyles = null;
let inertSiblings = [];

// The page keeps its scrollbar gutter (scrollbar-gutter: stable in
// layout.css), so locking doesn't shift it sideways.
function lockPage() {
    lockedStyles = { overflow: document.body.style.overflow };
    document.body.style.overflow = 'hidden';
}

/** Hides the page from assistive tech and the keyboard (once rendered). */
function makePageInert() {
    const host = overlay.value?.parentElement;

    inertSiblings = host ? [...host.children].filter(el => el !== overlay.value && !el.inert) : [];
    inertSiblings.forEach(el => {
        el.inert = true;
    });
}

function unlockPage() {
    if (lockedStyles) {
        document.body.style.overflow = lockedStyles.overflow;
        lockedStyles = null;
    }

    inertSiblings.forEach(el => {
        el.inert = false;
    });
    inertSiblings = [];
}

const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), [tabindex]:not([tabindex="-1"])';

function trapTab(event) {
    const root = viewer.value ? viewerEl.value : dialog.value;

    if (!root) {
        return;
    }

    const items = [...root.querySelectorAll(FOCUSABLE)].filter(el => el.getClientRects().length);

    if (!items.length) {
        event.preventDefault();

        return;
    }

    const first = items[0];
    const last = items[items.length - 1];

    if (!root.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
    } else if (event.shiftKey && (document.activeElement === first || document.activeElement === root)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

// On the document, so keys still work when focus has fallen to the page
// (a button that disabled itself, a click on the backdrop).
function onDocumentKeydown(event) {
    if (event.key === 'Tab') {
        trapTab(event);

        return;
    }

    if (event.key !== 'Escape' || event.isComposing) {
        return;
    }

    event.preventDefault();

    // Innermost first: the photo, then the menu, then the modal.
    if (viewer.value) {
        closeViewer();
    } else if (menuOpen.value) {
        closeMenu();
    } else {
        closeChat();
    }
}

/*
|--------------------------------------------------------------------------
| Opening, requests from pages, closing
|--------------------------------------------------------------------------
*/

let handledSeq = 0;
let ready = false;

async function waitForInbox() {
    if (!inboxLoaded.value && !inboxLoading.value) {
        await loadInbox();
    }

    while (inboxLoading.value && !inboxLoaded.value) {
        await new Promise(resolve => setTimeout(resolve, 50));
    }
}

function handleRequest(request) {
    if (!request || request.pending || request.seq === handledSeq) {
        return false;
    }

    handledSeq = request.seq;

    if (request.conversationId) {
        showConversation(request.conversationId);
    } else if (request.compose) {
        showCompose();
    }

    return true;
}

async function onOpen() {
    ready = false;
    lockPage();
    document.addEventListener('keydown', onDocumentKeydown);
    onViewportResize();
    await nextTick();
    makePageInert();
    dialog.value?.focus({ preventScroll: true });

    if (!buyerProfile.value) {
        return;
    }

    if (inboxLoaded.value) {
        loadInbox({ quiet: true });
    } else {
        await waitForInbox();
    }

    if (!chatOpen.value) {
        return;
    }

    ready = true;
    await nextTick();
    restoreInboxScroll();

    const request = chatRequest.value;

    if (request?.pending && request.seq !== handledSeq) {
        // A Message seller button is still finding its thread.
    } else if (!handleRequest(request)) {
        if (activeId.value) {
            showConversation(activeId.value);
        } else if (composeTarget.value) {
            showCompose();
        } else if (!isCompact.value && conversations.value.length) {
            // Desktop opens on the most recent conversation.
            showConversation(conversations.value[0].id);
        } else if (!coarsePointer) {
            searchInput.value?.focus({ preventScroll: true });
        }
    }

    startTimers();
}

function onClose() {
    ready = false;
    rememberHistoryScroll();
    rememberInboxScroll();
    stopTimers();
    menuOpen.value = false;
    viewer.value = null;
    document.removeEventListener('keydown', onDocumentKeydown);
    unlockPage();
}

watch(chatOpen, open => (open ? onOpen() : onClose()));

watch(chatRequest, (request) => {
    if (chatOpen.value && ready) {
        handleRequest(request);
    }
});

// Signing in while it's open.
watch(() => buyerProfile.value?.id, (id) => {
    if (id && chatOpen.value && !ready) {
        onOpen();
    }
});

onMounted(() => {
    compactQuery.addEventListener('change', onCompactChange);
    document.addEventListener('visibilitychange', catchUp);
    window.addEventListener('online', catchUp);
    document.addEventListener('click', handleDocumentClick);
    window.visualViewport?.addEventListener('resize', onViewportResize);
    window.visualViewport?.addEventListener('scroll', onViewportResize);

    if (chatOpen.value) {
        onOpen();
    }
});

onUnmounted(() => {
    stopTimers();
    document.removeEventListener('keydown', onDocumentKeydown);
    unlockPage();
    compactQuery.removeEventListener('change', onCompactChange);
    document.removeEventListener('visibilitychange', catchUp);
    window.removeEventListener('online', catchUp);
    document.removeEventListener('click', handleDocumentClick);
    window.visualViewport?.removeEventListener('resize', onViewportResize);
    window.visualViewport?.removeEventListener('scroll', onViewportResize);
});
</script>

<template>

    <Transition name="msg-modal">
        <div
            v-if="chatOpen"
            ref="overlay"
            class="msg-overlay"
            @mousedown.self="closeChat"
        >
            <div
                ref="dialog"
                class="msg-dialog"
                :class="{ 'has-thread': Boolean(active) }"
                :style="dialogStyle"
                role="dialog"
                aria-modal="true"
                aria-labelledby="msg-dialog-title"
                tabindex="-1"
            >
                <!-- Title bar -->
                <div class="msg-titlebar">
                    <h2
                        id="msg-dialog-title"
                        class="msg-dialog-title"
                    >
                        Messages
                    </h2>
                    <button
                        ref="closeButton"
                        type="button"
                        class="icon-btn msg-close"
                        aria-label="Close messages"
                        @click="closeChat"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                <!-- Signed out -->
                <section
                    v-if="!buyerProfile && !isLoadingSession"
                    class="msg-signin"
                    aria-labelledby="msg-signin-title"
                >
                    <h3
                        id="msg-signin-title"
                        class="msg-signin-title"
                    >
                        Sign in to message sellers
                    </h3>
                    <p>Your conversations with sellers are kept here once you&rsquo;re signed in.</p>
                    <a
                        href="/login"
                        class="btn btn-primary"
                    >Sign in</a>
                </section>

                <div
                    v-else
                    class="msg-layout"
                >
                    <!-- ===================== INBOX ===================== -->
                    <section
                        class="msg-inbox"
                        aria-label="Conversations"
                    >
                        <div class="msg-inbox-head">
                            <label
                                for="msg-search"
                                class="sr-only"
                            >Search conversations by store name</label>
                            <div class="msg-search">
                                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                                <input
                                    id="msg-search"
                                    ref="searchInput"
                                    v-model="query"
                                    type="search"
                                    placeholder="Search stores"
                                    autocomplete="off"
                                >
                            </div>
                        </div>

                        <div
                            ref="inboxList"
                            class="msg-inbox-list"
                        >
                            <ul
                                v-if="!inboxLoaded && (inboxLoading || isLoadingSession)"
                                class="msg-rows"
                                aria-hidden="true"
                            >
                                <li
                                    v-for="n in 5"
                                    :key="n"
                                    class="msg-row is-skeleton"
                                >
                                    <span class="skeleton msg-sk-logo"></span>
                                    <span class="msg-row-text">
                                        <span class="skeleton is-line"></span>
                                        <span class="skeleton is-line is-short"></span>
                                    </span>
                                </li>
                            </ul>

                            <div
                                v-else-if="inboxError && !conversations.length"
                                class="msg-state"
                                role="alert"
                            >
                                <p>{{ inboxError }}</p>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="loadInbox()"
                                >
                                    Try again
                                </button>
                            </div>

                            <div
                                v-else-if="!conversations.length"
                                class="msg-state"
                            >
                                <p class="msg-state-title">No conversations yet</p>
                                <p>Questions about a product or an order? Use Message seller on its page and the chat appears here.</p>
                            </div>

                            <div
                                v-else-if="!filtered.length"
                                class="msg-state"
                            >
                                <p class="msg-state-title">No stores match &ldquo;{{ query }}&rdquo;</p>
                                <button
                                    type="button"
                                    class="link-btn"
                                    @click="query = ''"
                                >
                                    Clear search
                                </button>
                            </div>

                            <ul
                                v-else
                                class="msg-rows"
                            >
                                <li
                                    v-for="c in filtered"
                                    :key="c.id"
                                >
                                    <button
                                        type="button"
                                        class="msg-row"
                                        :class="{ 'is-active': c.id === activeId, 'is-unread': c.unread > 0 }"
                                        :aria-current="c.id === activeId ? 'true' : undefined"
                                        @click="select(c.id)"
                                    >
                                        <StoreLogo
                                            size="sm"
                                            :name="c.seller"
                                            :src="c.sellerLogo || ''"
                                            :category="c.sellerCategory || ''"
                                        />
                                        <span class="msg-row-text">
                                            <span class="msg-row-top">
                                                <span class="msg-row-name">{{ c.seller }}</span>
                                                <time
                                                    class="msg-row-time"
                                                    :datetime="c.updatedAt"
                                                >{{ timeLabel(c.updatedAt) }}</time>
                                            </span>
                                            <span class="msg-row-bottom">
                                                <span class="msg-row-preview">
                                                    <span
                                                        v-if="c.lastMessageFromMe"
                                                        class="msg-row-you"
                                                    >You: </span>{{ c.lastMessagePreview || 'No messages yet' }}
                                                </span>
                                                <span
                                                    v-if="c.unread > 0"
                                                    class="msg-unread"
                                                >{{ c.unread > 99 ? '99+' : c.unread }}<span class="sr-only"> unread</span></span>
                                            </span>
                                            <span
                                                v-if="c.order || c.product || c.status === 'resolved'"
                                                class="msg-row-context"
                                            >
                                                <template v-if="c.order">Order #{{ c.order.number }}</template>
                                                <template v-else-if="c.product">{{ c.product.name }}</template>
                                                <template v-if="c.status === 'resolved'">{{ c.order || c.product ? ' · ' : '' }}Resolved</template>
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <p class="msg-inbox-foot">
                            Question about BuyTheWay itself?
                            <button
                                type="button"
                                class="link-btn"
                                @click="goTo('account', { section: 'help' })"
                            >
                                Help &amp; Support
                            </button>
                        </p>
                    </section>

                    <!-- ===================== THREAD ===================== -->
                    <section
                        class="msg-thread"
                        :aria-label="active ? `Conversation with ${active.seller}` : 'Conversation'"
                    >
                        <div
                            v-if="!active"
                            class="msg-thread-empty"
                        >
                            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12Z" /></svg>
                            <p>{{ chatRequest?.pending ? 'Opening the conversation…' : conversations.length ? 'Choose a conversation to read it.' : 'Your conversations with sellers will appear here.' }}</p>
                        </div>

                        <div
                            v-else
                            :key="active.id"
                            class="msg-thread-inner"
                        >
                            <!-- Header -->
                            <header class="msg-thread-head">
                                <button
                                    v-if="isCompact"
                                    type="button"
                                    class="icon-btn msg-back"
                                    aria-label="Back to conversations"
                                    @click="backToInbox"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                                </button>
                                <StoreLogo
                                    size="sm"
                                    :name="active.seller"
                                    :src="active.sellerLogo || ''"
                                    :category="active.sellerCategory || ''"
                                />
                                <div class="msg-thread-id">
                                    <button
                                        type="button"
                                        class="msg-thread-name"
                                        @click="visitStore"
                                    >
                                        {{ active.seller }}
                                    </button>
                                    <p class="msg-thread-sub">
                                        <span v-if="isNew">New conversation</span>
                                        <span v-else-if="active.sellerCategory">{{ active.sellerCategory }}</span>
                                        <span v-if="active.status === 'resolved'">Resolved</span>
                                    </p>
                                </div>
                                <div class="msg-menu-wrap">
                                    <button
                                        ref="menuButton"
                                        type="button"
                                        class="icon-btn"
                                        aria-label="Conversation options"
                                        aria-haspopup="menu"
                                        :aria-expanded="menuOpen"
                                        aria-controls="msg-menu"
                                        @click.stop="toggleMenu"
                                    >
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7" /><circle cx="12" cy="12" r="1.7" /><circle cx="19" cy="12" r="1.7" /></svg>
                                    </button>
                                    <Transition name="msg-pop">
                                        <div
                                            v-if="menuOpen"
                                            id="msg-menu"
                                            ref="menu"
                                            class="msg-menu"
                                            role="menu"
                                            @keydown="onMenuKeydown"
                                        >
                                            <button
                                                type="button"
                                                role="menuitem"
                                                @click="closeMenu(false); visitStore()"
                                            >
                                                Visit store
                                            </button>
                                            <button
                                                v-if="t?.order && !isNew"
                                                type="button"
                                                role="menuitem"
                                                @click="closeMenu(false); viewOrders()"
                                            >
                                                View order #{{ t.order.number }}
                                            </button>
                                            <button
                                                v-if="!isNew"
                                                type="button"
                                                role="menuitem"
                                                @click="toggleResolved"
                                            >
                                                {{ active.status === 'resolved' ? 'Reopen conversation' : 'Mark as resolved' }}
                                            </button>
                                        </div>
                                    </Transition>
                                </div>
                                <button
                                    v-if="isCompact"
                                    type="button"
                                    class="icon-btn msg-close"
                                    aria-label="Close messages"
                                    @click="closeChat"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                </button>
                            </header>

                            <!-- History -->
                            <div
                                ref="history"
                                class="msg-history"
                                @scroll.passive="onHistoryScroll"
                            >
                                <div
                                    v-if="t?.loading && !t.loaded"
                                    class="msg-loading"
                                    aria-busy="true"
                                >
                                    <span class="skeleton is-line msg-sk-in"></span>
                                    <span class="skeleton is-line msg-sk-out"></span>
                                    <span class="skeleton is-line msg-sk-in"></span>
                                </div>

                                <div
                                    v-else-if="t?.error"
                                    class="msg-state"
                                    role="alert"
                                >
                                    <p>{{ t.error }}</p>
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        @click="showConversation(activeId)"
                                    >
                                        Try again
                                    </button>
                                </div>

                                <template v-else-if="t">
                                    <div
                                        v-if="t.hasMore"
                                        class="msg-older"
                                    >
                                        <button
                                            type="button"
                                            class="link-btn"
                                            :disabled="t.loadingOlder"
                                            @click="loadEarlier"
                                        >
                                            {{ t.loadingOlder ? 'Loading earlier messages…' : 'Load earlier messages' }}
                                        </button>
                                    </div>

                                    <!-- What this conversation is about (start of the discussion) -->
                                    <div
                                        v-if="!t.hasMore && (t.product || t.order)"
                                        class="msg-refs"
                                    >
                                        <div
                                            v-if="t.order"
                                            class="msg-ref"
                                        >
                                            <p class="msg-ref-kicker">About order</p>
                                            <p class="msg-ref-title">
                                                #{{ t.order.number }}
                                                <span
                                                    v-if="t.order.status"
                                                    class="msg-ref-status"
                                                >{{ t.order.status }}</span>
                                            </p>
                                            <ul
                                                v-if="t.order.items?.length"
                                                class="msg-ref-items"
                                            >
                                                <li
                                                    v-for="(item, i) in t.order.items"
                                                    :key="i"
                                                >
                                                    {{ item.name }}<template v-if="item.variant"> · {{ item.variant }}</template>
                                                    <span class="msg-ref-paid">{{ item.quantity }} × {{ formatPrice(item.unitPrice) }} paid</span>
                                                </li>
                                            </ul>
                                            <button
                                                v-if="!isNew"
                                                type="button"
                                                class="link-btn"
                                                @click="viewOrders"
                                            >
                                                View order details
                                            </button>
                                        </div>
                                        <div
                                            v-if="t.product"
                                            class="msg-ref is-product"
                                        >
                                            <span class="msg-ref-img">
                                                <img
                                                    v-if="t.product.image"
                                                    :src="t.product.image"
                                                    alt=""
                                                    width="56"
                                                    height="56"
                                                    loading="lazy"
                                                >
                                            </span>
                                            <div>
                                                <p class="msg-ref-kicker">About product</p>
                                                <p class="msg-ref-title">{{ t.product.name }}</p>
                                                <p
                                                    v-if="t.product.price != null"
                                                    class="msg-ref-price"
                                                >
                                                    {{ formatPrice(t.product.price) }}
                                                    <s v-if="t.product.oldPrice">{{ formatPrice(t.product.oldPrice) }}</s>
                                                    <span class="msg-ref-note">current price</span>
                                                    <span
                                                        v-if="!t.product.available"
                                                        class="msg-ref-note is-warn"
                                                    >no longer listed</span>
                                                </p>
                                                <button
                                                    v-if="t.product.available && !isNew"
                                                    type="button"
                                                    class="link-btn"
                                                    :disabled="openingProduct"
                                                    @click="viewProduct"
                                                >
                                                    {{ openingProduct ? 'Opening…' : 'View product' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <p
                                        v-if="isNew && !t.pending.length"
                                        class="msg-start-note"
                                    >
                                        Start the conversation with {{ active.seller }}. Your first message creates it.
                                    </p>

                                    <ol class="msg-list">
                                        <template
                                            v-for="row in rows"
                                            :key="row.key"
                                        >
                                            <li
                                                v-if="row.kind === 'day'"
                                                class="msg-day"
                                            >
                                                <span>{{ row.label }}</span>
                                            </li>
                                            <li
                                                v-else
                                                class="msg-item"
                                                :class="[row.from === 'buyer' ? 'is-out' : 'is-in', { 'is-group-end': row.groupEnd, 'is-pending': row.kind === 'pending', 'is-failed': row.pending?.status === 'failed' }]"
                                            >
                                                <span class="sr-only">{{ row.from === 'buyer' ? 'You' : active.seller }}, {{ clock(row.at) }}:</span>
                                                <div class="msg-bubble">
                                                    <!-- Photos -->
                                                    <div
                                                        v-if="(row.attachments && row.attachments.length) || (row.files && row.files.length)"
                                                        class="msg-photos"
                                                        :class="{ 'is-multi': (row.attachments?.length || row.files?.length) > 1 }"
                                                    >
                                                        <template v-if="row.kind === 'message'">
                                                            <template
                                                                v-for="att in row.attachments"
                                                                :key="att.id || att.name"
                                                            >
                                                                <button
                                                                    v-if="att.url && att.mime?.startsWith('image/') && !failedImages.has(`${row.key}-${att.id}`)"
                                                                    type="button"
                                                                    class="msg-photo"
                                                                    :aria-label="`Open photo ${att.name}`"
                                                                    @click="openViewer(att.url, att.name, $event)"
                                                                >
                                                                    <img
                                                                        :src="att.url"
                                                                        :alt="att.name"
                                                                        width="200"
                                                                        height="150"
                                                                        loading="lazy"
                                                                        @error="imageFailed(`${row.key}-${att.id}`)"
                                                                    >
                                                                </button>
                                                                <a
                                                                    v-else-if="att.url && !att.mime?.startsWith('image/')"
                                                                    :href="att.url"
                                                                    class="msg-file"
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                >{{ att.name }}</a>
                                                                <span
                                                                    v-else
                                                                    class="msg-photo is-missing"
                                                                >Image unavailable</span>
                                                            </template>
                                                        </template>
                                                        <template v-else>
                                                            <span
                                                                v-for="item in row.files"
                                                                :key="item.key"
                                                                class="msg-photo"
                                                            >
                                                                <img
                                                                    :src="item.url"
                                                                    :alt="item.file.name"
                                                                    width="200"
                                                                    height="150"
                                                                >
                                                            </span>
                                                        </template>
                                                    </div>

                                                    <p
                                                        v-if="row.text"
                                                        class="msg-text"
                                                    >
                                                        <template
                                                            v-for="(part, i) in segments(row.text)"
                                                            :key="i"
                                                        >
                                                            <a
                                                                v-if="part.link"
                                                                :href="part.text"
                                                                target="_blank"
                                                                rel="noopener noreferrer nofollow"
                                                            >{{ part.text }}</a>
                                                            <template v-else>{{ part.text }}</template>
                                                        </template>
                                                    </p>
                                                </div>

                                                <p
                                                    v-if="row.kind === 'pending'"
                                                    class="msg-meta"
                                                    :role="row.pending.status === 'failed' ? 'alert' : undefined"
                                                >
                                                    <template v-if="row.pending.status === 'sending'">
                                                        <span class="msg-spinner" aria-hidden="true"></span>
                                                        {{ row.pending.progress !== null ? `Uploading ${row.pending.progress}%` : 'Sending…' }}
                                                    </template>
                                                    <template v-else>
                                                        <span class="msg-failed">{{ row.pending.error || 'Not sent' }}</span>
                                                        <button
                                                            type="button"
                                                            class="link-btn"
                                                            @click="retry(currentKey, row.key)"
                                                        >
                                                            Retry
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="link-btn"
                                                            @click="restoreToDraft(currentKey, row.key)"
                                                        >
                                                            Edit
                                                        </button>
                                                    </template>
                                                </p>
                                                <p
                                                    v-else-if="row.groupEnd || row.key === lastOwnId"
                                                    class="msg-meta"
                                                >
                                                    <time :datetime="row.at">{{ clock(row.at) }}</time>
                                                    <template v-if="row.key === lastOwnId">
                                                        · {{ row.status === 'read' ? 'Read' : 'Sent' }}
                                                    </template>
                                                </p>
                                            </li>
                                        </template>
                                    </ol>
                                </template>
                            </div>

                            <Transition name="msg-pop">
                                <button
                                    v-if="newCount > 0"
                                    type="button"
                                    class="msg-new"
                                    @click="jumpToNew"
                                >
                                    {{ newCount }} new {{ newCount === 1 ? 'message' : 'messages' }}
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12l7 7 7-7" /></svg>
                                </button>
                            </Transition>

                            <!-- Composer -->
                            <form
                                v-if="t?.loaded && draft"
                                class="msg-composer"
                                @submit.prevent="submit"
                            >
                                <p
                                    v-if="t.order"
                                    class="msg-official"
                                >
                                    Returns and refunds are requested from your order, not by chat.
                                    <button
                                        type="button"
                                        class="link-btn"
                                        @click="viewOrders"
                                    >
                                        Go to My Orders
                                    </button>
                                </p>

                                <p
                                    v-if="asking"
                                    class="msg-asking"
                                >
                                    <span class="msg-asking-label">Asking about</span>
                                    <span class="msg-asking-name">{{ asking.name }}</span>
                                    <button
                                        type="button"
                                        class="msg-asking-remove"
                                        :aria-label="`Don’t attach ${asking.name} to this message`"
                                        @click="clearAskingAbout(activeId)"
                                    >
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                    </button>
                                </p>

                                <div
                                    v-if="suggestions.length"
                                    class="msg-suggest"
                                    role="group"
                                    aria-label="Suggested questions (fills the message box)"
                                >
                                    <button
                                        v-for="s in suggestions"
                                        :key="s"
                                        type="button"
                                        class="msg-chip"
                                        @click="useSuggestion(s)"
                                    >
                                        {{ s }}
                                    </button>
                                </div>

                                <TransitionGroup
                                    v-if="draft.files.length"
                                    name="msg-pop"
                                    tag="ul"
                                    class="msg-previews"
                                    aria-label="Photos to send"
                                >
                                    <li
                                        v-for="item in draft.files"
                                        :key="item.key"
                                        class="msg-preview"
                                        :class="{ 'is-error': item.error }"
                                    >
                                        <img
                                            v-if="item.url"
                                            :src="item.url"
                                            :alt="item.file.name"
                                            width="64"
                                            height="64"
                                        >
                                        <span
                                            v-else
                                            class="msg-preview-error"
                                        >{{ item.error }}</span>
                                        <button
                                            type="button"
                                            class="msg-preview-remove"
                                            :aria-label="`Remove ${item.file.name}`"
                                            @click="removeFile(item.key)"
                                        >
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                        </button>
                                    </li>
                                </TransitionGroup>

                                <div class="msg-compose-row">
                                    <label
                                        class="icon-btn msg-attach"
                                        :class="{ 'is-disabled': draft.files.length >= IMAGE_RULES.maxCount }"
                                    >
                                        <input
                                            ref="fileInput"
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            multiple
                                            class="sr-only"
                                            :disabled="draft.files.length >= IMAGE_RULES.maxCount"
                                            @change="onFilesChosen"
                                        >
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="8.5" cy="9.5" r="1.5" /><path d="m21 16-5-5-9 9" /></svg>
                                        <span class="sr-only">Add photos (JPEG, PNG or WebP, up to 5 MB, 4 at a time)</span>
                                    </label>
                                    <label
                                        for="msg-input"
                                        class="sr-only"
                                    >Message {{ active.seller }}</label>
                                    <textarea
                                        id="msg-input"
                                        ref="composer"
                                        v-model="draft.text"
                                        rows="1"
                                        maxlength="4000"
                                        :placeholder="`Message ${active.seller}`"
                                        :enterkeyhint="coarsePointer ? 'enter' : 'send'"
                                        @keydown="onKeydown"
                                        @input="autosize"
                                    ></textarea>
                                    <button
                                        type="submit"
                                        class="msg-send"
                                        :disabled="!canSend"
                                        aria-label="Send message"
                                    >
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" /></svg>
                                    </button>
                                </div>
                                <p
                                    v-if="!coarsePointer"
                                    class="msg-hint"
                                >
                                    Enter to send · Shift+Enter for a new line
                                </p>
                            </form>
                        </div>
                    </section>
                </div>

                <p
                    class="sr-only"
                    aria-live="polite"
                >
                    {{ announcement }}
                </p>

                <!-- Photo viewer -->
                <Transition name="msg-fade">
                    <div
                        v-if="viewer"
                        ref="viewerEl"
                        class="msg-viewer"
                        role="dialog"
                        aria-modal="true"
                        :aria-label="viewer.name"
                        @click.self="closeViewer"
                    >
                        <button
                            ref="viewerClose"
                            type="button"
                            class="msg-viewer-close"
                            aria-label="Close photo"
                            @click="closeViewer"
                        >
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                        <img
                            :src="viewer.src"
                            :alt="viewer.name"
                        >
                    </div>
                </Transition>
            </div>
        </div>
    </Transition>

</template>
