<!-- resources/js/seller/components/Delivery.vue -->
<template>
    <div class="delivery-page">
        <!-- ============ TOOLBAR ============ -->
        <div class="delivery-toolbar">
            <div class="header-search delivery-search">
                <span class="search-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.35-4.35" /></svg>
                </span>
                <input
                    type="text"
                    :value="filters.search"
                    placeholder="Order #, product, tracking #, courier…"
                    aria-label="Search deliveries"
                    @input="setSearch($event.target.value)"
                />
            </div>

            <div class="delivery-toolbar-controls">
                <!-- Date range (reuses the same widget styling as Reports' picker) -->
                <div class="report-daterange" ref="rangeMenuEl">
                    <button type="button" class="report-daterange-btn" :aria-expanded="showRangeMenu" @click="showRangeMenu = !showRangeMenu">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
                        <span>{{ dateRangeLabel }}</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6" /></svg>
                    </button>
                    <div v-if="showRangeMenu" class="report-daterange-menu" role="menu">
                        <button type="button" class="report-daterange-option" :class="{ active: !filters.from && !filters.to }" @click="applyDatePreset(null)">All time</button>
                        <button
                            v-for="p in datePresets"
                            :key="p.value"
                            type="button"
                            class="report-daterange-option"
                            :class="{ active: activeDatePreset === p.value }"
                            @click="applyDatePreset(p.value)"
                        >
                            {{ p.label }}
                        </button>
                        <div class="report-daterange-custom">
                            <p class="report-daterange-custom-label">Custom range</p>
                            <div class="report-daterange-custom-fields">
                                <label class="field-label" for="delivery-from">From</label>
                                <input id="delivery-from" type="date" class="field-input" v-model="customFrom" :max="customTo || undefined" />
                                <label class="field-label" for="delivery-to">To</label>
                                <input id="delivery-to" type="date" class="field-input" v-model="customTo" :min="customFrom || undefined" />
                            </div>
                            <p v-if="customRangeError" class="report-daterange-error">{{ customRangeError }}</p>
                            <button type="button" class="btn-primary btn-sm" style="width: 100%; margin-top: 0.5rem" @click="applyCustomDateRange">Apply</button>
                        </div>
                    </div>
                </div>

                <select class="field-input delivery-sort-select" v-model="sortValue" aria-label="Sort deliveries">
                    <option value="updated_desc">Most Recently Updated</option>
                    <option value="updated_asc">Oldest Update First</option>
                    <option value="placed_desc">Newest Order Placed</option>
                </select>

                <button v-if="hasActiveFilters" type="button" class="btn-outline btn-sm" @click="resetFilters">Reset filters</button>

                <button type="button" class="btn-primary delivery-export-btn" :disabled="isExporting" @click="exportCsv">
                    <svg v-if="!isExporting" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
                    <span v-else class="loading-spinner" style="width: 1rem; height: 1rem; border-width: 2px"></span>
                    {{ isExporting ? 'Preparing…' : 'Export CSV' }}
                </button>
            </div>
        </div>
        <p v-if="exportError" class="save-msg error">{{ exportError }}</p>

        <!-- No live/realtime infra exists in this project (see useDeliveries.js
             docblock precedent in useMessaging.js) — an honest "last updated"
             timestamp + manual refresh, not a fake pulsing "live" indicator. -->
        <div class="delivery-updated-row">
            <p class="delivery-updated-note">
                <template v-if="summary">Last updated {{ lastUpdatedLabel }}</template>
            </p>
            <button type="button" class="delivery-refresh-btn" @click="refreshAll" :disabled="isLoadingSummary || isLoadingDeliveries" aria-label="Refresh">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7" /><path d="M3 4v5h5" /></svg>
                Refresh
            </button>
        </div>

        <!-- ============ SUMMARY CARDS ============ -->
        <section class="delivery-summary-grid" aria-label="Delivery summary">
            <template v-if="isLoadingSummary && !summary">
                <div v-for="n in 3" :key="n" class="report-kpi-card report-skeleton-card" aria-hidden="true">
                    <div class="report-skeleton-line" style="width: 50%; height: 0.9rem"></div>
                    <div class="report-skeleton-line" style="width: 35%; height: 1.6rem; margin-top: 0.75rem"></div>
                </div>
            </template>

            <div v-else-if="summaryError" class="empty-state" style="grid-column: 1 / -1">
                <p style="font-weight: 700; color: #b91c1c">Couldn't load the delivery summary</p>
                <p class="empty-hint">{{ summaryError }}</p>
                <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadSummary">Try again</button>
            </div>

            <template v-else-if="summary">
                <button type="button" class="report-kpi-card delivery-summary-card" @click="setFilter({ status: 'delivered' })">
                    <div class="report-kpi-top">
                        <span class="report-kpi-icon emerald">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" /><path d="M3 8l9 5 9-5M12 13v8" /></svg>
                        </span>
                    </div>
                    <p class="report-kpi-label">Delivered Today</p>
                    <h3 class="report-kpi-value">{{ summary.deliveredToday }}</h3>
                    <p class="report-kpi-sub">Based on last status update, {{ summary.timezone }}</p>
                </button>

                <button type="button" class="report-kpi-card delivery-summary-card" @click="setFilter({ status: 'in_transit' })">
                    <div class="report-kpi-top">
                        <span class="report-kpi-icon blue">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="6" width="14" height="11" rx="1" /><path d="M15 10h4l3 3v4h-7z" /><circle cx="6" cy="19" r="2" /><circle cx="17.5" cy="19" r="2" /></svg>
                        </span>
                    </div>
                    <p class="report-kpi-label">In Transit</p>
                    <h3 class="report-kpi-value">{{ summary.inTransit }}</h3>
                    <p class="report-kpi-sub">Shipped, outcome not yet recorded</p>
                </button>

                <button type="button" class="report-kpi-card delivery-summary-card" @click="setFilter({ status: 'issues' })">
                    <div class="report-kpi-top">
                        <span class="report-kpi-icon" style="background: #fff1f2; color: #e11d48">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01" /><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>
                        </span>
                    </div>
                    <p class="report-kpi-label">Delivery Issues</p>
                    <h3 class="report-kpi-value">{{ summary.issues }}</h3>
                    <p class="report-kpi-sub">Shipped, then cancelled</p>
                </button>
            </template>
        </section>

        <!-- ============ RECENT DELIVERIES ============ -->
        <section v-if="recentDeliveries.length" class="delivery-recent-section" aria-label="Recent deliveries">
            <h3 class="report-card-title">Recent Deliveries</h3>
            <div class="delivery-recent-grid">
                <article v-for="d in recentDeliveries" :key="d.id" class="card delivery-recent-card">
                    <div class="delivery-recent-head">
                        <button type="button" class="delivery-order-link" @click="openDetails(d.id)">{{ d.id }}</button>
                        <span class="badge" :class="deliveryStatusBadgeClass(d.status)">{{ d.status }}</span>
                    </div>
                    <p class="delivery-recent-items">{{ itemSummary(d) }}</p>
                    <div class="delivery-recent-meta">
                        <span>{{ formatDateTime(d.updatedAt) }}</span>
                        <span v-if="d.deliveryArea">· {{ d.deliveryArea }}</span>
                    </div>
                    <div class="delivery-recent-courier">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="6" width="14" height="11" rx="1" /><path d="M15 10h4l3 3v4h-7z" /></svg>
                        <span>{{ d.courier || 'Courier not recorded' }}</span>
                        <span v-if="d.trackingNumber" class="delivery-tracking-chip">{{ d.trackingNumber }}</span>
                    </div>
                    <!-- Proof of delivery genuinely does not exist in this project's
                         schema — an honest "not uploaded" note, never a fake placeholder. -->
                    <p class="delivery-no-proof">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m3 3 18 18" /><rect x="3" y="3" width="18" height="18" rx="2" /></svg>
                        No proof of delivery uploaded
                    </p>
                    <button
                        v-if="d.status === 'In Transit'"
                        type="button"
                        class="btn-primary btn-sm"
                        style="width: 100%; margin-top: 0.5rem"
                        :disabled="markingDeliveredId === d.id"
                        @click="markDelivered(d)"
                    >
                        {{ markingDeliveredId === d.id ? 'Marking…' : 'Mark as Delivered' }}
                    </button>
                </article>
            </div>
        </section>

        <!-- ============ DELIVERY HISTORY ============ -->
        <section class="card delivery-history-card">
            <div class="delivery-history-head">
                <div>
                    <h3 class="report-card-title">Delivery History</h3>
                    <p class="report-card-subtitle">Full record of shipped, delivered, and cancelled orders</p>
                </div>
                <div class="delivery-status-tabs" role="tablist">
                    <button
                        v-for="tab in statusTabs"
                        :key="tab.value"
                        type="button"
                        role="tab"
                        class="msg-filter-tab"
                        :class="{ active: filters.status === tab.value }"
                        :aria-selected="filters.status === tab.value"
                        @click="setFilter({ status: tab.value })"
                    >
                        {{ tab.label }}
                        <span class="msg-filter-tab-count">{{ tabCount(tab.value) }}</span>
                    </button>
                </div>
            </div>

            <p v-if="deliverError" class="save-msg error" style="margin: 0 0 0.75rem">{{ deliverError }}</p>

            <!-- Loading skeleton -->
            <div v-if="isLoadingDeliveries && !deliveries.length" class="delivery-skeleton-list" aria-hidden="true">
                <div v-for="n in 4" :key="n" class="report-skeleton-line" style="width: 100%; height: 3.2rem"></div>
            </div>

            <!-- Error -->
            <div v-else-if="deliveriesError" class="empty-state">
                <p style="font-weight: 700; color: #b91c1c">Couldn't load deliveries</p>
                <p class="empty-hint">{{ deliveriesError }}</p>
                <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadDeliveries">Try again</button>
            </div>

            <!-- Empty states -->
            <div v-else-if="!deliveries.length && !hasActiveFilters" class="empty-state">
                <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" /><path d="M3 8l9 5 9-5M12 13v8" /></svg>
                <p style="font-weight: 700; color: #1e293b">No delivery records yet</p>
                <p class="empty-hint">Orders show up here once they've shipped.</p>
            </div>
            <div v-else-if="!deliveries.length && filters.status === 'in_transit'" class="empty-state">
                <p style="font-weight: 700; color: #1e293b">Nothing in transit right now</p>
                <p class="empty-hint">Every shipped order has reached an outcome.</p>
            </div>
            <div v-else-if="!deliveries.length && filters.status === 'issues'" class="empty-state">
                <p style="font-weight: 700; color: #1e293b">No delivery issues</p>
                <p class="empty-hint">No shipped orders have been cancelled in this range.</p>
            </div>
            <div v-else-if="!deliveries.length" class="empty-state">
                <p style="font-weight: 700; color: #1e293b">No results match your search</p>
                <p class="empty-hint">Try a different search term or filter.</p>
                <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="resetFilters">Clear filters</button>
            </div>

            <!-- Table (desktop) / cards (mobile, via CSS) -->
            <template v-else>
                <div class="delivery-table" role="table" aria-label="Delivery history">
                    <div class="delivery-row delivery-row-head" role="row">
                        <span role="columnheader">Updated</span>
                        <span role="columnheader">Order</span>
                        <span role="columnheader">Items</span>
                        <span role="columnheader">Area</span>
                        <span role="columnheader">Courier</span>
                        <span role="columnheader">Status</span>
                        <span role="columnheader"></span>
                    </div>
                    <div v-for="d in deliveries" :key="d.id" class="delivery-row" role="row">
                        <span role="cell" data-label="Updated">{{ formatDateTime(d.updatedAt) }}</span>
                        <span role="cell" data-label="Order">
                            <button type="button" class="delivery-order-link" @click="openDetails(d.id)">{{ d.id }}</button>
                        </span>
                        <span role="cell" data-label="Items" class="delivery-cell-truncate">{{ itemSummary(d) }}</span>
                        <span role="cell" data-label="Area" class="delivery-cell-truncate">{{ d.deliveryArea || '—' }}</span>
                        <span role="cell" data-label="Courier" class="delivery-cell-truncate">{{ d.courier || '—' }}</span>
                        <span role="cell" data-label="Status">
                            <span class="badge" :class="deliveryStatusBadgeClass(d.status)">{{ d.status }}</span>
                        </span>
                        <span role="cell" class="delivery-row-actions">
                            <button
                                v-if="d.status === 'In Transit'"
                                type="button"
                                class="btn-outline btn-sm"
                                :disabled="markingDeliveredId === d.id"
                                @click="markDelivered(d)"
                            >
                                {{ markingDeliveredId === d.id ? '…' : 'Mark Delivered' }}
                            </button>
                            <button type="button" class="btn-outline btn-sm" @click="openDetails(d.id)">Details</button>
                        </span>
                    </div>
                </div>

                <div v-if="deliveriesMeta.lastPage > 1" class="pagination delivery-pagination">
                    <div class="pagination-controls">
                        <button class="page-btn" :disabled="deliveriesMeta.currentPage === 1" @click="setFilter({ page: deliveriesMeta.currentPage - 1 })">Previous</button>
                        <span class="pagination-page-indicator">Page {{ deliveriesMeta.currentPage }} of {{ deliveriesMeta.lastPage }} · {{ deliveriesMeta.total }} total</span>
                        <button class="page-btn" :disabled="deliveriesMeta.currentPage === deliveriesMeta.lastPage" @click="setFilter({ page: deliveriesMeta.currentPage + 1 })">Next</button>
                    </div>
                </div>
            </template>
        </section>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useDeliveries } from '../composables/useDeliveries';
