<!-- resources/js/seller/components/Delivery.vue -->
<template>
    <div class="delivery-page">
        <!-- ============ HEADER: title + search/date/export ============ -->
        <div class="dp-header">
            <div>
                <h1 class="dp-title">Deliveries</h1>
                <p class="dp-subtitle">Track fulfillment performance and courier reliability.</p>
            </div>

            <div class="dp-header-controls">
                <div class="header-search delivery-search">
                    <span class="search-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.35-4.35" /></svg>
                    </span>
                    <input
                        type="text"
                        :value="filters.search"
                        placeholder="Order #, tracking #, courier…"
                        aria-label="Search deliveries"
                        @input="setSearch($event.target.value)"
                    />
                </div>

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

                <button type="button" class="btn-primary delivery-export-btn" :disabled="isExporting" @click="exportCsv">
                    <svg v-if="!isExporting" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
                    <span v-else class="loading-spinner" style="width: 1rem; height: 1rem; border-width: 2px"></span>
                    {{ isExporting ? 'Preparing…' : 'Export CSV' }}
                </button>
            </div>
        </div>
        <p v-if="exportError" class="save-msg error">{{ exportError }}</p>
        <button v-if="hasActiveFilters" type="button" class="btn-outline btn-sm dp-reset-filters" @click="resetDeliveryFilters">Reset filters</button>

        <!-- ============ STAT BAR ============ -->
        <div v-if="isLoadingSummary && !summary" class="dp-stat-bar">
            <div v-for="n in 4" :key="n" class="dp-stat-seg" aria-hidden="true">
                <div class="report-skeleton-line" style="width: 2.2rem; height: 2.2rem; border-radius: 0.65rem"></div>
                <div style="flex: 1">
                    <div class="report-skeleton-line" style="width: 2.5rem; height: 1.2rem"></div>
                    <div class="report-skeleton-line" style="width: 70%; height: 0.7rem; margin-top: 0.4rem"></div>
                </div>
            </div>
        </div>
        <div v-else-if="summaryError" class="empty-state">
            <p style="font-weight: 700; color: #b91c1c">Couldn't load the delivery summary</p>
            <p class="empty-hint">{{ summaryError }}</p>
            <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadSummary">Try again</button>
        </div>
        <div v-else-if="summary" class="dp-stat-bar">
            <div class="dp-stat-seg dp-stat-seg--static">
                <span class="dp-stat-ic good">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" /><path d="M3 8l9 5 9-5M12 13v8" /></svg>
                </span>
                <div>
                    <div class="dp-stat-v">{{ summary.deliveredThisWeek }}</div>
                    <div class="dp-stat-l">Delivered This Week</div>
                </div>
            </div>
            <div class="dp-stat-seg dp-stat-seg--static">
                <span class="dp-stat-ic blue">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="6" width="14" height="11" rx="1" /><path d="M15 10h4l3 3v4h-7z" /><circle cx="6" cy="19" r="2" /><circle cx="17.5" cy="19" r="2" /></svg>
                </span>
                <div>
                    <div class="dp-stat-v">{{ summary.inTransit }}</div>
                    <div class="dp-stat-l">In Transit</div>
                </div>
            </div>
            <div class="dp-stat-seg dp-stat-seg--static">
                <span class="dp-stat-ic bad">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01" /><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>
                </span>
                <div>
                    <div class="dp-stat-v">{{ summary.issues }}</div>
                    <div class="dp-stat-l">Delivery Issues</div>
                </div>
            </div>
            <div class="dp-stat-seg dp-stat-seg--static" :title="`Delivered within ${summary.onTimeThresholdDays} days of shipping`">
                <span class="dp-stat-ic neutral">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 3" /></svg>
                </span>
                <div>
                    <div class="dp-stat-v">{{ summary.onTimeRate === null ? '—' : `${summary.onTimeRate}%` }}</div>
                    <div class="dp-stat-l">On-Time Rate</div>
                </div>
            </div>
        </div>

        <!-- ============ COURIER PERFORMANCE + DELIVERY ISSUES ============ -->
        <div class="dp-mid-grid">
            <section class="card dp-courier-card">
                <div class="dp-card-head">
                    <h3 class="report-card-title">Courier Performance</h3>
                    <span class="dp-range-pill">{{ dateRangeLabel }}</span>
                </div>

                <div v-if="isLoadingCourierPerformance && !courierPerformance.length" class="delivery-skeleton-list" style="padding: 0 1.4rem 1.4rem" aria-hidden="true">
                    <div v-for="n in 3" :key="n" class="report-skeleton-line" style="width: 100%; height: 2.2rem"></div>
                </div>
                <div v-else-if="courierPerformanceError" class="empty-state" style="padding: 1.5rem">
                    <p style="font-weight: 700; color: #b91c1c">Couldn't load courier performance</p>
                    <p class="empty-hint">{{ courierPerformanceError }}</p>
                </div>
                <div v-else-if="!courierPerformance.length" class="empty-state" style="padding: 2rem 1.5rem">
                    <p class="empty-hint">No shipments with a recorded courier in this range yet.</p>
                </div>
                <template v-else>
                    <div class="dp-courier-table" role="table">
                        <div class="dp-courier-row dp-courier-row-head" role="row">
                            <span role="columnheader">Courier</span>
                            <span role="columnheader">Shipments</span>
                            <span role="columnheader">Avg. Delivery Time</span>
                            <span role="columnheader">On-Time Rate</span>
                            <span role="columnheader">Issues</span>
                        </div>
                        <div v-for="c in courierPerformance" :key="c.courier" class="dp-courier-row" role="row">
                            <span role="cell" class="dp-courier-name">
                                <span class="dp-courier-badge" :class="courierBadgeClass(c.courier)">{{ courierInitials(c.courier) }}</span>
                                {{ c.courier }}
                            </span>
                            <span role="cell">{{ c.shipments }}</span>
                            <span role="cell">{{ c.avgDeliveryDays === null ? '—' : `${c.avgDeliveryDays} days` }}</span>
                            <span role="cell" class="dp-ontime-cell">
                                <template v-if="c.onTimeRate === null">—</template>
                                <template v-else>
                                    <span class="dp-ontime-track"><span class="dp-ontime-fill" :class="onTimeRateClass(c.onTimeRate)" :style="{ width: c.onTimeRate + '%' }"></span></span>
                                    <span :class="onTimeRateClass(c.onTimeRate)">{{ c.onTimeRate }}%</span>
                                </template>
                            </span>
                            <span role="cell" :class="{ 'dp-issue-count': c.issues > 0 }">{{ c.issues }}</span>
                        </div>
                    </div>
                    <p v-if="courierInsight" class="dp-insight">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01" /><circle cx="12" cy="12" r="9" /></svg>
                        {{ courierInsight }}
                    </p>
                </template>
            </section>

            <section class="card dp-issues-card">
                <div class="dp-card-head">
                    <h3 class="report-card-title">Delivery Issues</h3>
                    <span class="dp-range-pill">{{ dateRangeLabel }}</span>
                </div>

                <div v-if="isLoadingIssues && !issuesList.length" class="delivery-skeleton-list" style="padding: 0 1.4rem 1.4rem" aria-hidden="true">
                    <div v-for="n in 3" :key="n" class="report-skeleton-line" style="width: 100%; height: 2.6rem"></div>
                </div>
                <div v-else-if="issuesError" class="empty-state" style="padding: 1.5rem">
                    <p style="font-weight: 700; color: #b91c1c">Couldn't load delivery issues</p>
                    <p class="empty-hint">{{ issuesError }}</p>
                </div>
                <div v-else-if="!issuesList.length" class="empty-state" style="padding: 2rem 1.5rem">
                    <p class="empty-hint">No delivery issues in this range.</p>
                </div>
                <div v-else class="dp-issue-list">
                    <div v-for="i in issuesList" :key="i.id" class="dp-issue-row">
                        <span class="dp-issue-ic">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M9.5 9.5 14.5 14.5M14.5 9.5 9.5 14.5" /></svg>
                        </span>
                        <div class="dp-issue-body">
                            <div class="dp-issue-top">
                                <button type="button" class="delivery-order-link" @click="openDetails(i.id)">{{ i.id }}</button>
                                <span class="dp-issue-courier">{{ i.courier || 'Courier not recorded' }}</span>
                            </div>
                            <p class="dp-issue-reason">{{ i.reason || 'No reason recorded' }}</p>
                        </div>
                        <span class="dp-issue-date">{{ formatShortDate(i.cancelledAt) }}</span>
                    </div>
                </div>
            </section>
        </div>

        <!-- ============ RECENT DELIVERIES (the only delivery list on this
             page now — real, paginated, server-fetched, always scoped to
             status=Delivered) ============ -->
        <section class="card dp-recent-card">
            <div class="dp-card-head">
                <div>
                    <h3 class="report-card-title">Recent Deliveries</h3>
                    <p class="delivery-auto-note">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9" /><path d="M12 8v4l2.5 2.5" /></svg>
                        Orders land here on their own — once the buyer confirms delivery, or automatically after 7 days In Transit with no confirmation. There's no manual "mark as delivered" here.
                    </p>
                </div>
                <select class="field-input delivery-sort-select" v-model="sortValue" aria-label="Sort recent deliveries">
                    <option value="updated_desc">Most Recently Delivered</option>
                    <option value="updated_asc">Oldest Delivered First</option>
                    <option value="placed_desc">Newest Order Placed</option>
                </select>
            </div>

            <!-- Loading skeleton -->
            <div v-if="isLoadingDeliveries && !deliveries.length" class="delivery-skeleton-list" style="padding: 0 1.4rem 1.4rem" aria-hidden="true">
                <div v-for="n in 4" :key="n" class="report-skeleton-line" style="width: 100%; height: 2.4rem"></div>
            </div>

            <!-- Error -->
            <div v-else-if="deliveriesError" class="empty-state">
                <p style="font-weight: 700; color: #b91c1c">Couldn't load recent deliveries</p>
                <p class="empty-hint">{{ deliveriesError }}</p>
                <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadDeliveries">Try again</button>
            </div>

            <!-- Empty states -->
            <div v-else-if="!deliveries.length && !hasActiveFilters" class="empty-state">
                <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" /><path d="M3 8l9 5 9-5M12 13v8" /></svg>
                <p style="font-weight: 700; color: #1e293b">No delivered orders yet</p>
                <p class="empty-hint">Orders show up here once they've actually been delivered.</p>
            </div>
            <div v-else-if="!deliveries.length" class="empty-state">
                <p style="font-weight: 700; color: #1e293b">No results match your search</p>
                <p class="empty-hint">Try a different search term or date range.</p>
                <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="resetDeliveryFilters">Clear filters</button>
            </div>

            <!-- Table (desktop) / cards (mobile, via CSS) -->
            <template v-else>
                <div class="dp-recent-table" role="table">
                    <div class="dp-recent-row dp-recent-row-head" role="row">
                        <span role="columnheader">Order</span>
                        <span role="columnheader">Customer</span>
                        <span role="columnheader">Items</span>
                        <span role="columnheader">Courier</span>
                        <span role="columnheader">Delivered</span>
                        <span role="columnheader">Delivery Time</span>
                        <span role="columnheader">Status</span>
                    </div>
                    <div v-for="d in deliveries" :key="d.id" class="dp-recent-row" role="row">
                        <span role="cell">
                            <button type="button" class="delivery-order-link" @click="openDetails(d.id)">{{ d.id }}</button>
                        </span>
                        <span role="cell" class="dp-recent-customer">
                            <span class="dp-cust-avatar">{{ customerInitials(d.customer) }}</span>
                            {{ d.customer || 'Unknown buyer' }}
                        </span>
                        <span role="cell" class="delivery-cell-truncate">{{ itemSummary(d) }}</span>
                        <span role="cell" class="delivery-cell-truncate">{{ d.courier || '—' }}</span>
                        <span role="cell">{{ formatShortDate(d.deliveredAt) }}</span>
                        <span role="cell">{{ d.deliveryDays === null ? '—' : `${d.deliveryDays} days` }}</span>
                        <span role="cell">
                            <span v-if="d.onTime === null" class="badge badge-slate">—</span>
                            <span v-else class="badge" :class="d.onTime ? 'badge-emerald' : 'badge-amber'">{{ d.onTime ? 'On Time' : 'Late' }}</span>
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

    courierPerformance,
    courierInsight,
    isLoadingCourierPerformance,
    courierPerformanceError,
    loadCourierPerformance,

    issuesList,
    isLoadingIssues,
    issuesError,
    loadIssues,

    filters,
    hasActiveFilters,
    setSearch,
    setFilter,
    initFromUrl,

    isExporting,
    exportError,
    exportCsv,
} = useDeliveries();

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

