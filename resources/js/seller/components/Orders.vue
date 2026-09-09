<!-- resources/js/seller/components/Orders.vue -->
<!--
  Seller Order Management.

  Real data via useOrders() -> Laravel SellerOrderController. Summary
  cards + filter bar + a paginated table (desktop) / card list (mobile) +
  a live detail preview drawer. Every status action is workflow-aware:
  the buttons come straight from order.nextStatuses / order.canCancel
  (Order::ALLOWED_TRANSITIONS on the server), so an invalid transition
  can't be offered, and the API still rejects one if forced.
-->
<template>
    <div class="order-page order-page--v2">
        <!-- New-order alert -->
        <div v-if="newCount > 0 && viewMode === 'board'" class="order-alert" role="status">
            <div class="order-alert-left">
                <span class="order-alert-dot" aria-hidden="true"></span>
                <p>
                    {{ newCount }} new order{{ newCount === 1 ? '' : 's' }}
                    awaiting your review.
                </p>
            </div>
            <button
                type="button"
                class="btn-primary btn-sm"
                @click="filters.status = 'New'"
            >
                Review new
            </button>
        </div>

        <!-- ============================================================
             ORDERS LIST — the full, filterable, paginated list a kanban
             column's "View N more" opens (real click-through, matching
             the reference's view-orders-list). Reads the same already-
             loaded `orders` array as the board — no extra fetch, since
             loadOrders() already returns the seller's full matching list.
             ============================================================ -->
        <template v-if="viewMode === 'list'">
            <div class="ol-topbar">
                <button
                    type="button"
                    class="ol-back-btn"
                    aria-label="Back to order board"
                    @click="closeList"
                >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <path d="M15 5 8 12l7 7" />
                    </svg>
                </button>
                <div>
                    <p class="ol-breadcrumb">Orders &gt; {{ listTitle }}</p>
                    <h3 class="ol-title">
                        {{ listTitle }} Orders
                        <span class="ol-count-badge num">{{ listOrders.length }}</span>
                    </h3>
                </div>
            </div>

            <div class="ol-tabs">
                <button
                    v-for="tab in LIST_TABS"
                    :key="tab.key"
                    type="button"
                    class="ol-tab"
                    :class="{ active: listStageKey === tab.key }"
                    @click="selectListTab(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <div class="card">
                <div v-if="!listOrders.length" class="order-state order-state--empty">
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <rect x="4" y="4" width="16" height="17" rx="2" />
                        <path d="M9 2h6v3H9zM8 10h8M8 14h8M8 18h5" />
                    </svg>
                    <p class="order-state-title">No orders in this view</p>
                </div>

                <template v-else>
                    <div class="order-table-wrap">
                        <table class="order-table--full">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Date</th>
                                    <th class="text-right">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="o in pagedListOrders"
                                    :key="o.id"
                                    class="order-row"
                                    @click="openDetails(o.id)"
                                >
                                    <td>
                                        <button
                                            type="button"
                                            class="order-id-link"
                                            @click.stop="openDetails(o.id)"
                                        >
                                            {{ o.id }}
                                        </button>
                                    </td>
                                    <td class="customer">{{ o.customer }}</td>
                                    <td class="order-products">{{ orderItemsSummary(o) }}</td>
                                    <td class="order-date">{{ o.date }}</td>
                                    <td class="amount text-right">{{ formatCurrency(o.total) }}</td>
                                    <td><OrderStatusBadge :status="o.status" size="sm" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="ol-pagination">
                        <span class="ol-page-info">{{ listRangeLabel }}</span>
                        <div class="ol-page-nav">
                            <button
                                type="button"
                                class="ol-page-btn"
                                aria-label="Previous page"
                                :disabled="listPage === 1"
                                @click="goToListPage(listPage - 1)"
                            >
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <path d="M15 5 8 12l7 7" />
                                </svg>
                            </button>
                            <span class="ol-page-current num">{{ listPage }} / {{ listLastPage }}</span>
                            <button
                                type="button"
                                class="ol-page-btn"
                                aria-label="Next page"
                                :disabled="listPage === listLastPage"
                                @click="goToListPage(listPage + 1)"
                            >
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <path d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <template v-else>
        <div class="ord-stats">
            <div class="card ord-stat-card">
                <div class="ord-stat-head">
                    <div class="ord-stat-title">
                        <span class="ord-stat-ic">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="17" rx="2" /><path d="M9 2h6v3H9zM8 10h8M8 14h8M8 18h5" /></svg>
                        </span>
                        Orders Overview
                    </div>
                </div>
                <div class="ord-big-num num">
                    {{ activePipelineCount }}<span class="unit">orders in the active pipeline</span>
                </div>
                <p class="ord-compare">
                    {{ totalLabel }}<template v-if="hasActiveFilters"> matching your filters</template>
                </p>
                <div class="ord-legend">
                    <span v-for="g in kanbanColumns" :key="g.key" class="ord-legend-item">
                        <span class="dot" :style="{ background: g.color }"></span>{{ g.label }} <strong class="num">{{ g.count }}</strong>
                    </span>
                </div>
                <div class="ord-bar">
                    <span
                        v-for="g in kanbanColumns"
                        :key="g.key"
                        :style="{ width: (total ? (g.count / total) * 100 : 0) + '%', background: g.color }"
                    ></span>
                </div>
            </div>

            <div class="card ord-stat-card">
                <div class="ord-stat-head">
                    <div class="ord-stat-title">
                        <span class="ord-stat-ic blue">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5.5c0-1.9-2.2-3.5-5-3.5s-5 1.6-5 3.5S9.2 9 12 9s5 1.6 5 3.5-2.2 3.5-5 3.5-5-1.6-5-3.5" /></svg>
                        </span>
                        Revenue
                    </div>
                </div>
                <div v-if="paidRevenue.total === 0" class="ord-revenue-empty">
                    No paid orders yet.
                </div>
                <div v-else class="ord-gauge-row">
                    <div class="ord-donut-wrap">
                        <svg viewBox="0 0 36 36" class="ord-donut" style="transform: rotate(-90deg)">
                            <circle cx="18" cy="18" r="15.9" fill="transparent" style="stroke: var(--ord-border)" stroke-width="4" />
                            <circle
                                v-for="seg in revenueDonutSegments"
                                :key="seg.key"
                                cx="18"
                                cy="18"
                                r="15.9"
                                fill="transparent"
                                :stroke="seg.color"
                                stroke-width="4"
                                stroke-linecap="round"
                                :stroke-dasharray="`${seg.pct} ${100 - seg.pct}`"
                                :stroke-dashoffset="seg.dashoffset"
                            ></circle>
                        </svg>
                    </div>
                    <div class="ord-gauge-legend">
                        <span class="ord-legend-item"><span class="dot" style="background: #6fa3e0"></span>Online <strong class="num">{{ formatCurrency(paidRevenue.online) }}</strong></span>
                        <span class="ord-legend-item"><span class="dot" style="background: #fbbf7d"></span>Cash on Delivery <strong class="num">{{ formatCurrency(paidRevenue.cod) }}</strong></span>
                    </div>
                    <div class="ord-gauge-total">
                        <div class="g-num num">{{ formatCurrency(paidRevenue.total) }}</div>
                    </div>
                </div>
                <p class="ord-gauge-caption">Collected from paid orders, split by how the buyer paid.</p>
            </div>
        </div>

        <section class="order-results" aria-label="Order pipeline board">
                <div class="order-results-head">
                    <div class="board-title">
                        <h3>Live Order Pipeline</h3>
                        <span class="order-filter-active-pill" v-if="hasActiveFilters">Filtered</span>
                    </div>
                    <span class="order-results-count">{{ totalLabel }}</span>
                </div>

                <!-- Error -->
                <div
                    v-if="loadError && !isLoadingOrders"
                    class="order-state order-state--error"
                >
                    <p class="order-state-title">Couldn't load your orders</p>
                    <p class="order-state-text">{{ loadError }}</p>
                    <button type="button" class="btn-outline" @click="reload">
                        Try again
                    </button>
                </div>

                <!-- Skeleton (first load) -->
                <div
                    v-else-if="isLoadingOrders && !orders.length"
                    class="order-skel-list"
                    aria-hidden="true"
                >
                    <div v-for="n in 6" :key="n" class="order-skel-row">
                        <span class="order-skel-bar" style="width: 20%"></span>
                        <span class="order-skel-bar" style="width: 26%"></span>
                        <span class="order-skel-bar" style="width: 14%"></span>
                        <span class="order-skel-bar" style="width: 12%"></span>
                        <span class="order-skel-bar order-skel-pill" style="width: 5rem"></span>
                    </div>
                </div>

                <!-- Empty -->
                <div
                    v-else-if="!orders.length"
                    class="order-state order-state--empty"
                >
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <rect x="4" y="4" width="16" height="17" rx="2" />
                        <path d="M9 2h6v3H9zM8 10h8M8 14h8M8 18h5" />
                    </svg>
                    <p class="order-state-title">No orders match these filters</p>
                    <p class="order-state-text">
                        Try widening the date range or clearing a filter.
                    </p>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="btn-outline"
                        @click="resetFilters"
                    >
                        Reset filters
                    </button>
                </div>

                <template v-else>
                    <!-- ============================================================
                     LIVE ORDER PIPELINE — kanban board grouped by real status.
                     Order::STATUSES has 9 values; grouped into 5 stage columns
                     so the board stays scannable (New, Confirmed+Processing+
                     Packed+Ready for Pickup, In Transit, Delivered, Cancelled+
                     Rejected) — each card still shows its own precise status
                     via OrderStatusBadge, so no precision is lost. Confirmed
                     sits in the Processing column, not New: once a seller has
                     accepted an order it's already moving toward being
                     packed, which is exactly what Prepare Orders now expects
                     (see OrderDetails.vue's redirect once status hits
                     Processing) — New is reserved for orders still awaiting
                     that accept/reject decision.

                     No drag-and-drop: Order::ALLOWED_TRANSITIONS is strict
                     per current status (e.g. New can only go to Confirmed/
                     Processing/Cancelled/Rejected, never straight to
                     Delivered), so "drop anywhere in a column" can't map
                     cleanly onto real transition rules. The existing
                     workflow-aware primary-action button on each card is
                     the real, validated way to change status; clicking the
                     rest of a card opens the full Order Details page
                     (which has its own Cancel/Reject actions).
                     ============================================================ -->
                    <div class="kanban">
                        <div v-for="col in kanbanColumns" :key="col.key" class="kanban-col">
                            <div class="kanban-col-head">
                                <span class="dot" :style="{ background: col.color }"></span>
                                <span class="label">{{ col.label }}</span>
                                <span class="kanban-count num">{{ col.count }}</span>
                            </div>
                            <div class="kanban-col-body">
                                <OrderCard
                                    v-for="order in visibleCards(col)"
                                    :key="order.id"
                                    :order="order"
                                    @select="openDetails(order.id)"
                                    @open="openDetails(order.id)"
                                />
                                <p v-if="!col.cards.length" class="kanban-empty">
                                    No orders here.
                                </p>
                            </div>
                            <button
                                v-if="col.count > visibleCards(col).length"
                                type="button"
                                class="kanban-more"
                                @click="openList(col.key)"
                            >
                                View {{ col.count - visibleCards(col).length }} more →
                            </button>
                        </div>
                    </div>
                </template>
            </section>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useOrders } from '../composables/useOrders';
