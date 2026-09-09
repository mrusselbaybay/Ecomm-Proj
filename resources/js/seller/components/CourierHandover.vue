<!-- resources/js/seller/components/CourierHandover.vue -->
<!--
  Courier Handover — 100% real data via useOrders() -> SellerOrderController.
  Originally a 4-column board mirroring the reference (Awaiting Handover /
  Manifest Ready / Ready for Pickup / Picked Up), but Prepare Orders' own
  action modal now drives the whole Processing -> Packed -> Ready for
  Pickup packing flow in one place (see PrepareOrders.vue's Step 3,
  "Ready for Pickup"), which made this page's first two columns a
  redundant second front door onto the exact same two status hops.

  This page's real job now starts where packing ends: a seller hands a
  packed, Ready for Pickup order to a courier. So it's just 2 columns:

    Ready for Pickup     = status 'Ready for Pickup'   -> Confirm Pickup (shipOrder)
    Picked Up (Transit)  = status 'In Transit'         -> read-only

  Once Confirm Pickup actually runs, the order really has been handed
  off, and it moves itself into the read-only "Picked Up" column — no
  order ever sits in a column labelled "already handed over" while
  still showing an action to hand it over.

  Each card gets one real forward action (the actual next status per
  Order::ALLOWED_TRANSITIONS). The reference's secondary buttons
  (Exclude / Remove / Undo) are dropped — there's no real, honest
  backend action behind any of them (statuses here only move forward).