function todayStr() {
    return new Date().toISOString().slice(0, 10);
}
function daysAgoStr(n) {
    const d = new Date();
    d.setDate(d.getDate() - n);

    return d.toISOString().slice(0, 10);
}

const activeDatePreset = computed(() => {
    if (!filters.value.from || !filters.value.to) {
return null;
}

    if (filters.value.from === todayStr() && filters.value.to === todayStr()) {
return 'today';
}

    if (filters.value.from === daysAgoStr(6) && filters.value.to === todayStr()) {
return 'last7';
}

    if (filters.value.from === daysAgoStr(29) && filters.value.to === todayStr()) {
return 'last30';
}

    return null;
});

const dateRangeLabel = computed(() => {
    const preset = datePresets.find((p) => p.value === activeDatePreset.value);

    if (preset) {
return preset.label;
}

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

function itemSummary(d) {
    if (!d.items?.length) {
return 'No items recorded';
}

    if (d.items.length === 1) {
return d.items[0].variant ? `${d.items[0].name} (${d.items[0].variant})` : d.items[0].name;
}

    return `${d.items[0].name} + ${d.items.length - 1} more`;
}

function formatShortDate(iso) {
    if (!iso) {
return '—';
}

    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function onTimeRateClass(rate) {
    if (rate >= 90) {
return 'dp-rate-good';
}

    if (rate >= 75) {
return 'dp-rate-warn';
}

    return 'dp-rate-bad';
}

// ---- courier badge (initials + a deterministic color from real text —
// couriers are free text here, not a fixed enum, so the color is a
// styling aid, not a claim about the courier itself). Same pattern
// CourierHandover.vue already uses. ----
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

function customerInitials(name) {
    if (!name) {
return '?';
}

    const parts = name.trim().split(/\s+/);

    return parts.length === 1
        ? parts[0].slice(0, 2).toUpperCase()
        : (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function openDetails(id) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section: 'orderDetails', orderId: id } }));
}