import OrderCard from './orders/OrderCard.vue';
import OrderStatusBadge from './orders/OrderStatusBadge.vue';

const props = defineProps({
    // Real status string deep-linked from Reports.vue's order-breakdown.
    statusFilter: { type: String, default: null },
});

const {
    orders,
    ordersMeta,
    isLoadingOrders,
    loadError,
    orderFilters: filters,
    resetOrderFilters: resetFilters,
    loadOrders,
    formatCurrency,
} = useOrders();

// ---- derived ------------------------------------------------------------

const statusCounts = computed(() => ordersMeta.value?.statusCounts || {});
const newCount = computed(() => Number(statusCounts.value.New || 0));
const total = computed(() => orders.value.length);

const totalLabel = computed(() => {
    const t = total.value;

    return `${t} order${t === 1 ? '' : 's'}`;
});

const hasActiveFilters = computed(() => {
    const f = filters.value;

    return (
        !!f.search ||
        !!f.status ||
        !!f.payment_status ||
        !!f.date_from ||
        !!f.date_to ||
        f.sort !== 'newest'
    );
});

// ---- kanban board — real Order::STATUSES grouped into stage columns
// (see the template comment above the board for why: 9 real statuses,
// grouped to 4 columns so it matches the reference; each card still
// shows its own exact status via OrderStatusBadge). Cancelled/Rejected
// orders are intentionally not shown as a column (per the reference),
// so a cancelled/rejected order won't appear on this board — it's still
// reachable via the Order Status filter in the header, and on the full
// Order Details page. ----
const STAGE_GROUPS = [
    { key: 'new', label: 'New', statuses: ['New'], color: '#9dc2ef' },
    { key: 'processing', label: 'Processing', statuses: ['Confirmed', 'Processing', 'Packed', 'Ready for Pickup'], color: '#fbbf7d' },
    { key: 'transit', label: 'In Transit', statuses: ['In Transit'], color: '#6fa3e0' },
    { key: 'delivered', label: 'Delivered', statuses: ['Delivered'], color: '#14b8a6' },
];