-->
<template>
    <div class="courier-page">
    <template v-if="viewMode === 'board'">
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
         STAT BAR — 4 real metrics
         ================================================================ -->
        <div class="ch-stat-bar">
            <div class="ch-stat-seg">
                <span class="ch-stat-ic neutral">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                        <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                    </svg>
                </span>
                <div>
                    <div class="ch-stat-v">{{ readyOrders.length }}</div>
                    <div class="ch-stat-l">Ready for Pickup</div>
                </div>
            </div>
            <div class="ch-stat-seg">
                <span class="ch-stat-ic blue">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path d="M2 6h9v8H2zM11 9h4l3 3v2h-7z" />
                        <circle cx="5.5" cy="15.5" r="1.5" />
                        <circle cx="14.5" cy="15.5" r="1.5" />
                    </svg>
                </span>
                <div>
                    <div class="ch-stat-v">{{ inTransitOrders.length }}</div>
                    <div class="ch-stat-l">Currently Shipped</div>
                </div>
            </div>
            <div class="ch-stat-seg">
                <span class="ch-stat-ic good">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                        <circle cx="10" cy="10" r="8" />
                        <path d="m6.5 10 2.5 2.5 4.5-5" />
                    </svg>
                </span>
                <div>
                    <div class="ch-stat-v">{{ deliveredThisWeek.length }}</div>
                    <div class="ch-stat-l">Delivered This Week</div>
                </div>
            </div>
            <div class="ch-stat-seg">
                <span class="ch-stat-ic blue">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path d="M10 3v14M4 6.5c0-1.4 2.2-2.5 6-2.5s6 1.1 6 2.5-2.2 2.5-6 2.5-6 1.1-6 2.5 2.2 2.5 6 2.5 6 1.1 6 2.5-2.2 2.5-6 2.5-6-1.1-6-2.5" />
                    </svg>
                </span>
                <div>
                    <div class="ch-stat-v">{{ formatCurrency(inTransitValue) }}</div>
                    <div class="ch-stat-l">Value In Transit</div>
                </div>
            </div>
        </div>

        <!-- ================================================================
         COURIER FILTER + DATE + SEARCH — courier names come from real
         shipping_carrier values (only ever set once an order is
         actually shipped), not a fixed list.
         ================================================================ -->
        <div class="ch-controls-row">
            <div class="ch-tabs">
                <button
                    v-for="tab in courierTabs"
                    :key="tab.key"
                    type="button"
                    class="ch-tab"
                    :class="{ active: activeCourier === tab.key }"
                    @click="activeCourier = tab.key"
                >
                    {{ tab.label }} <span class="ch-tab-count">{{ tab.count }}</span>
                </button>
            </div>
            <div class="ch-controls-right">
                <span class="ch-date-pill">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M8 3v4M16 3v4M3 10h18" />
                    </svg>
                    {{ todayLabel }}
                </span>
                <input
                    type="text"
                    class="field-input ch-search"
                    v-model="boardSearch"
                    placeholder="Search order # or product…"
                />
            </div>
        </div>

        <!-- ================================================================
         CONFIRM-PICKUP TOOLBAR — the one real shipOrder() call on this
         page. The order select makes it explicit which real order the
         courier/service fields are about to be applied to; clicking
         "Select for Pickup" on a Ready for Pickup card just fills this
         in for you.
         ================================================================ -->
        <div class="card ch-assign-bar">
            <div class="ch-assign-field">
                <label class="field-label">Order</label>
                <select class="field-input" v-model="selectedOrderId">
                    <option value="">Select an order…</option>
                    <option v-for="o in readyOrders" :key="o.id" :value="o.id">
                        {{ o.id }} — {{ o.customer }}
                    </option>
                </select>
            </div>
            <div class="ch-assign-field">
                <label class="field-label">Courier</label>
                <select class="field-input" v-model="assignCarrier" :disabled="isLoadingCouriers">
                    <option value="">{{ courierSelectPlaceholder }}</option>
                    <option v-for="c in couriers" :key="c.id" :value="c.name">{{ c.name }}</option>
                </select>
            </div>
            <div class="ch-assign-field" style="max-width: 9rem">
                <label class="field-label">Service</label>
                <select class="field-input" v-model="assignService">
                    <option value="Standard">Standard</option>
                    <option value="Express">Express</option>
                    <option value="Same-Day">Same-Day</option>
                </select>
            </div>
            <button
                type="button"
                class="btn-primary"
                :disabled="!canConfirmPickup"
                :title="pickupDisabledReason"
                @click="confirmPickup"
            >
                {{ busyId === selectedOrderId && busyId ? 'Confirming…' : 'Confirm Pickup' }}
            </button>
            <p v-if="pickupError" class="save-msg error" style="margin: 0; flex-basis: 100%">{{ pickupError }}</p>
            <p v-else-if="pickupSuccess" class="ch-success-note" style="margin: 0; flex-basis: 100%">{{ pickupSuccess }}</p>
            <p v-else class="field-hint" style="margin: 0; flex-basis: 100%">
                A tracking number is generated automatically when you confirm — nothing to type.
            </p>
        </div>

        <!-- ================================================================
         KANBAN BOARD — 4 real columns, one per Order::STATUSES value
         (see file header comment).
         ================================================================ -->
        <div v-if="isLoadingOrders && !orders.length" class="placeholder-page" style="padding: 3rem 0">
            <div class="loading-spinner"></div>
        </div>

        <div v-else class="ch2-board">
            <div class="ch2-col">
                <div class="ch2-col-head blue">Ready for Pickup <span class="ch2-col-count">{{ boardReady.length }}</span></div>
                <div class="ch2-col-body">
                    <div
                        v-for="o in visibleCards(boardReady)"
                        :key="o.id"
                        class="ch2-card"
                        :class="{ 'is-targeted': selectedOrderId === o.id }"
                    >
                        <div class="ch2-card-top">
                            <span>Items: {{ (o.items || []).length }}</span>
                            <button type="button" class="ch2-view-link" @click="openDetails(o.id)">View Order Details</button>
                        </div>
                        <div class="ch2-card-main">
                            <span class="ch2-courier-badge" :class="courierBadgeClass(o.shippingCarrier)">
                                {{ courierInitials(o.shippingCarrier) }}
                            </span>
                            <div class="ch2-card-info">
                                <p class="ch2-order-id">{{ o.id }}</p>
                                <p class="ch2-cust">{{ o.customer }}</p>
                            </div>
                            <span class="ch2-price">{{ formatCurrency(o.total) }}</span>
                        </div>
                        <button
                            type="button"
                            class="ch2-btn-solid"
                            :class="{ selected: selectedOrderId === o.id }"
                            @click="selectedOrderId = o.id"
                        >
                            {{ selectedOrderId === o.id ? 'Selected ↑ for pickup' : 'Select for Pickup' }}
                        </button>
                    </div>
                    <p v-if="!boardReady.length" class="ch2-empty">
                        {{ readyOrders.length ? 'No orders match this filter.' : "Nothing ready for pickup yet — pack an order on Prepare Orders first." }}
                    </p>
                </div>
                <button
                    v-if="boardReady.length > visibleCards(boardReady).length"
                    type="button"
                    class="ch2-more"
                    @click="openList('Ready for Pickup')"
                >
                    View {{ boardReady.length - visibleCards(boardReady).length }} more →
                </button>
            </div>

            <div class="ch2-col">
                <div class="ch2-col-head good">Picked Up (In Transit) <span class="ch2-col-count">{{ boardTransit.length }}</span></div>
                <div class="ch2-col-body">
                    <div v-for="o in visibleCards(boardTransit)" :key="o.id" class="ch2-card">
                        <div class="ch2-card-top">
                            <span>Items: {{ (o.items || []).length }}</span>
                            <button type="button" class="ch2-view-link" @click="openDetails(o.id)">View Order Details</button>
                        </div>
                        <div class="ch2-card-main">
                            <span class="ch2-courier-badge" :class="courierBadgeClass(o.shippingCarrier)">
                                {{ courierInitials(o.shippingCarrier) }}
                            </span>
                            <div class="ch2-card-info">
                                <p class="ch2-order-id">{{ o.id }}</p>
                                <p class="ch2-cust">{{ o.customer }}</p>
                            </div>
                            <span class="ch2-price">{{ formatCurrency(o.total) }}</span>
                        </div>
                        <p class="ch2-note">Picked up — now In Transit.</p>
                    </div>
                    <p v-if="!boardTransit.length" class="ch2-empty">
                        {{ inTransitOrders.length ? 'No orders match this filter.' : 'Nothing in transit right now.' }}
                    </p>
                </div>
                <button
                    v-if="boardTransit.length > visibleCards(boardTransit).length"
                    type="button"
                    class="ch2-more"
                    @click="openList('In Transit')"
                >
                    View {{ boardTransit.length - visibleCards(boardTransit).length }} more →
                </button>
            </div>
        </div>

        <!-- ================================================================
         HISTORY + WORKFLOW EXPLAINER
         ================================================================ -->
        <div class="ch-grid-bottom">
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
                            <tr v-for="o in pagedHistory" :key="o.id" class="ch-history-row" @click="openDetails(o.id)">
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
                <h3 class="ch-stepper-title">How Orders Move Through Handover</h3>
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
    </template>

    <!-- ================================================================
     SHIPMENTS LIST — adapted from the reference's "View more" drill-down:
     a dedicated, filterable, paginated list scoped to Logistics, opened
     by a board column's "View N more" (see openList()) rather than
     growing that column indefinitely. Tabs mirror the board's own 2
     real columns exactly (see LIST_TABS/file header comment) — no
     Processing/Packed tabs, and "Ready for Pickup" instead of the
     reference's "Handed to Courier": nothing ever sits in a tab
     claiming it's already been handed over while it still hasn't been.
     ================================================================ -->
    <template v-else>
        <div class="chl-topbar">
            <button type="button" class="chl-back-btn" aria-label="Back to board" @click="closeList">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M15 5 8 12l7 7" />
                </svg>
            </button>
            <div>
                <div class="prep-breadcrumb" style="margin: 0 0 0.35rem">
                    <span>Seller Portal</span>
                    <span>/</span>
                    <span>Logistics</span>
                    <span>/</span>
                    <span>{{ activeListTab.label }}</span>
                </div>
                <h2 class="prep-title">{{ activeListTab.label }} <span class="chl-count">{{ listOrders.length }}</span></h2>
            </div>
        </div>

        <div class="chl-tabs-row">
            <div class="ch-tabs">
                <button
                    v-for="tab in LIST_TABS"
                    :key="tab.key"
                    type="button"
                    class="ch-tab"
                    :class="{ active: listTab === tab.key }"
                    @click="listTab = tab.key"
                >
                    {{ tab.label }}
                </button>
            </div>
            <p class="chl-hint">Click any row to open the full order</p>
        </div>

        <div class="ch-controls-row">
            <div class="chl-search">
                <span class="ic">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.35-4.35" /></svg>
                </span>
                <input type="text" v-model="listSearch" placeholder="Search order no., customer…" />
            </div>
            <div class="ch-controls-right">
                <button type="button" class="btn-outline" @click="showListFilter = !showListFilter">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4" /></svg>
                    Filter
                    <span v-if="activeCourier !== 'all'" class="ch-filter-active-dot" aria-hidden="true"></span>
                </button>
                <span v-if="activeCourier !== 'all' && !showListFilter" class="ch-filter-active-label">
                    Courier: {{ courierTabs.find((t) => t.key === activeCourier)?.label || activeCourier }}
                    <button type="button" class="ch-filter-active-clear" aria-label="Clear courier filter" @click="activeCourier = 'all'">×</button>
                </span>
                <button type="button" class="btn-outline" :disabled="!listOrders.length" @click="exportListCsv">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></svg>
                    Export
                </button>
            </div>
        </div>

        <div v-if="showListFilter" class="chl-filter-panel">
            <span class="field-label">Courier</span>
            <button
                v-for="tab in courierTabs"
                :key="tab.key"
                type="button"
                class="ch-tab"
                :class="{ active: activeCourier === tab.key }"
                @click="activeCourier = tab.key"
            >
                {{ tab.label }} <span class="ch-tab-count">{{ tab.count }}</span>
            </button>
        </div>

        <div class="card" style="overflow-x: auto">
            <table class="sales-table chl-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Courier</th>
                        <th>Value</th>
                        <th>Stage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in pagedListOrders" :key="o.id" class="ch-history-row" @click="openDetails(o.id)">
                        <td class="order-id">{{ o.id }}</td>
                        <td>
                            <span class="chl-row">
                                <span class="chl-avatar">{{ customerInitials(o.customer) }}</span>
                                {{ o.customer || 'Unknown buyer' }}
                            </span>
                        </td>
                        <td>{{ (o.items || []).length }} item{{ (o.items || []).length === 1 ? '' : 's' }}</td>
                        <td>{{ o.shippingCarrier || '—' }}</td>
                        <td class="num">{{ formatCurrency(o.total) }}</td>
                        <td><span class="badge" :class="statusBadgeClass(o.status)">{{ o.status }}</span></td>
                    </tr>
                    <tr v-if="!pagedListOrders.length">
                        <td colspan="6" class="ch-empty-note" style="text-align: center; padding: 2rem 0">
                            No shipments {{ listSearch ? 'match your search' : 'in this stage yet' }}.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="listOrders.length" class="chl-pagination">
            <span>Showing {{ listRangeLabel }} of {{ listOrders.length }} shipments</span>
            <div class="chl-page-nav">
                <button type="button" class="chl-page-btn" :disabled="listPage <= 1" aria-label="Previous page" @click="listPage--">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 5 8 12l7 7" /></svg>
                </button>
                <button
                    v-for="p in totalListPages"
                    :key="p"
                    type="button"
                    class="chl-page-btn"
                    :class="{ active: p === listPage }"
                    @click="listPage = p"
                >
                    {{ p }}
                </button>
                <button type="button" class="chl-page-btn" :disabled="listPage >= totalListPages" aria-label="Next page" @click="listPage++">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 5l7 7-7 7" /></svg>
                </button>
            </div>
        </div>
    </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useCouriers } from '../composables/useCouriers';
