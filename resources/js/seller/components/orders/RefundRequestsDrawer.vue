<!--
    Seller review of buyer refund requests. Approving refunds the buyer via
    seller-funded settlement. Logistics keeps its forward share and the
    platform reverses the goods commission. Opened from the Orders header.
-->
<template>
    <Teleport to="body">
        <Transition name="rr-fade">
            <div v-if="open" class="rr-overlay" @click.self="close">
                <aside class="rr-drawer" role="dialog" aria-modal="true" aria-labelledby="rr-title">
                    <header class="rr-head">
                        <div>
                            <h2 id="rr-title">Refund requests</h2>
                            <p>You fund the refund, including any refunded shipping. Logistics and couriers keep their earnings; the platform reverses its goods commission. Approved returns also charge return shipping to you.</p>
                        </div>
                        <button type="button" class="rr-icon-btn" aria-label="Close" @click="close">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </header>

                    <nav class="rr-tabs" role="tablist">
                        <button
                            v-for="tab in tabs"
                            :key="tab.value"
                            type="button"
                            role="tab"
                            class="rr-tab"
                            :class="{ active: status === tab.value }"
                            :aria-selected="status === tab.value"
                            @click="load(tab.value)"
                        >
                            {{ tab.label }}
                            <span class="rr-tab-count">{{ counts[tab.value] || 0 }}</span>
                        </button>
                    </nav>

                    <!-- Approve all -->
                    <div v-if="status === 'pending' && requests.length > 1" class="rr-bulk">
                        <template v-if="!confirmingAll">
                            <div>
                                <strong>{{ requests.length }} pending</strong>
                                <span>{{ peso(pendingTotal) }} total</span>
                            </div>
                            <button type="button" class="rr-btn rr-btn-primary" @click="confirmingAll = true">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 7 17l-5-5" /><path d="m22 10-7.5 7.5L13 16" /></svg>
                                Approve all
                            </button>
                        </template>
                        <template v-else>
                            <p class="rr-bulk-q">Refund all {{ requests.length }} requests ({{ peso(pendingTotal) }})? This can't be undone.</p>
                            <div class="rr-actions">
                                <button type="button" class="rr-btn rr-btn-ghost" :disabled="bulkBusy" @click="confirmingAll = false">Cancel</button>
                                <button type="button" class="rr-btn rr-btn-primary" :disabled="bulkBusy" @click="handleApproveAll">
                                    <span v-if="bulkBusy" class="rr-spinner" aria-hidden="true" />
                                    {{ bulkBusy ? 'Approving…' : 'Yes, approve all' }}
                                </button>
                            </div>
                        </template>
                    </div>

                    <div v-if="notice" class="rr-notice" :class="notice.tone" role="status">
                        <span>{{ notice.text }}</span>
                        <button type="button" class="rr-link" @click="notice = null">Dismiss</button>
                    </div>

                    <div class="rr-body">
                        <div v-if="loadError" class="rr-state">
                            <p>{{ loadError }}</p>
                            <button type="button" class="rr-btn rr-btn-ghost" @click="load()">Try again</button>
                        </div>

                        <ul v-else-if="isLoading && !requests.length" class="rr-list" aria-hidden="true">
                            <li v-for="n in 3" :key="n" class="rr-card"><span class="rr-skel" /></li>
                        </ul>

                        <div v-else-if="!requests.length" class="rr-state">
                            <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335" /><path d="m9 11 3 3L22 4" /></svg>
                            <p>{{ emptyText }}</p>
                        </div>

                        <ul v-else class="rr-list">
                            <li v-for="req in requests" :key="req.id" class="rr-card">
                                <div class="rr-card-top">
                                    <div class="rr-min">
                                        <strong class="rr-product">{{ req.productName }}</strong>
                                        <span class="rr-meta">
                                            #{{ req.orderNumber }} · {{ req.buyerName }}<template v-if="req.variant"> · {{ req.variant }}</template>
                                        </span>
                                    </div>
                                    <div class="rr-amount">
                                        <strong>{{ peso(req.refundedAmount ?? req.amount) }}</strong>
                                        <span>× {{ req.quantity }}</span>
                                    </div>
                                </div>

                                <div class="rr-chips">
                                    <span class="rr-chip rr-chip-reason">{{ req.reasonLabel }}</span>
                                    <span class="rr-chip">{{ req.requestType === 'return_and_refund' ? 'Return & refund' : 'Refund only' }}</span>
                                    <span v-if="returnChip(req)" class="rr-chip rr-chip-reason">{{ returnChip(req) }}</span>
                                    <span class="rr-chip rr-chip-time">{{ timeAgo(req.submittedAt) }}</span>
                                </div>

                                <p v-if="req.requestType === 'return_and_refund' && req.status === 'pending'" class="rr-details">
                                    Approving sends a courier to collect the item from the buyer and bring it back to you through logistics.
                                    The return shipping fee is charged to you, and the buyer is refunded once the item reaches you.
                                </p>

                                <p v-if="req.details" class="rr-details">{{ req.details }}</p>

                                <div v-if="req.evidence?.length" class="rr-evidence">
                                    <a
                                        v-for="(src, i) in req.evidence"
                                        :key="i"
                                        :href="src"
                                        target="_blank"
                                        rel="noopener"
                                        :aria-label="`Open evidence photo ${i + 1}`"
                                    >
                                        <img :src="src" alt="" width="56" height="56" loading="lazy">
                                    </a>
                                </div>

                                <p v-if="req.status !== 'pending'" class="rr-outcome" :class="req.status">
                                    {{ outcomeLabel(req) }}
                                    <template v-if="req.resolutionNote"> — {{ req.resolutionNote }}</template>
                                </p>

                                <template v-else>
                                    <div v-if="rejectingId === req.id" class="rr-reject">
                                        <label :for="`rr-note-${req.id}`">Describe why you're declining <span class="rr-req">*</span> (shown to the buyer)</label>
                                        <textarea
                                            :id="`rr-note-${req.id}`"
                                            v-model="rejectNote"
                                            rows="3"
                                            maxlength="500"
                                            required
                                            placeholder="e.g. The photos show the item was used after delivery, which isn't covered by our return policy."
                                        />
                                        <small class="rr-hint" :class="{ ok: rejectNote.trim().length >= MIN_REJECT_NOTE }">
                                            {{ rejectNote.trim().length }}/{{ MIN_REJECT_NOTE }} characters minimum
                                        </small>
                                        <div class="rr-actions">
                                            <button type="button" class="rr-btn rr-btn-ghost" :disabled="busyId === req.id" @click="rejectingId = null">Cancel</button>
                                            <button
                                                type="button"
                                                class="rr-btn rr-btn-danger"
                                                :disabled="busyId === req.id || rejectNote.trim().length < MIN_REJECT_NOTE"
                                                @click="handleReject(req)"
                                            >
                                                <span v-if="busyId === req.id" class="rr-spinner" aria-hidden="true" />
                                                Reject request
                                            </button>
                                        </div>
                                    </div>
                                    <div v-else class="rr-actions">
                                        <button type="button" class="rr-btn rr-btn-ghost" :disabled="busyId === req.id" @click="startReject(req)">Reject</button>
                                        <button type="button" class="rr-btn rr-btn-primary" :disabled="busyId === req.id" @click="handleApprove(req)">
                                            <span v-if="busyId === req.id" class="rr-spinner" aria-hidden="true" />
                                            Approve refund
                                        </button>
                                    </div>
                                </template>
                            </li>
                        </ul>
                    </div>
                </aside>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRefundRequests } from '../../composables/useRefundRequests';