const kanbanColumns = computed(() =>
    STAGE_GROUPS.map((g) => {
        // Most-recently-touched first (real `updatedAt`, same field
        // Prepare Orders' queue and Courier Handover's history table
        // already sort by) — so a card that just moved into a column
        // (e.g. an order Courier Handover just confirmed picked up)
        // surfaces at the top of "In Transit" instead of wherever its
        // original placed_at date happens to rank it.
        const cards = orders.value
            .filter((o) => g.statuses.includes(o.status))
            .sort(
                (a, b) =>
                    new Date(b.updatedAt || b.placedAt || 0) - new Date(a.updatedAt || a.placedAt || 0),
            );

        return { ...g, cards, count: cards.length };
    }),
);

const activePipelineCount = computed(() =>
    kanbanColumns.value
        .filter((c) => c.key !== 'delivered')
        .reduce((sum, c) => sum + c.count, 0),
);

// Each column shows at most 3 cards; "View N more" doesn't reveal them
// in place — it opens the full Orders List below, pre-filtered to that
// column's stage (matches the reference's kanban-more -> orders-list
// click-through).
const COLUMN_INITIAL = 3;

function visibleCards(col) {
    return col.cards.slice(0, COLUMN_INITIAL);
}

// ---- Orders List — the drill-down a "View N more" opens. Reads the
// same already-loaded `orders` array as the board (no extra fetch) and
// paginates it client-side, since loadOrders() already returns the
// seller's full matching list in one shot. ----
const viewMode = ref('board'); // 'board' | 'list'
const listStageKey = ref('all');
const LIST_PAGE_SIZE = 10;
const listPage = ref(1);