import { useOrders } from '../composables/useOrders';

const {
    orders,
    isLoadingOrders,
    loadOrders,
    statusBadgeClass,
    formatCurrency,
    shipOrder,
} = useOrders();

const { couriers, isLoadingCouriers, loadCouriers } = useCouriers();

const courierSelectPlaceholder = computed(() => {
    if (isLoadingCouriers.value) {
return 'Loading couriers…';
}

    if (!couriers.value.length) {
return 'No couriers registered yet';
}

    return 'Select a courier…';
});

onMounted(() => {
    if (!orders.value.length) {
        loadOrders();
    }

    loadCouriers();
});

function refresh() {
    loadOrders();
}

// Same 30s poll rhythm as Orders.vue/Dashboard.vue — without it a
// seller who leaves this board open never sees a new "Ready for
// Pickup" order land, or another one move into "In Transit".
const HANDOVER_POLL_MS = 30 * 1000;
let handoverPollTimer = null;

onMounted(() => {
    handoverPollTimer = setInterval(refresh, HANDOVER_POLL_MS);
});

onBeforeUnmount(() => {
    clearInterval(handoverPollTimer);
});

// ---- board <-> shipments list (see the SHIPMENTS LIST template block
// for why: a board column's "View N more" opens this instead of
// growing the column in place) ----
const viewMode = ref('board'); // 'board' | 'list'

