<!-- resources/js/seller/components/CourierHandover.vue -->
<template>
    <div>
        <!-- ================================================================
         HEADER
         ================================================================ -->
        <header class="prep-header">
            <div>
                <div class="prep-breadcrumb" style="margin: 0 0 0.35rem">
                    <span>Seller Portal</span>
                    <span>/</span>
                    <span>Logistics</span>
                </div>
                <h2 class="prep-title">Courier Handover</h2>
            </div>
            <button class="btn-outline" @click="refresh" :disabled="isLoadingOrders">
                {{ isLoadingOrders ? 'Refreshing…' : 'Refresh' }}
            </button>
        </header>

        <!-- ================================================================
         METRICS
         Real counts derived from the same orders Orders/Dashboard use —
         no fabricated figures (no fake "avg pickup response" time, since
         we don't track per-status timestamps precisely enough for that).
         ================================================================ -->
        <div class="ch-metric-grid">
            <div class="ch-metric-card">
                <div class="ch-metric-top">
                    <span class="ch-metric-icon sky">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                            <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                        </svg>
                    </span>
                    <span class="ch-metric-chip sky">Ready</span>
                </div>
                <h4 class="ch-metric-value">{{ processingOrders.length }}</h4>
                <p class="ch-metric-label">Parcels Awaiting Handover</p>
            </div>

            <div class="ch-metric-card">
                <div class="ch-metric-top">
                    <span class="ch-metric-icon amber">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M2 6h9v8H2zM11 9h4l3 3v2h-7z" />
                            <circle cx="5.5" cy="15.5" r="1.5" />
                            <circle cx="14.5" cy="15.5" r="1.5" />
                        </svg>
                    </span>
                    <span class="ch-metric-chip amber">In Transit</span>
                </div>
                <h4 class="ch-metric-value">{{ inTransitOrders.length }}</h4>
                <p class="ch-metric-label">Currently Shipped</p>
            </div>

            <div class="ch-metric-card">
                <div class="ch-metric-top">
                    <span class="ch-metric-icon emerald">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                            <circle cx="10" cy="10" r="8" />
                            <path d="m6.5 10 2.5 2.5 4.5-5" />
                        </svg>
                    </span>
                    <span class="ch-metric-chip emerald">Completed</span>
                </div>
                <h4 class="ch-metric-value">{{ deliveredThisWeek.length }}</h4>
                <p class="ch-metric-label">Delivered This Week</p>
            </div>

            <div class="ch-metric-card">
                <div class="ch-metric-top">
                    <span class="ch-metric-icon indigo">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M10 3v14M4 6.5c0-1.4 2.2-2.5 6-2.5s6 1.1 6 2.5-2.2 2.5-6 2.5-6 1.1-6 2.5 2.2 2.5 6 2.5 6 1.1 6 2.5-2.2 2.5-6 2.5-6-1.1-6-2.5" />
                        </svg>
                    </span>
                    <span class="ch-metric-chip indigo">In Transit</span>
                </div>
                <h4 class="ch-metric-value">{{ formatCurrency(inTransitValue) }}</h4>
                <p class="ch-metric-label">Value Currently Shipped</p>
            </div>
        </div>

        <!-- ================================================================
         WORKFLOW
         ================================================================ -->
        <div class="ch-grid">
            <!-- LEFT: bulk handover form -->
            <div class="card">
                <div class="prep-card-head" style="border-bottom: none">
                    <div>
                        <h3>Hand Over to Courier</h3>
                        <p class="prep-card-sub">
                            Confirm which packed orders are being picked up
                            right now.
                        </p>
                    </div>
                </div>

                <div class="prep-form" style="padding-top: 0">
                    <div>
                        <label class="field-label">Courier / Carrier</label>
                        <input
                            type="text"
                            class="field-input"
                            v-model="handoverCarrier"
                            placeholder="e.g. LBC, J&T, Ninja Van"
                        />
                    </div>

                    <div>
                        <label class="field-label">Service Tier</label>
                        <select class="field-input" v-model="handoverService">
                            <option value="Standard">Standard</option>
                            <option value="Express">Express</option>
                            <option value="Same-Day">Same-Day</option>
                        </select>
                    </div>

                    <div>
                        <div class="flex items-center justify-between" style="margin-bottom: 0.5rem">
                            <label class="field-label" style="margin: 0">
                                Orders in This Handover
                            </label>
                            <button
                                type="button"
                                class="ch-select-all-btn"
                                @click="toggleSelectAll"
                            >
                                {{ allSelected ? 'Deselect All' : 'Select All' }}
                            </button>
                        </div>

                        <div v-if="isLoadingOrders" class="placeholder-page" style="padding: 1.5rem 0">
                            <div class="loading-spinner"></div>
                        </div>

                        <div v-else-if="processingOrders.length === 0" class="ch-empty-note">
                            No packed orders are waiting for pickup right now.
                            Use <strong>Prepare Orders</strong> to pack and
                            queue up your next shipment.
                        </div>

                        <div v-else class="ch-order-checklist scrollbar-hidden">
                            <label
                                v-for="o in processingOrders"
                                :key="o.id"
                                class="ch-order-check-row"
                            >
                                <input
                                    type="checkbox"
                                    :value="o.id"
                                    v-model="selectedOrderIds"
                                />
                                <div class="ch-order-check-info">
                                    <p class="ch-order-check-id">{{ o.id }}</p>
                                    <p class="ch-order-check-meta">{{ o.customer }} — {{ formatCurrency(o.total) }}</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <p v-if="handoverError" class="save-msg error">{{ handoverError }}</p>
                    <p v-if="handoverSuccess" class="ch-success-note">{{ handoverSuccess }}</p>

                    <button
                        class="btn-primary"
                        style="width: 100%"
                        :disabled="!canConfirmHandover"
                        :title="handoverDisabledReason"
                        @click="confirmHandover"
                    >
                        {{ isHandingOver ? 'Confirming…' : `Confirm Handover (${selectedOrderIds.length})` }}
                    </button>
                    <p class="field-hint" style="text-align: center">
                        Tracking numbers can be added per order afterward from
                        Orders → Order Details.
                    </p>
                </div>
            </div>

            <!-- RIGHT: history + workflow explainer -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem">
                <div class="card">
                    <div class="prep-card-head">
                        <div>
                            <h3>Shipment Handover History</h3>
                            <p class="prep-card-sub">Orders that have already shipped.</p>
                        </div>
                        <input
                            type="text"
                            class="field-input"
                            style="max-width: 12rem"
                            v-model="historySearch"
                            placeholder="Search order or tracking #…"
                        />
                    </div>

                    <div v-if="isLoadingOrders" class="placeholder-page" style="padding: 2rem 0">
                        <div class="loading-spinner"></div>
                    </div>

                    <div v-else-if="filteredHistory.length === 0" class="ch-empty-note" style="margin: 1.4rem">
                        No shipments {{ historySearch ? 'match your search' : 'yet' }}.
                    </div>

                    <div v-else style="overflow-x: auto">
                        <table class="sales-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Tracking #</th>
                                    <th>Carrier</th>
                                    <th>Service</th>
                                    <th>Handover Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="o in pagedHistory" :key="o.id">
                                    <td class="order-id">{{ o.id }}</td>
                                    <td>{{ o.trackingNumber || '—' }}</td>
                                    <td>{{ o.shippingCarrier || '—' }}</td>
                                    <td>{{ o.shippingService || '—' }}</td>
                                    <td>{{ formatHandoverDate(o.updatedAt) }}</td>
                                    <td>
                                        <span class="badge" :class="statusBadgeClass(o.status)">{{ o.status }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="filteredHistory.length > pageSize" class="ch-pagination">
                        <span>Showing {{ pagedHistory.length }} of {{ filteredHistory.length }}</span>
                        <div class="flex items-center gap-2">
                            <button class="btn-outline" style="padding: 0.3rem 0.7rem" :disabled="historyPage === 1" @click="historyPage--">Prev</button>
                            <button class="btn-outline" style="padding: 0.3rem 0.7rem" :disabled="historyPage >= totalHistoryPages" @click="historyPage++">Next</button>
                        </div>
                    </div>
                </div>

                <!-- Workflow explainer (static — not tied to a specific
                     order; per-order tracking lives on Order Details) -->
                <div class="card" style="padding: 1.5rem 1.4rem">
                    <h3 style="font-size: 0.85rem; font-weight: 800; color: #0f172a; margin: 0 0 1.2rem">
                        How Orders Move Through Handover
                    </h3>
                    <div class="ch-stepper">
                        <div class="ch-stepper-track"></div>
                        <div
                            v-for="(step, idx) in workflowSteps"
                            :key="step.key"
                            class="ch-stepper-step"
                        >
                            <div class="ch-stepper-dot" :class="{ active: idx === 0 || idx === 1 }">
                                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                    <path :d="step.icon" />
                                </svg>
                            </div>
                            <p class="ch-stepper-label">{{ step.label }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useOrders } from '../composables/useOrders';

const {
    orders,
    isLoadingOrders,
    loadOrders,
    statusBadgeClass,
    formatCurrency,
    shipOrder,
} = useOrders();

onMounted(() => {
    if (!orders.value.length) {
        loadOrders();
    }
});

function refresh() {
    loadOrders();
}

// ---- real order buckets ----
const processingOrders = computed(() => orders.value.filter((o) => o.status === 'Processing'));
const inTransitOrders = computed(() => orders.value.filter((o) => o.status === 'In Transit'));
const deliveredThisWeek = computed(() => {
    const weekAgo = Date.now() - 7 * 24 * 60 * 60 * 1000;

    return orders.value.filter(
        (o) => o.status === 'Delivered' && o.updatedAt && new Date(o.updatedAt).getTime() >= weekAgo,
    );
});
const inTransitValue = computed(() =>
    inTransitOrders.value.reduce((sum, o) => sum + (Number(o.total) || 0), 0),
);

// ---- bulk handover form ----
const handoverCarrier = ref('');
const handoverService = ref('Standard');
const selectedOrderIds = ref([]);
const isHandingOver = ref(false);
const handoverError = ref('');
const handoverSuccess = ref('');

// Default to selecting every ready order — matches the common case of
// "the courier is here, hand over everything that's packed."
watch(
    processingOrders,
    (list) => {
        const validIds = new Set(list.map((o) => o.id));
        selectedOrderIds.value = selectedOrderIds.value.filter((id) => validIds.has(id));

        if (selectedOrderIds.value.length === 0) {
            selectedOrderIds.value = list.map((o) => o.id);
        }
    },
    { immediate: true },
);

const allSelected = computed(
    () => processingOrders.value.length > 0 && selectedOrderIds.value.length === processingOrders.value.length,
);

function toggleSelectAll() {
    selectedOrderIds.value = allSelected.value ? [] : processingOrders.value.map((o) => o.id);
}

const canConfirmHandover = computed(
    () => selectedOrderIds.value.length > 0 && handoverCarrier.value.trim().length > 0 && !isHandingOver.value,
);
const handoverDisabledReason = computed(() => {
    if (isHandingOver.value) return '';
    if (selectedOrderIds.value.length === 0) return 'Select at least one order.';
    if (!handoverCarrier.value.trim()) return 'Enter the courier/carrier name.';

    return '';
});

async function confirmHandover() {
    if (!canConfirmHandover.value) return;

    isHandingOver.value = true;
    handoverError.value = '';
    handoverSuccess.value = '';

    const ids = [...selectedOrderIds.value];
    const results = await Promise.all(
        ids.map((id) =>
            shipOrder(id, {
                shipping_carrier: handoverCarrier.value.trim(),
                shipping_service: handoverService.value || null,
            }),
        ),
    );

    const succeeded = results.filter(Boolean).length;
    const failed = results.length - succeeded;

    isHandingOver.value = false;

    if (succeeded > 0) {
        handoverSuccess.value = `${succeeded} order(s) handed over to ${handoverCarrier.value.trim()}.`;
    }

    if (failed > 0) {
        handoverError.value = `${failed} order(s) could not be updated — they may have changed status already. Refresh and try again.`;
    }
}

// ---- shipment history ----
const historySearch = ref('');
const historyPage = ref(1);
const pageSize = 8;

const shippedOrders = computed(() =>
    orders.value
        .filter((o) => o.status === 'In Transit' || o.status === 'Delivered')
        .sort((a, b) => new Date(b.updatedAt || 0) - new Date(a.updatedAt || 0)),
);

const filteredHistory = computed(() => {
    const q = historySearch.value.trim().toLowerCase();

    if (!q) return shippedOrders.value;

    return shippedOrders.value.filter(
        (o) =>
            o.id.toLowerCase().includes(q) ||
            (o.trackingNumber || '').toLowerCase().includes(q),
    );
});

const totalHistoryPages = computed(() => Math.max(1, Math.ceil(filteredHistory.value.length / pageSize)));

const pagedHistory = computed(() => {
    const start = (historyPage.value - 1) * pageSize;

    return filteredHistory.value.slice(start, start + pageSize);
});

watch(filteredHistory, () => {
    historyPage.value = 1;
});

function formatHandoverDate(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

// ---- static workflow explainer (matches the real 4-state pipeline) ----
const workflowSteps = [
    { key: 'placed', label: 'Order Placed', icon: 'M3 8h14M3 12h14M3 16h8' },
    { key: 'processing', label: 'Packed & Ready', icon: 'M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z' },
    { key: 'transit', label: 'In Transit', icon: 'M2 6h9v8H2zM11 9h4l3 3v2h-7z' },
    { key: 'delivered', label: 'Delivered', icon: 'M3 10h14M10 3v14' },
];
</script>