const LIST_TABS = [{ key: 'all', label: 'All' }, ...STAGE_GROUPS.map((g) => ({ key: g.key, label: g.label }))];

function openList(stageKey) {
    listStageKey.value = stageKey;
    listPage.value = 1;
    viewMode.value = 'list';
}

function closeList() {
    viewMode.value = 'board';
}

function selectListTab(key) {
    listStageKey.value = key;
    listPage.value = 1;
}

const listStageGroup = computed(() => STAGE_GROUPS.find((g) => g.key === listStageKey.value) || null);
const listTitle = computed(() => listStageGroup.value?.label || 'All');

const listOrders = computed(() =>
    listStageGroup.value
        ? orders.value.filter((o) => listStageGroup.value.statuses.includes(o.status))
        : orders.value,
);

const listLastPage = computed(() => Math.max(1, Math.ceil(listOrders.value.length / LIST_PAGE_SIZE)));

const pagedListOrders = computed(() => {
    const start = (listPage.value - 1) * LIST_PAGE_SIZE;

    return listOrders.value.slice(start, start + LIST_PAGE_SIZE);
});

const listRangeLabel = computed(() => {
    if (!listOrders.value.length) {
        return 'No orders';
    }

    const start = (listPage.value - 1) * LIST_PAGE_SIZE + 1;
    const end = Math.min(listOrders.value.length, listPage.value * LIST_PAGE_SIZE);

    return `Showing ${start} to ${end} of ${listOrders.value.length} orders`;
});