// Processing/Packed aren't real tabs here either, same reasoning as the
// board above (see file header comment) — this list mirrors exactly
// what the 2-column board shows, just as a full filterable/paginated
// drill-down instead of a capped 3-card column.
const LIST_TABS = [
    { key: 'all', label: 'All', statuses: ['Ready for Pickup', 'In Transit'] },
    { key: 'ready', label: 'Ready for Pickup', statuses: ['Ready for Pickup'] },
    { key: 'transit', label: 'In Transit', statuses: ['In Transit'] },
];

const listTab = ref('all');
const listSearch = ref('');
const showListFilter = ref(false);

const activeListTab = computed(() => LIST_TABS.find((t) => t.key === listTab.value) || LIST_TABS[0]);

function openList(status) {
    const tab = LIST_TABS.find((t) => t.statuses.length === 1 && t.statuses[0] === status);

    listTab.value = tab ? tab.key : 'all';
    listSearch.value = '';
    viewMode.value = 'list';
}

function closeList() {
    viewMode.value = 'board';
}

// Real courier filter (activeCourier) is shared with the board above —
// switching between board/list keeps the same filter active instead of
// resetting it, same as Orders.vue keeps one `filters` object across
// its own board/list toggle.
const listOrders = computed(() => {
    const q = listSearch.value.trim().toLowerCase();

    return orders.value
        .filter((o) => activeListTab.value.statuses.includes(o.status))
        .filter((o) => matchesCourier(o))
        .filter((o) => {
            if (!q) {
return true;
}

            return (
                o.id.toLowerCase().includes(q) ||
                (o.customer || '').toLowerCase().includes(q) ||
                (o.shippingCarrier || '').toLowerCase().includes(q)
            );
        })
        .sort(
            (a, b) =>
                new Date(b.updatedAt || b.placedAt || 0) - new Date(a.updatedAt || a.placedAt || 0),
        );
});

const LIST_PAGE_SIZE = 6;
const listPage = ref(1);

watch([listTab, listSearch], () => {
    listPage.value = 1;
});

const totalListPages = computed(() => Math.max(1, Math.ceil(listOrders.value.length / LIST_PAGE_SIZE)));

const pagedListOrders = computed(() => {
    const start = (listPage.value - 1) * LIST_PAGE_SIZE;

    return listOrders.value.slice(start, start + LIST_PAGE_SIZE);
});

const listRangeLabel = computed(() => {
    if (!listOrders.value.length) {
return '0';
}

    const start = (listPage.value - 1) * LIST_PAGE_SIZE + 1;
    const end = Math.min(listPage.value * LIST_PAGE_SIZE, listOrders.value.length);

    return `${start} to ${end}`;
});