// Resets search/date/sort — status stays pinned to 'delivered' (see
// filters' own comment in useDeliveries.js), unlike the composable's
// own resetFilters(), which would reset it back to that same default
// anyway, but this makes the invariant explicit here too.
function resetDeliveryFilters() {
    customFrom.value = '';
    customTo.value = '';
    setFilter({ search: '', from: '', to: '', sort: 'updated_desc', status: 'delivered', page: 1 });
}

function onDocClick(e) {
    if (showRangeMenu.value && rangeMenuEl.value && !rangeMenuEl.value.contains(e.target)) {
        showRangeMenu.value = false;
    }
}
function onEscKey(e) {
    if (e.key === 'Escape' && showRangeMenu.value) {
showRangeMenu.value = false;
}
}

onMounted(() => {
    initFromUrl();
    // This page no longer has a status-tab UI — Recent Deliveries only
    // ever shows Delivered orders, regardless of what an old bookmarked
    // ?status=... URL might still say.
    filters.value.status = 'delivered';
    customFrom.value = filters.value.from;
    customTo.value = filters.value.to;
    loadDeliveries();
    loadSummary();
    loadCourierPerformance();
    loadIssues();
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onEscKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onEscKey);
});

// Same 30s poll rhythm as Orders.vue/Dashboard.vue — otherwise a
// delivery marked complete elsewhere (Courier Handover, a status
// update) wouldn't show up here until the seller reloads the page.
const DELIVERY_POLL_MS = 30 * 1000;
let deliveryPollTimer = null;