function goToListPage(page) {
    listPage.value = Math.min(Math.max(1, page), listLastPage.value);
}

// Keeps the current page in range if a filter/status change shrinks the
// list out from under it (e.g. an order gets moved off this stage).
watch(listOrders, () => {
    if (listPage.value > listLastPage.value) {
        listPage.value = listLastPage.value;
    }
});

function orderItemsSummary(o) {
    const items = o.items || [];

    if (!items.length) {
        return 'No items';
    }

    const first = items[0].name || 'Item';

    return items.length === 1 ? first : `${first} +${items.length - 1} more`;
}

// ---- revenue split — real payment_method values are 'cod' | 'gcash' |
// 'card' (see buyer Checkout.vue); "Online" groups gcash+card. Only
// counts Paid orders, so this is real collected revenue, not a
// projection. No month-over-month comparison — there is no historical
// revenue snapshot to compare against. ----
const paidRevenue = computed(() => {
    const paid = orders.value.filter((o) => o.paymentStatus === 'Paid');
    const cod = paid.filter((o) => o.paymentMethod === 'cod').reduce((s, o) => s + (Number(o.total) || 0), 0);
    const online = paid.filter((o) => o.paymentMethod !== 'cod').reduce((s, o) => s + (Number(o.total) || 0), 0);

    return { cod, online, total: cod + online };
});

const revenueDonutSegments = computed(() => {
    const { cod, online, total: revTotal } = paidRevenue.value;
    const denom = revTotal || 1;
    const segments = [
        { key: 'online', color: '#6fa3e0', amount: online },
        { key: 'cod', color: '#fbbf7d', amount: cod },
    ];

    let offsetAcc = 0;

    return segments
        .filter((s) => s.amount > 0)
        .map((s) => {
            const pct = Math.round((s.amount / denom) * 100);
            const seg = { ...s, pct, dashoffset: -offsetAcc };

            offsetAcc += pct;

            return seg;
        });
});

// ---- display helpers --------------------------------------------------

// ---- data loading ---------------------------------------------------------

// No `page` param -> SellerOrderController returns the full unpaginated
// list (see its docblock). The kanban board needs every matching order
// at once to group them into real columns, and the Orders List
// drill-down (see openList()) paginates that same real array client-side
// instead of re-fetching.
async function reload() {
    await loadOrders({ ...filters.value });
}

watch(filters, reload, { deep: true });

watch(
    () => props.statusFilter,
    (status) => {
        if (status) {
            filters.value = { ...filters.value, status };
        }
    },
    { immediate: true },
);

onMounted(reload);

// Orders are created buyer-side straight in Supabase (not through this
// Laravel API — see useOrders.js's own header comment), and nothing
// else on this page re-triggers a fetch once it's mounted (only a
// filter change or a fresh mount does). Without a poll, a seller who
// just leaves the board open would never see a brand-new order land in
// "New", or another order they shipped elsewhere move into "In
// Transit" — same 30s rhythm as the orders cache's own TTL (and the
// same fix already applied to Dashboard.vue) so each tick is a real
// fetch, not a wasted one.
const ORDERS_POLL_MS = 30 * 1000;
let ordersPollTimer = null;

onMounted(() => {
    ordersPollTimer = setInterval(reload, ORDERS_POLL_MS);
});

onBeforeUnmount(() => {
    clearInterval(ordersPollTimer);
});

// Status changes and Cancel/Reject live on the full Order Details page,
// opened by clicking a card (nothing here mutates order state directly
// anymore).
function openDetails(id) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'orderDetails', orderId: id },
        }),
    );
}
</script>