function customerInitials(name) {
    if (!name) {
return '?';
}

    const parts = name.trim().split(/\s+/);

    return parts.length === 1
        ? parts[0].slice(0, 2).toUpperCase()
        : (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

// Client-side CSV of exactly what's currently filtered (all matching
// pages, not just the one on screen) — no server export endpoint for
// this exists, so this builds one from the same real data already
// loaded rather than adding a fake button with nothing behind it.
function exportListCsv() {
    const rows = [
        ['Order', 'Customer', 'Items', 'Courier', 'Value', 'Stage'],
        ...listOrders.value.map((o) => [
            o.id,
            o.customer || '',
            String((o.items || []).length),
            o.shippingCarrier || '',
            String(o.total ?? 0),
            o.status,
        ]),
    ];

    const csv = rows
        .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','))
        .join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');

    a.href = url;
    a.download = `buytheway-${listTab.value}-shipments-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

function openDetails(id) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'orderDetails', orderId: id },
        }),
    );
}

const todayLabel = new Date().toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
});

// ---- real order buckets — one per real status this page actually acts
// on (see file header comment for why Processing/Packed aren't here) ----
const readyOrders = computed(() => orders.value.filter((o) => o.status === 'Ready for Pickup'));
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

// ---- courier filter — derived from real shipping_carrier values,
// which only exist once an order has actually shipped. Shared with the
// shipments list above (see listOrders), so switching between board
// and list keeps the same courier filter active instead of resetting. ----
const activeCourier = ref('all');

watch(activeCourier, () => {
    listPage.value = 1;
});

const courierTabs = computed(() => {
    const pool = [...readyOrders.value, ...inTransitOrders.value, ...deliveredThisWeek.value];
    const counts = new Map();
    let unassigned = 0;

    pool.forEach((o) => {
        const name = (o.shippingCarrier || '').trim();

        if (!name) {
            unassigned += 1;

            return;
        }

        counts.set(name, (counts.get(name) || 0) + 1);
    });

    const tabs = [{ key: 'all', label: 'All', count: pool.length }];

    for (const [name, count] of counts) {
        tabs.push({ key: name, label: name, count });
    }

    if (unassigned) {
        tabs.push({ key: 'unassigned', label: 'Unassigned', count: unassigned });
    }

    return tabs;
});

function matchesCourier(o) {
    if (activeCourier.value === 'all') {
return true;
}

    const name = (o.shippingCarrier || '').trim();

    return activeCourier.value === 'unassigned' ? !name : name === activeCourier.value;
}

const boardSearch = ref('');

function matchesSearch(o) {
    const q = boardSearch.value.trim().toLowerCase();

    if (!q) {
return true;
}

    return (
        o.id.toLowerCase().includes(q) ||
        (o.customer || '').toLowerCase().includes(q) ||
        (o.items || []).some((it) => (it.name || '').toLowerCase().includes(q))
    );
}

const boardReady = computed(() => readyOrders.value.filter((o) => matchesCourier(o) && matchesSearch(o)));
const boardTransit = computed(() => inTransitOrders.value.filter((o) => matchesCourier(o) && matchesSearch(o)));

// Each column shows at most 3 cards. "View N more" doesn't reveal them
// in place — it opens the SHIPMENTS LIST above instead, matching the
// reference and Orders.vue's own kanban (see its "View N more" comment)
// — a real drill-down instead of growing this small board indefinitely.
const COLUMN_INITIAL = 3;

function visibleCards(list) {
    return list.slice(0, COLUMN_INITIAL);
}

// ---- courier badge (initials + a deterministic color from real text —
// couriers are free text here, not a fixed enum, so the color is a
// styling aid, not a claim about the courier itself) ----
const BADGE_PALETTE = ['blue', 'warn', 'bad', 'rose'];

function courierInitials(name) {
    if (!name) {
return 'N/A';
}

    const words = name.trim().split(/\s+/).filter(Boolean);

    if (words.length === 1) {
return words[0].slice(0, 3).toUpperCase();
}

    return words.map((w) => w[0]).join('').slice(0, 3).toUpperCase();
}

function courierBadgeClass(name) {
    if (!name) {
return 'neutral';
}

    let hash = 0;

    for (let i = 0; i < name.length; i += 1) {
hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
}

    return BADGE_PALETTE[hash % BADGE_PALETTE.length];
}

// busyId tracks the one real action this page performs (Confirm
// Pickup) — see confirmPickup() below. There's no per-card forward
// action anymore now that Processing -> Packed -> Ready for Pickup all
// happen inside Prepare Orders' own action modal.
const busyId = ref('');

// The Order select makes explicit which real order the courier/service
// fields are about to be applied to — "Select for Pickup" on a card
// just fills it in; the select itself is always the source of truth.
const selectedOrderId = ref('');
const assignCarrier = ref('');
const assignService = ref('Standard');
const pickupError = ref('');
const pickupSuccess = ref('');

// Drop the selection if that order leaves "Ready for Pickup" for any
// reason (already shipped some other way, cancelled, etc.).
watch(readyOrders, (list) => {
    if (selectedOrderId.value && !list.some((o) => o.id === selectedOrderId.value)) {
        selectedOrderId.value = '';
    }
});

// Prepare Orders already lets the seller pick a courier/service while
// packing (it rides along on the Packed -> Ready for Pickup hops), so
// prefill from that instead of asking again — the seller can still
// change it here, since this is the real moment it's confirmed.
watch(selectedOrderId, (id) => {
    const o = readyOrders.value.find((order) => order.id === id);

    assignCarrier.value = o?.shippingCarrier || '';
    assignService.value = o?.shippingService || 'Standard';
});

const canConfirmPickup = computed(
    () => !!selectedOrderId.value && assignCarrier.value.trim().length > 0 && !busyId.value,
);
const pickupDisabledReason = computed(() => {
    if (busyId.value) {
return '';
}

    if (!selectedOrderId.value) {
return 'Select an order above first.';
}

    if (!assignCarrier.value.trim()) {
return 'Choose the courier/carrier.';
}

    return '';
});

async function confirmPickup() {
    if (!canConfirmPickup.value) {
return;
}

    const id = selectedOrderId.value;

    busyId.value = id;
    pickupError.value = '';
    pickupSuccess.value = '';

    // No tracking_number here — the server generates a real one itself
    // the moment this order actually becomes 'In Transit' (see
    // SellerOrderController::generateTrackingNumber), rather than
    // trusting whatever a seller might type into a text field.
    const updated = await shipOrder(id, {
        shipping_carrier: assignCarrier.value.trim(),
        shipping_service: assignService.value || null,
    });

    busyId.value = '';

    if (updated) {
        pickupSuccess.value = `${id} picked up — now In Transit. Tracking #${updated.trackingNumber}.`;
        selectedOrderId.value = '';

        return;
    }

    pickupError.value = `Couldn't confirm pickup for ${id} — it may have changed status already. Refresh and try again.`;
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

    if (!q) {
return shippedOrders.value;
}

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
    if (!iso) {
return '—';
}

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