onMounted(() => {
    deliveryPollTimer = setInterval(() => {
        loadDeliveries();
        loadSummary();
        loadCourierPerformance();
        loadIssues();
    }, DELIVERY_POLL_MS);
});

onBeforeUnmount(() => {
    clearInterval(deliveryPollTimer);
});
</script>

<style scoped>
/* ============================================================
   DELIVERY — dark reskin (matches Dashboard / Orders / Inventory /
   Order Preparation / Order Details / Courier Handover). Scoped to
   this component, so targeting shared class names here (.card,
   .field-input, .btn-outline, .report-kpi-card, .msg-filter-tab,
   .empty-state, .pagination, .badge-*, ...) only ever affects what
   this page renders — every other page keeps using the same class
   names, unaffected, in their own light-mode originals.
   ============================================================ */
.delivery-page {
    --dp-surface: #161b17;
    --dp-surface-2: #1d231e;
    --dp-border: rgba(255, 255, 255, 0.08);
    --dp-border-soft: rgba(255, 255, 255, 0.06);
    --dp-ink-900: #f2f4f1;
    --dp-ink-700: #c6cbc5;
    --dp-ink-500: #97a099;
    --dp-ink-400: #6d766e;
    color: var(--dp-ink-900);
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
}

.delivery-page .card,
.delivery-page .report-kpi-card {
    background: var(--dp-surface);
    border: 1px solid var(--dp-border);
}
.delivery-page .field-input {
    background: var(--dp-surface-2);
    border-color: var(--dp-border);
    color: var(--dp-ink-900);
}
.delivery-page .field-input::placeholder {
    color: var(--dp-ink-400);
}
.delivery-page .field-label {
    color: var(--dp-ink-500);
}
.delivery-page .btn-outline {
    background: var(--dp-surface-2);
    border-color: var(--dp-border);
    color: var(--dp-ink-900);
}
.delivery-page .btn-outline:hover:not(:disabled) {
    background: var(--dp-surface);
}
.delivery-page .save-msg.error {
    color: #f7a49f;
}
.delivery-page .report-card-title {
    color: var(--dp-ink-900);
}
.delivery-page .report-card-subtitle {
    color: var(--dp-ink-500);
}
.delivery-page .report-skeleton-line {
    background: var(--dp-surface-2);
}