const props = defineProps({
    open: { type: Boolean, default: false }
});

const emit = defineEmits(['close']);

const { requests, counts, status, isLoading, loadError, load, approve, reject, approveAll } = useRefundRequests();

const tabs = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' }
];

const busyId = ref(null);
const rejectingId = ref(null);
// Keep in sync with RefundRequestController::reject's min rule.
const MIN_REJECT_NOTE = 10;

function outcomeLabel(req) {
    if (req.status === 'rejected') {
        return 'Declined';
    }
    if (req.requestType === 'return_and_refund') {
        return req.status === 'completed' ? 'Returned & refunded' : 'Return approved — awaiting courier';
    }

    return 'Refunded';
}
const rejectNote = ref('');
const confirmingAll = ref(false);
const bulkBusy = ref(false);
const notice = ref(null);

const pesoFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const peso = value => pesoFormat.format(Number(value || 0));

const pendingTotal = computed(() => requests.value.reduce((sum, r) => sum + Number(r.amount || 0), 0));

const emptyText = computed(() => ({
    pending: "You're all caught up — no refund requests waiting.",
    approved: 'No approved refunds yet.',
    rejected: 'No rejected requests.'
}[status.value]));

function timeAgo(iso) {
    if (!iso) {
        return '';
    }

    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutes < 60) {
        return `${Math.max(minutes, 1)}m ago`;
    }

    if (minutes < 1440) {
        return `${Math.round(minutes / 60)}h ago`;
    }

    return `${Math.round(minutes / 1440)}d ago`;
}