<style scoped>
/* ============================================================
   COURIER HANDOVER — dark reskin (matches Orders / Dashboard /
   Inventory / Order Preparation / Order Details). Scoped to this
   component, so targeting shared class names like .card/.field-input/
   .btn-outline/.sales-table here only ever affects what this page
   itself renders — every other page's light-mode originals keep
   using the same class names untouched.
   ============================================================ */
.courier-page {
    --ch-surface: #161b17;
    --ch-surface-2: #1d231e;
    --ch-border: rgba(255, 255, 255, 0.08);
    --ch-border-soft: rgba(255, 255, 255, 0.06);
    --ch-ink-900: #f2f4f1;
    --ch-ink-700: #ced4cd;
    --ch-ink-500: #97a099;
    --ch-ink-400: #6d766e;
    color: var(--ch-ink-900);
}

.courier-page .card {
    background: var(--ch-surface);
    border: 1px solid var(--ch-border);
}
.courier-page .prep-title {
    color: var(--ch-ink-900);
}
.courier-page .prep-breadcrumb {
    color: var(--ch-ink-500);
}
.courier-page .btn-outline {
    background: var(--ch-surface-2);
    border-color: var(--ch-border);
    color: var(--ch-ink-900);
}
.courier-page .btn-outline:hover:not(:disabled) {
    background: var(--ch-surface);
}
.courier-page .field-input {
    background: var(--ch-surface-2);
    border-color: var(--ch-border);
    color: var(--ch-ink-900);
}
.courier-page .field-label {
    color: var(--ch-ink-500);
}
.courier-page .field-hint {
    color: var(--ch-ink-500);
}
.courier-page .prep-card-head h3 {
    color: var(--ch-ink-900);
}
.courier-page .prep-card-sub {
    color: var(--ch-ink-500);
}
.courier-page .ch-empty-note {
    color: var(--ch-ink-500);
}
.courier-page .ch-success-note {
    color: #5eead4;
}
.courier-page .ch-pagination {
    color: var(--ch-ink-500);
}

/* ---- stat bar ---- */
.ch-stat-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    background: var(--ch-surface);
    border: 1px solid var(--ch-border);
    border-radius: 1rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    margin-bottom: 1.2rem;
    overflow: hidden;
}
.ch-stat-seg {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 1rem 1.25rem;
    border-right: 1px solid var(--ch-border-soft);
}
.ch-stat-seg:last-child {
    border-right: none;
}
.ch-stat-ic {
    width: 2.2rem;
    height: 2.2rem;
    border-radius: 0.65rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ch-stat-ic.neutral {
    background: rgba(255, 255, 255, 0.06);
    color: var(--ch-ink-500);
}
.ch-stat-ic.blue {
    background: rgba(18, 63, 143, 0.28);
    color: #9dc2ef;
}
.ch-stat-ic.good {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
}
.ch-stat-v {
    font-size: 1.2rem;
    font-weight: 800;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.ch-stat-l {
    font-size: 0.7rem;
    color: var(--ch-ink-500);
    font-weight: 600;
    margin-top: 0.15rem;
}

@media (max-width: 1180px) {
    .ch-stat-bar {
        grid-template-columns: repeat(2, 1fr);
    }
    .ch-stat-seg:nth-child(2) {
        border-right: none;
    }
}

/* ---- courier filter + date + search ----
   .ch-controls-right (date + search) never wraps and never shrinks —
   it's always one intact unit sitting beside the tabs, not stacked
   underneath/within them. If the courier tab list ever gets too long
   to fit alongside it, the TABS scroll horizontally instead of pushing
   the date/search block onto its own line. */
.ch-controls-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.9rem;
}
.ch-tabs {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    flex: 1 1 auto;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: none;
}
.ch-tabs::-webkit-scrollbar {
    display: none;
}
.ch-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.42rem 0.9rem;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    border: none;
    background: transparent;
    color: var(--ch-ink-500);
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
}
.ch-tab:hover {
    color: var(--ch-ink-900);
}
.ch-tab.active {
    background: var(--ch-ink-900);
    color: var(--ch-surface);
}
.ch-tab-count {
    font-size: 0.7rem;
    opacity: 0.75;
}
.ch-controls-right {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-shrink: 0;
}
.ch-date-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.9rem;
    border-radius: 999px;
    border: 1px solid var(--ch-border);
    background: var(--ch-surface-2);
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--ch-ink-700);
    white-space: nowrap;
}