import { useOrders } from '../composables/useOrders';

const {
    deliveries,
    deliveriesMeta,
    isLoadingDeliveries,
    deliveriesError,
    loadDeliveries,

    summary,
    isLoadingSummary,
    summaryError,
    loadSummary,

    filters,
    hasActiveFilters,
    setSearch,
    setFilter,
    resetFilters,
    initFromUrl,

    isExporting,
    exportError,
    exportCsv,
} = useDeliveries();

// deliverOrder() already exists and is already permitted by
// Order::ALLOWED_TRANSITIONS server-side — reused here directly rather
// than duplicated. This page has its own paginated data source
// (useDeliveries); only the mutation is shared.
const { deliverOrder, updateError: deliverError } = useOrders();

const statusTabs = [
    { value: 'all', label: 'All' },
    { value: 'in_transit', label: 'In Transit' },
    { value: 'delivered', label: 'Delivered' },
    { value: 'issues', label: 'Issues' },
];
const datePresets = [
    { value: 'today', label: 'Today' },
    { value: 'last7', label: 'Last 7 Days' },
    { value: 'last30', label: 'Last 30 Days' },
];

const showRangeMenu = ref(false);
const rangeMenuEl = ref(null);
const customFrom = ref('');
const customTo = ref('');
const customRangeError = ref('');
const markingDeliveredId = ref(null);