function close() {
    emit('close');
}

function startReject(req) {
    rejectingId.value = req.id;
    rejectNote.value = '';
}

async function handleApprove(req) {
    busyId.value = req.id;
    const { data, error } = await approve(req.id);
    busyId.value = null;

    notice.value = error
        ? { tone: 'error', text: error }
        : { tone: 'success', text: approvedText(data, req) };
}

function approvedText(data, req) {
    return data.requestType === 'return_and_refund'
        ? `Return approved for #${req.orderNumber}. A courier will bring the item back to you; the buyer is refunded once it arrives.`
        : `Refunded ${peso(data.refundedAmount)} for #${req.orderNumber}.`;
}

function returnChip(req) {
    if (req.requestType !== 'return_and_refund') {
        return '';
    }
    if (req.status === 'completed') {
        return 'Returned';
    }

    return req.status === 'approved' ? 'To return · via courier' : '';
}

async function handleReject(req) {
    busyId.value = req.id;
    const { error } = await reject(req.id, rejectNote.value.trim());
    busyId.value = null;

    if (error) {
        notice.value = { tone: 'error', text: error };

        return;
    }

    rejectingId.value = null;
    notice.value = { tone: 'success', text: `Rejected the request for #${req.orderNumber}.` };
}

async function handleApproveAll() {
    bulkBusy.value = true;
    const { data, error } = await approveAll();
    bulkBusy.value = false;
    confirmingAll.value = false;

    if (error) {
        notice.value = { tone: 'error', text: error };

        return;
    }

    const failed = data.failed.length;
    notice.value = failed
        ? { tone: 'error', text: `Approved ${data.approved}; ${failed} couldn't be refunded (${data.failed.map(f => `#${f.orderNumber}: ${f.message}`).join('; ')}).` }
        : { tone: 'success', text: `Approved ${data.approved} request${data.approved === 1 ? '' : 's'}. Refund-only requests were refunded; returns are refunded once the item reaches you.` };
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        close();
    }
}