/* ---- header: title + search/date/export ---- */
.dp-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.dp-title {
    font-size: 1.4rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--dp-ink-900);
    margin: 0;
}
.dp-subtitle {
    font-size: 0.8rem;
    color: var(--dp-ink-500);
    margin: 0.25rem 0 0;
}
.dp-header-controls {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.dp-reset-filters {
    align-self: flex-start;
}

/* The pill (background/border) belongs on the <input>, not this
   wrapper — the wrapper has no padding of its own, so as a block-level
   div it defaults to full width (unlike the input's own fixed width),
   and a border painted on it stretched past the input's real edges,
   leaving the search icon floating outside the visible bar. */
.delivery-page .header-search input {
    background: var(--dp-surface-2);
    border: 1px solid var(--dp-border);
    color: var(--dp-ink-900);
}
.delivery-page .header-search input::placeholder {
    color: var(--dp-ink-400);
}
.delivery-page .header-search .search-icon {
    color: var(--dp-ink-500);
}
.delivery-page .report-daterange-btn {
    background: var(--dp-surface-2);
    border-color: var(--dp-border);
    color: var(--dp-ink-900);
}
.delivery-page .report-daterange-menu {
    background: var(--dp-surface);
    border-color: var(--dp-border);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
}
.delivery-page .report-daterange-option {
    color: var(--dp-ink-700);
}
.delivery-page .report-daterange-option:hover {
    background: var(--dp-surface-2);
}
.delivery-page .report-daterange-option.active {
    background: rgba(20, 184, 166, 0.16);
    color: #5eead4;
}
.delivery-page .report-daterange-custom {
    border-top-color: var(--dp-border-soft);
}
.delivery-page .report-daterange-custom-label {
    color: var(--dp-ink-500);
}
.delivery-page .report-daterange-error {
    color: #f7a49f;
}

/* ---- stat bar (4 segments, one strip) ---- */
.dp-stat-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    background: var(--dp-surface);
    border: 1px solid var(--dp-border);
    border-radius: 1rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    overflow: hidden;
}
.dp-stat-seg {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 1rem 1.25rem;
    border-right: 1px solid var(--dp-border-soft);
    background: none;
    border-top: none;
    border-bottom: none;
    border-left: none;
    text-align: left;
    cursor: pointer;
    font: inherit;
    color: inherit;
}
.dp-stat-seg--static {
    cursor: default;
}
.dp-stat-seg:last-child {
    border-right: none;
}
.dp-stat-seg:hover:not(.dp-stat-seg--static) {
    background: var(--dp-surface-2);
}
.dp-stat-ic {
    width: 2.2rem;
    height: 2.2rem;
    border-radius: 0.65rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.dp-stat-ic.neutral {
    background: rgba(255, 255, 255, 0.06);
    color: var(--dp-ink-500);
}
.dp-stat-ic.blue {
    background: rgba(18, 63, 143, 0.28);
    color: #9dc2ef;
}
.dp-stat-ic.good {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
}
.dp-stat-ic.bad {
    background: rgba(200, 67, 61, 0.2);
    color: #f7a49f;
}
.dp-stat-v {
    font-size: 1.2rem;
    font-weight: 800;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.dp-stat-l {
    font-size: 0.7rem;
    color: var(--dp-ink-500);
    font-weight: 600;
    margin-top: 0.15rem;
}

/* ---- courier performance + delivery issues ---- */
.dp-mid-grid {
    display: grid;
    grid-template-columns: 1.6fr 1fr;
    gap: 1.2rem;
    align-items: start;
}
.dp-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1.1rem 1.4rem;
    border-bottom: 1px solid var(--dp-border-soft);
}
.dp-card-head h3 {
    margin: 0;
}
.dp-range-pill {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--dp-ink-500);
    background: var(--dp-surface-2);
    border: 1px solid var(--dp-border);
    padding: 0.25rem 0.6rem;
    border-radius: 999px;
    white-space: nowrap;
}