/* Without this, the "Filter" button looked identical whether a courier
   was selected or not — collapsing the panel after picking one left no
   visible sign the shipment list was still narrowed. */
.ch-filter-active-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--teal-500);
    display: inline-block;
    margin-left: 0.3rem;
}
.ch-filter-active-label {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.18);
    color: #5eead4;
    font-size: 0.76rem;
    font-weight: 700;
    white-space: nowrap;
}
.ch-filter-active-clear {
    border: none;
    background: none;
    color: inherit;
    font-size: 0.9rem;
    line-height: 1;
    cursor: pointer;
    padding: 0;
}
.ch-search {
    max-width: 14rem;
}

/* ---- confirm-pickup toolbar ---- */
.ch-assign-bar {
    display: flex;
    align-items: flex-end;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding: 0.85rem 1.1rem;
    margin-bottom: 1.2rem;
}
.ch-assign-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    flex: 1;
    min-width: 9rem;
}
.ch-assign-bar .field-input {
    padding: 0.5rem 0.75rem;
}
.ch-assign-bar .btn-primary {
    flex-shrink: 0;
}

/* ---- kanban board ---- */
.ch2-board {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    align-items: start;
    margin-bottom: 1.3rem;
}
.ch2-col {
    background: var(--ch-surface-2);
    border: 1px solid var(--ch-border-soft);
    border-radius: 1rem;
    padding: 0.85rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-width: 0;
}
.ch2-col-head {
    padding: 0.55rem 0.5rem;
    border-radius: 999px;
    color: #fff;
    font-size: 0.74rem;
    font-weight: 800;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
}
.ch2-col-head.blue {
    background: #4f6bb0;
}
.ch2-col-head.good {
    background: #1f9d5a;
}
.ch2-col-count {
    font-size: 0.68rem;
    font-weight: 800;
    background: rgba(255, 255, 255, 0.22);
    padding: 0.05rem 0.45rem;
    border-radius: 999px;
}
.ch2-col-body {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    max-height: 34rem;
    overflow-y: auto;
    scrollbar-width: none;
}
.ch2-col-body::-webkit-scrollbar {
    display: none;
}
.ch2-empty {
    font-size: 0.76rem;
    color: var(--ch-ink-400);
    text-align: center;
    padding: 1rem 0;
    margin: 0;
}
.ch2-more {
    margin-top: 0.2rem;
    padding: 0.4rem 0;
    background: none;
    border: none;
    color: var(--ch-ink-500);
    font-size: 0.76rem;
    font-weight: 700;
    text-align: center;
    cursor: pointer;
    width: 100%;
}
.ch2-more:hover {
    color: #5eead4;
}