watch(
    () => props.open,
    isOpen => {
        if (isOpen) {
            notice.value = null;
            confirmingAll.value = false;
            rejectingId.value = null;
            load('pending');
            document.addEventListener('keydown', onKeydown);
        } else {
            document.removeEventListener('keydown', onKeydown);
        }
    }
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<style scoped>
.rr-overlay { position: fixed; inset: 0; z-index: 90; background: rgba(15, 23, 42, .45); display: flex; justify-content: flex-end; }
.rr-drawer { width: min(520px, 100%); height: 100%; background: #f8fafc; display: flex; flex-direction: column; box-shadow: -12px 0 40px rgba(15, 23, 42, .18); }
.rr-fade-enter-active, .rr-fade-leave-active { transition: opacity .2s ease; }
.rr-fade-enter-active .rr-drawer, .rr-fade-leave-active .rr-drawer { transition: transform .25s ease; }
.rr-fade-enter-from, .rr-fade-leave-to { opacity: 0; }
.rr-fade-enter-from .rr-drawer, .rr-fade-leave-to .rr-drawer { transform: translateX(40px); }

.rr-head { display: flex; justify-content: space-between; gap: 16px; padding: 20px 20px 12px; background: #fff; }
.rr-head h2 { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
.rr-head p { margin: 4px 0 0; font-size: 13px; color: #64748b; line-height: 1.45; }
.rr-icon-btn { flex-shrink: 0; width: 40px; height: 40px; display: grid; place-items: center; border: 0; border-radius: 999px; background: transparent; color: #64748b; cursor: pointer; }
.rr-icon-btn:hover { background: #f1f5f9; color: #0f172a; }

.rr-tabs { display: flex; gap: 4px; padding: 0 20px; background: #fff; border-bottom: 1px solid #e2e8f0; }
.rr-tab { display: inline-flex; align-items: center; gap: 6px; padding: 12px 10px; border: 0; background: none; font-size: 14px; font-weight: 600; color: #64748b; border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; }
.rr-tab.active { color: #0d9488; border-bottom-color: #0d9488; }
.rr-tab-count { min-width: 22px; padding: 1px 7px; border-radius: 999px; background: #f1f5f9; font-size: 12px; text-align: center; }
.rr-tab.active .rr-tab-count { background: #ccfbf1; color: #0f766e; }

.rr-bulk { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin: 16px 20px 0; padding: 12px 14px; border-radius: 14px; background: #fff; border: 1px solid #e2e8f0; }
.rr-bulk strong { display: block; font-size: 14px; color: #0f172a; }
.rr-bulk span { font-size: 13px; color: #64748b; }
.rr-bulk-q { margin: 0; flex: 1 1 200px; font-size: 13px; font-weight: 600; color: #0f172a; }

.rr-notice { display: flex; justify-content: space-between; gap: 12px; margin: 12px 20px 0; padding: 10px 14px; border-radius: 12px; font-size: 13px; }
.rr-notice.success { background: #ecfdf5; color: #047857; }
.rr-notice.error { background: #fef2f2; color: #b91c1c; }
.rr-link { flex-shrink: 0; border: 0; background: none; color: inherit; font-weight: 700; cursor: pointer; text-decoration: underline; }

.rr-body { flex: 1; overflow-y: auto; padding: 16px 20px 24px; }
.rr-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.rr-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.rr-card-top { display: flex; justify-content: space-between; gap: 12px; }
.rr-min { min-width: 0; }
.rr-product { display: block; font-size: 15px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rr-meta { display: block; font-size: 12px; color: #94a3b8; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rr-amount { text-align: right; flex-shrink: 0; }
.rr-amount strong { display: block; font-size: 16px; color: #0f172a; }
.rr-amount span { font-size: 12px; color: #94a3b8; }

.rr-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.rr-chip { padding: 3px 10px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 12px; font-weight: 600; }
.rr-chip-reason { background: #fff7ed; color: #c2410c; }
.rr-chip-time { background: transparent; color: #94a3b8; padding-left: 0; }

.rr-details { margin: 0; font-size: 13px; color: #475569; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.rr-evidence { display: flex; gap: 8px; }
.rr-evidence img { width: 56px; height: 56px; object-fit: cover; border-radius: 10px; border: 1px solid #e2e8f0; display: block; }
.rr-evidence a:hover img { border-color: #0d9488; }

.rr-outcome { margin: 0; font-size: 13px; font-weight: 600; }
.rr-outcome.approved { color: #047857; }
.rr-outcome.rejected { color: #b91c1c; }

.rr-reject { display: flex; flex-direction: column; gap: 8px; }
.rr-reject label { font-size: 12px; font-weight: 600; color: #475569; }
.rr-req { color: #dc2626; }
.rr-hint { font-size: 11px; color: #94a3b8; }
.rr-hint.ok { color: #16a34a; }
.rr-reject textarea { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; font: inherit; font-size: 13px; resize: vertical; }
.rr-reject textarea:focus { outline: none; border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220, 38, 38, .12); }

.rr-actions { display: flex; justify-content: flex-end; gap: 8px; }
.rr-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 40px; padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 700; border: 1px solid transparent; cursor: pointer; transition: background .15s, opacity .15s; }
.rr-btn:disabled { opacity: .55; cursor: not-allowed; }
.rr-btn-primary { background: #0d9488; color: #fff; }
.rr-btn-primary:hover:not(:disabled) { background: #0f766e; }
.rr-btn-ghost { background: #fff; border-color: #e2e8f0; color: #475569; }
.rr-btn-ghost:hover:not(:disabled) { background: #f8fafc; }
.rr-btn-danger { background: #dc2626; color: #fff; }
.rr-btn-danger:hover:not(:disabled) { background: #b91c1c; }
.rr-spinner { width: 14px; height: 14px; border-radius: 999px; border: 2px solid currentColor; border-right-color: transparent; animation: rr-spin .7s linear infinite; }
@keyframes rr-spin { to { transform: rotate(360deg); } }

.rr-state { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 56px 16px; text-align: center; color: #94a3b8; }
.rr-state p { margin: 0; font-size: 14px; color: #64748b; }
.rr-skel { display: block; height: 96px; border-radius: 10px; background: linear-gradient(90deg, #f1f5f9, #e2e8f0, #f1f5f9); background-size: 200% 100%; animation: rr-shimmer 1.2s infinite; }
@keyframes rr-shimmer { to { background-position: -200% 0; } }

@media (max-width: 560px) {
    .rr-drawer { width: 100%; }
    .rr-actions { justify-content: stretch; }
    .rr-actions .rr-btn { flex: 1; }
}
</style>