function todayStr() {
    return new Date().toISOString().slice(0, 10);
}
function daysAgoStr(n) {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return d.toISOString().slice(0, 10);
}

const activeDatePreset = computed(() => {
    if (!filters.value.from || !filters.value.to) return null;
    if (filters.value.from === todayStr() && filters.value.to === todayStr()) return 'today';
    if (filters.value.from === daysAgoStr(6) && filters.value.to === todayStr()) return 'last7';
    if (filters.value.from === daysAgoStr(29) && filters.value.to === todayStr()) return 'last30';
    return null;
});

const dateRangeLabel = computed(() => {
    const preset = datePresets.find((p) => p.value === activeDatePreset.value);
    if (preset) return preset.label;
    if (filters.value.from && filters.value.to) {
        const fmt = (s) => new Date(`${s}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        return `${fmt(filters.value.from)} – ${fmt(filters.value.to)}`;
    }
    return 'All time';
});

function applyDatePreset(preset) {
    if (!preset) {
        setFilter({ from: '', to: '' });
    } else {
        const map = { today: [todayStr(), todayStr()], last7: [daysAgoStr(6), todayStr()], last30: [daysAgoStr(29), todayStr()] };
        const [from, to] = map[preset];
        setFilter({ from, to });
    }
    showRangeMenu.value = false;
}

function applyCustomDateRange() {
    customRangeError.value = '';
    if (!customFrom.value || !customTo.value) {
        customRangeError.value = 'Pick both a start and end date.';
        return;
    }
    if (customTo.value < customFrom.value) {
        customRangeError.value = 'End date must be on or after the start date.';
        return;
    }
    setFilter({ from: customFrom.value, to: customTo.value });
    showRangeMenu.value = false;
}

const sortValue = computed({
    get: () => filters.value.sort,
    set: (v) => setFilter({ sort: v }),
});

function tabCount(value) {
    const counts = deliveriesMeta.value.statusCounts || {};
    const map = { all: 'all', in_transit: 'inTransit', delivered: 'delivered', issues: 'issues' };
    return counts[map[value]] ?? 0;
}

function itemSummary(d) {
    if (!d.items?.length) return 'No items recorded';
    if (d.items.length === 1) return d.items[0].variant ? `${d.items[0].name} (${d.items[0].variant})` : d.items[0].name;
    return `${d.items[0].name} + ${d.items.length - 1} more`;
}

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function deliveryStatusBadgeClass(status) {
    return { 'In Transit': 'badge-sky', Delivered: 'badge-emerald', Cancelled: 'badge-red' }[status] || 'badge-slate';
}

// Top few from whatever's currently loaded/filtered — no extra
// request, just a lighter-weight view of the same first page.
const recentDeliveries = computed(() => deliveries.value.slice(0, 4));

function openDetails(id) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section: 'orderDetails', orderId: id } }));
}

async function markDelivered(d) {
    if (markingDeliveredId.value) return;
    markingDeliveredId.value = d.id;
    const result = await deliverOrder(d.id);
    markingDeliveredId.value = null;
    if (result) {
        // Refresh both the list (status/tab counts changed) and the
        // summary cards (Delivered Today / In Transit counts changed).
        loadDeliveries();
        loadSummary();
    }
}

const lastUpdatedLabel = computed(() => {
    if (!summary.value?.generatedAt) return '';
    return new Date(summary.value.generatedAt).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
});

function refreshAll() {
    loadDeliveries();
    loadSummary();
}

function onDocClick(e) {
    if (showRangeMenu.value && rangeMenuEl.value && !rangeMenuEl.value.contains(e.target)) {
        showRangeMenu.value = false;
    }
}
function onEscKey(e) {
    if (e.key === 'Escape' && showRangeMenu.value) showRangeMenu.value = false;
}

onMounted(() => {
    initFromUrl();
    customFrom.value = filters.value.from;
    customTo.value = filters.value.to;
    loadDeliveries();
    loadSummary();
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onEscKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onEscKey);
});
</script>