.ch2-card {
    background: var(--ch-surface);
    border: 1px solid var(--ch-border);
    border-radius: 1rem;
    padding: 0.9rem 1rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.ch2-card.is-targeted {
    border-color: #5eead4;
    box-shadow: 0 0 0 1px #5eead4;
}
.ch2-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.66rem;
    color: var(--ch-ink-400);
    font-weight: 700;
}
.ch2-view-link {
    font-size: 0.7rem;
    font-weight: 700;
    color: #5eead4;
    text-decoration: underline;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
}
.ch2-card-main {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}
.ch2-courier-badge {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.6rem;
    color: #fff;
    font-size: 0.56rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    text-align: center;
    line-height: 1.05;
}
.ch2-courier-badge.neutral {
    background: var(--ch-ink-400);
}
.ch2-courier-badge.blue {
    background: #4f6bb0;
}
.ch2-courier-badge.warn {
    background: #b5791b;
}
.ch2-courier-badge.bad {
    background: #c8433d;
}
.ch2-courier-badge.rose {
    background: #b5476b;
}
.ch2-card-info {
    flex: 1;
    min-width: 0;
}
.ch2-order-id {
    font-size: 0.83rem;
    font-weight: 800;
    color: #9dc2ef;
    margin: 0;
}
.ch2-cust {
    font-size: 0.73rem;
    color: var(--ch-ink-500);
    margin: 0.1rem 0 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ch2-price {
    font-size: 0.86rem;
    font-weight: 800;
    flex-shrink: 0;
}
.ch2-btn-solid {
    width: 100%;
    padding: 0.5rem;
    border-radius: 0.6rem;
    border: none;
    background: var(--ch-ink-900);
    color: var(--ch-surface);
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
}
.ch2-btn-solid:hover:not(:disabled) {
    background: #5eead4;
}
.ch2-btn-solid:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.ch2-btn-solid.selected {
    background: #5eead4;
    color: #0b1f1a;
}
.ch2-note {
    font-size: 0.74rem;
    color: var(--ch-ink-500);
    text-align: center;
    font-weight: 600;
    padding: 0.3rem 0;
    margin: 0;
}

@media (max-width: 720px) {
    .ch2-board {
        grid-template-columns: 1fr;
    }
    /* Too narrow for tabs + date + search to all fit on one row even
       with the tabs scrolling — stack date+search (still together,
       just as their own row) below the tabs instead of squeezing. */
    .ch-controls-row {
        flex-wrap: wrap;
    }
    .ch-controls-right {
        width: 100%;
    }
    .ch-search {
        max-width: none;
        flex: 1;
    }
}

/* ---- shipment history table ---- */
.courier-page .sales-table thead th {
    color: var(--ch-ink-400);
    background: var(--ch-surface-2);
    border-bottom-color: var(--ch-border);
}
.courier-page .sales-table tbody td {
    border-top-color: var(--ch-border-soft);
    color: var(--ch-ink-900);
}
.courier-page .sales-table .order-id {
    color: #9dc2ef;
}
.courier-page .ch-history-row {
    cursor: pointer;
}
.courier-page .ch-history-row:hover td {
    background: var(--ch-surface-2);
}

/* Status badges (statusBadgeClass()) as translucent chips instead of
   solid light pastel rectangles — same treatment as Order Details. */
.courier-page .badge-emerald {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
    border-color: rgba(15, 118, 110, 0.35);
}
.courier-page .badge-sky {
    background: rgba(18, 63, 143, 0.28);
    color: #9dc2ef;
    border-color: rgba(18, 63, 143, 0.4);
}
.courier-page .badge-amber {
    background: rgba(251, 191, 125, 0.16);
    color: #fbbf7d;
    border: 1px solid rgba(251, 191, 125, 0.3);
}
.courier-page .badge-red {
    background: rgba(247, 164, 159, 0.16);
    color: #f7a49f;
    border: 1px solid rgba(247, 164, 159, 0.3);
}
.courier-page .badge-slate {
    background: var(--ch-surface-2);
    color: var(--ch-ink-500);
    border: 1px solid var(--ch-border);
}

/* ---- workflow stepper ---- */
.ch-stepper-title {
    font-size: 0.85rem;
    font-weight: 800;
    color: var(--ch-ink-900);
    margin: 0 0 1.2rem;
}
.courier-page .ch-stepper-track {
    background: var(--ch-border);
}
.courier-page .ch-stepper-dot {
    background: var(--ch-surface-2);
    color: var(--ch-ink-500);
}
.courier-page .ch-stepper-dot.active {
    background: rgba(15, 118, 110, 0.35);
    color: #5eead4;
}
.courier-page .ch-stepper-label {
    color: var(--ch-ink-500);
}

/* ---- bottom stack (history, then workflow) ---- */
.ch-grid-bottom {
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
}

/* ---- shipments list (adapted "View more" destination) ---- */
.chl-topbar {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 1.1rem;
}
.chl-back-btn {
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 999px;
    border: 1px solid var(--ch-border);
    background: var(--ch-surface-2);
    color: var(--ch-ink-700);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    margin-top: 0.1rem;
}
.chl-back-btn:hover {
    background: var(--ch-surface);
    color: var(--ch-ink-900);
}
.chl-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.6rem;
    height: 1.6rem;
    padding: 0 0.4rem;
    margin-left: 0.5rem;
    border-radius: 999px;
    background: var(--ch-surface-2);
    border: 1px solid var(--ch-border);
    color: var(--ch-ink-500);
    font-size: 0.78rem;
    font-weight: 700;
    vertical-align: middle;
}

.chl-tabs-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 0.9rem;
}
.chl-hint {
    font-size: 0.76rem;
    color: var(--ch-ink-500);
    margin: 0;
    white-space: nowrap;
}

.chl-search {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 18rem;
    flex: 1;
}
.chl-search .ic {
    position: absolute;
    left: 0.7rem;
    color: var(--ch-ink-500);
    pointer-events: none;
    display: flex;
}
.chl-search input {
    width: 100%;
    padding: 0.55rem 0.8rem 0.55rem 2.1rem;
    border-radius: 0.6rem;
    border: 1px solid var(--ch-border);
    background: var(--ch-surface-2);
    color: var(--ch-ink-900);
    font-size: 0.82rem;
}

.chl-filter-panel {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding: 0.7rem 0.9rem;
    margin-bottom: 1rem;
    border-radius: 0.75rem;
    background: var(--ch-surface-2);
    border: 1px solid var(--ch-border-soft);
}
.chl-filter-panel .field-label {
    margin-right: 0.25rem;
}

.chl-table td,
.chl-table th {
    white-space: nowrap;
}
.chl-table .num {
    font-weight: 700;
}
.chl-row {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
}
.chl-avatar {
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    background: rgba(181, 71, 92, 0.22);
    color: #e08a9a;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    font-weight: 800;
    flex-shrink: 0;
}

.chl-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1.1rem;
    font-size: 0.78rem;
    color: var(--ch-ink-500);
}
.chl-page-nav {
    display: flex;
    gap: 0.4rem;
}
.chl-page-btn {
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 0.5rem;
    border: 1px solid var(--ch-border);
    background: var(--ch-surface-2);
    color: var(--ch-ink-700);
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.chl-page-btn:disabled {
    opacity: 0.4;
    cursor: default;
}
.chl-page-btn.active {
    background: var(--ch-ink-900);
    border-color: var(--ch-ink-900);
    color: var(--ch-surface);
}
</style>