.dp-courier-table {
    display: flex;
    flex-direction: column;
}
.dp-courier-row {
    display: grid;
    grid-template-columns: 1.4fr 0.8fr 1.1fr 1.3fr 0.6fr;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.4rem;
    font-size: 0.8rem;
    border-bottom: 1px solid var(--dp-border-soft);
}
.dp-courier-row:last-of-type {
    border-bottom: none;
}
.dp-courier-row-head {
    font-size: 0.65rem;
    font-weight: 800;
    color: var(--dp-ink-400);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.dp-courier-name {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-weight: 700;
    color: var(--dp-ink-900);
}
.dp-courier-badge {
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 0.55rem;
    color: #fff;
    font-size: 0.55rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.dp-courier-badge.neutral { background: var(--dp-ink-400); }
.dp-courier-badge.blue { background: #4f6bb0; }
.dp-courier-badge.warn { background: #b5791b; }
.dp-courier-badge.bad { background: #c8433d; }
.dp-courier-badge.rose { background: #b5476b; }
.dp-ontime-cell {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.dp-ontime-track {
    flex: 1;
    height: 5px;
    border-radius: 999px;
    background: var(--dp-surface-2);
    overflow: hidden;
    max-width: 5.5rem;
}
.dp-ontime-fill {
    display: block;
    height: 100%;
    border-radius: 999px;
}
/* Text color (the % label) and fill-bar background share the same
   onTimeRateClass() name but need very different treatments — a solid
   background behind a same-hued text color is nearly unreadable, so
   the background only applies to the actual .dp-ontime-fill bar. */
.dp-rate-good { color: #5eead4; }
.dp-rate-warn { color: #fbbf7d; }
.dp-rate-bad { color: #f7a49f; }
.dp-ontime-fill.dp-rate-good { background-color: #14b8a6; }
.dp-ontime-fill.dp-rate-warn { background-color: #d97706; }
.dp-ontime-fill.dp-rate-bad { background-color: #c8433d; }
.dp-issue-count {
    color: #f7a49f;
    font-weight: 800;
}
.dp-insight {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    font-size: 0.78rem;
    color: var(--dp-ink-500);
    margin: 0;
    padding: 0.9rem 1.4rem;
    border-top: 1px solid var(--dp-border-soft);
}
.dp-insight svg {
    flex-shrink: 0;
    color: #fbbf7d;
    margin-top: 0.1rem;
}

.dp-issue-list {
    display: flex;
    flex-direction: column;
}
.dp-issue-row {
    display: flex;
    align-items: flex-start;
    gap: 0.7rem;
    padding: 0.85rem 1.4rem;
    border-bottom: 1px solid var(--dp-border-soft);
}
.dp-issue-row:last-child {
    border-bottom: none;
}
.dp-issue-ic {
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 0.5rem;
    background: rgba(200, 67, 61, 0.18);
    color: #f7a49f;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.dp-issue-body {
    flex: 1;
    min-width: 0;
}
.dp-issue-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}
.dp-issue-courier {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--dp-ink-500);
    white-space: nowrap;
}
.dp-issue-reason {
    font-size: 0.78rem;
    color: var(--dp-ink-700);
    margin: 0.2rem 0 0;
}
.dp-issue-date {
    font-size: 0.7rem;
    color: var(--dp-ink-400);
    white-space: nowrap;
    flex-shrink: 0;
    padding-top: 0.15rem;
}

/* ---- recent deliveries table ---- */
.dp-recent-table {
    display: flex;
    flex-direction: column;
}
.dp-recent-row {
    display: grid;
    grid-template-columns: 6rem 1.2fr 1.4fr 0.9fr 6rem 6rem 6rem;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1.4rem;
    font-size: 0.8rem;
    border-bottom: 1px solid var(--dp-border-soft);
}
.dp-recent-row:last-of-type {
    border-bottom: none;
}
.dp-recent-row-head {
    font-size: 0.65rem;
    font-weight: 800;
    color: var(--dp-ink-400);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.dp-recent-customer {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--dp-ink-900);
    font-weight: 600;
}
.dp-cust-avatar {
    width: 1.7rem;
    height: 1.7rem;
    border-radius: 50%;
    background: rgba(15, 118, 110, 0.28);
    color: #5eead4;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.62rem;
    font-weight: 800;
    flex-shrink: 0;
}

/* ---- shared: order link, truncated cells, empty/error states ---- */
.delivery-page .delivery-order-link {
    color: #5eead4;
}
.delivery-page .delivery-cell-truncate {
    color: var(--dp-ink-700);
}
.delivery-page .empty-state p,
.delivery-page .empty-hint {
    color: var(--dp-ink-500);
}
.delivery-page .empty-state p[style*='#1e293b'],
.delivery-page .empty-state p[style*='#b91c1c'] {
    color: var(--dp-ink-900) !important;
}
.delivery-page .empty-state .icon-lg {
    color: var(--dp-ink-400);
}

/* ---- delivery history (unchanged section, same tokens) ---- */
.delivery-page .delivery-auto-note {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.72rem;
    color: var(--dp-ink-500);
    margin: 0.3rem 0 0;
    max-width: 34rem;
}
.delivery-page .delivery-auto-note svg {
    flex-shrink: 0;
    color: #5eead4;
}
.delivery-sort-select {
    width: auto;
    padding-right: 2rem;
}
.delivery-page .delivery-pagination,
.delivery-page .pagination {
    border-top-color: var(--dp-border);
    color: var(--dp-ink-500);
}
.delivery-page .page-btn {
    background: var(--dp-surface-2);
    border-color: var(--dp-border);
    color: var(--dp-ink-700);
}
.delivery-page .page-btn:disabled {
    opacity: 0.4;
}

/* Status badges as translucent chips instead of solid light pastel
   rectangles — same treatment as Order Details / Courier Handover. */
.delivery-page .badge-emerald {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
    border-color: rgba(15, 118, 110, 0.35);
}
.delivery-page .badge-sky {
    background: rgba(18, 63, 143, 0.28);
    color: #9dc2ef;
    border-color: rgba(18, 63, 143, 0.4);
}
.delivery-page .badge-amber {
    background: rgba(251, 191, 125, 0.16);
    color: #fbbf7d;
    border: 1px solid rgba(251, 191, 125, 0.3);
}
.delivery-page .badge-red {
    background: rgba(247, 164, 159, 0.16);
    color: #f7a49f;
    border: 1px solid rgba(247, 164, 159, 0.3);
}
.delivery-page .badge-slate {
    background: var(--dp-surface-2);
    color: var(--dp-ink-500);
    border: 1px solid var(--dp-border);
}

/* ---- responsive ---- */
@media (max-width: 1180px) {
    .dp-stat-bar {
        grid-template-columns: repeat(2, 1fr);
    }
    .dp-mid-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 900px) {
    .dp-courier-row,
    .dp-recent-row {
        grid-template-columns: 1fr;
        gap: 0.3rem;
    }
    .dp-courier-row-head,
    .dp-recent-row-head {
        display: none;
    }
    .dp-courier-row > [role='cell'],
    .dp-recent-row > [role='cell'] {
        display: flex;
        justify-content: space-between;
    }
}
@media (max-width: 860px) {
    .dp-header {
        flex-direction: column;
        align-items: stretch;
    }
    .dp-header-controls {
        justify-content: stretch;
    }
    .dp-header-controls > * {
        flex: 1;
    }
    .delivery-search {
        max-width: none;
    }
}
</style>
