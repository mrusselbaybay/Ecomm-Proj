<!-- resources/js/seller/components/Reports.vue -->
<template>
    <div class="report-page">
        <!-- ============ TOOLBAR ============ -->
        <div class="report-toolbar">
            <div class="report-toolbar-left">
                <div class="report-daterange" ref="rangeMenuEl">
                    <button
                        type="button"
                        class="report-daterange-btn"
                        :aria-expanded="showRangeMenu"
                        aria-haspopup="true"
                        @click="showRangeMenu = !showRangeMenu"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
                        <span>{{ rangeLabel }}</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6" /></svg>
                    </button>

                    <div v-if="showRangeMenu" class="report-daterange-menu" role="menu">
                        <button
                            v-for="p in presetOptions"
                            :key="p.value"
                            type="button"
                            class="report-daterange-option"
                            :class="{ active: preset === p.value }"
                            role="menuitemradio"
                            :aria-checked="preset === p.value"
                            @click="selectPreset(p.value)"
                        >
                            {{ p.label }}
                        </button>

                        <div class="report-daterange-custom">
                            <p class="report-daterange-custom-label">Custom range</p>
                            <div class="report-daterange-custom-fields">
                                <label class="field-label" for="report-from">From</label>
                                <input id="report-from" type="date" class="field-input" v-model="customFromInput" :max="customToInput || undefined" />
                                <label class="field-label" for="report-to">To</label>
                                <input id="report-to" type="date" class="field-input" v-model="customToInput" :min="customFromInput || undefined" />
                            </div>
                            <p v-if="customRangeError" class="report-daterange-error">{{ customRangeError }}</p>
                            <button type="button" class="btn-primary btn-sm" style="width: 100%; margin-top: 0.5rem" @click="submitCustomRange">
                                Apply
                            </button>
                        </div>

                        <button
                            v-if="preset === 'custom'"
                            type="button"
                            class="report-daterange-reset"
                            @click="resetFilters(); showRangeMenu = false"
                        >
                            Reset filters
                        </button>
                    </div>
                </div>

                <p class="report-updated-note">
                    <template v-if="summary">Report data as of {{ lastUpdatedLabel }}</template>
                </p>
            </div>

            <button type="button" class="btn-primary report-export-btn" :disabled="isExporting" @click="exportCsv">
                <svg v-if="!isExporting" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
                <span v-else class="loading-spinner" style="width: 1rem; height: 1rem; border-width: 2px"></span>
                {{ isExporting ? 'Preparing export…' : 'Export CSV' }}
            </button>
        </div>
        <p v-if="exportError" class="save-msg error">{{ exportError }}</p>

        <!-- ============ MAIN KPI ROW ============ -->
        <section class="report-kpi-grid" aria-label="Key performance indicators">
            <template v-if="isLoadingSummary && !summary">
                <div v-for="n in 4" :key="n" class="report-kpi-card report-skeleton-card" aria-hidden="true">
                    <div class="report-skeleton-line" style="width: 40%; height: 0.9rem"></div>
                    <div class="report-skeleton-line" style="width: 65%; height: 1.6rem; margin-top: 0.75rem"></div>
                </div>
            </template>

            <div v-else-if="summaryError" class="empty-state" style="grid-column: 1 / -1">
                <p style="font-weight: 700; color: #b91c1c">Couldn't load your performance summary</p>
                <p class="empty-hint">{{ summaryError }}</p>
                <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadSummary">Try again</button>
            </div>

            <template v-else>
                <article v-for="kpi in mainKpis" :key="kpi.key" class="report-kpi-card">
                    <div class="report-kpi-top">
                        <span class="report-kpi-icon" :class="kpi.iconClass">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" v-html="kpiIcon(kpi.icon)"></svg>
                        </span>
                        <span v-if="kpi.trend !== undefined" class="report-trend" :class="trendClass(kpi.trend)">
                            <template v-if="kpi.trend === null">No comparison data</template>
                            <template v-else>{{ formatTrend(kpi.trend) }}</template>
                        </span>
                    </div>
                    <p class="report-kpi-label">{{ kpi.label }}</p>
                    <h3 class="report-kpi-value">{{ kpi.value }}</h3>
                    <p v-if="kpi.sub" class="report-kpi-sub">{{ kpi.sub }}</p>
                </article>
            </template>
        </section>

        <p v-if="summary" class="report-comparison-note">
            Trends compare against
            <template v-if="summary.comparisonRange">{{ formatRangeShort(summary.comparisonRange) }}</template>
            <template v-else>the immediately preceding period of equal length (not enough prior data yet)</template>
            · all times in {{ summary.timezone }}
        </p>

        <!-- ============ SECONDARY KPI ROW ============ -->
        <section v-if="summary && !summaryError" class="report-kpi-grid secondary" aria-label="Secondary metrics">
            <article v-for="kpi in secondaryKpis" :key="kpi.key" class="report-kpi-card secondary">
                <div class="report-kpi-top">
                    <p class="report-kpi-label">
                        {{ kpi.label }}
                        <span v-if="kpi.tip" class="info-tip" tabindex="0">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 16v-4M12 8h.01" /></svg>
                            <span class="info-tip-bubble">{{ kpi.tip }}</span>
                        </span>
                    </p>
                    <span v-if="kpi.trend !== undefined" class="report-trend sm" :class="trendClass(kpi.trend)">
                        <template v-if="kpi.trend !== null">{{ formatTrend(kpi.trend) }}</template>
                    </span>
                </div>
                <h3 class="report-kpi-value sm">{{ kpi.value }}</h3>
            </article>
        </section>

        <!-- ============ CHARTS ROW ============ -->
        <div class="report-charts-row">
            <!-- Revenue trend -->
            <div class="card report-chart-card">
                <div class="report-chart-head">
                    <div>
                        <h3 class="report-card-title">Revenue Trend</h3>
                        <p class="report-card-subtitle">Delivered-order revenue{{ revenueTrend?.comparisonRange ? ' vs. previous period' : '' }}</p>
                    </div>
                    <div v-if="revenueTrend && revenueTrend.allowedGranularities.length > 1" class="report-granularity-toggle" role="group" aria-label="Chart grouping">
                        <button
                            v-for="g in revenueTrend.allowedGranularities"
                            :key="g"
                            type="button"
                            class="report-granularity-btn"
                            :class="{ active: granularity === g }"
                            :aria-pressed="granularity === g"
                            @click="loadRevenueTrend(g)"
                        >
                            {{ granularityLabel(g) }}
                        </button>
                    </div>
                </div>

                <div v-if="isLoadingRevenueTrend && !revenueTrend" class="report-chart-skeleton" aria-hidden="true">
                    <div class="report-skeleton-line" style="width: 100%; height: 220px; border-radius: 0.75rem"></div>
                </div>

                <div v-else-if="revenueTrendError" class="empty-state">
                    <p style="font-weight: 700; color: #b91c1c">Couldn't load the revenue chart</p>
                    <p class="empty-hint">{{ revenueTrendError }}</p>
                    <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadRevenueTrend()">Try again</button>
                </div>

                <div v-else-if="revenueTrend && revenueTrend.current.every((b) => b.orderCount === 0)" class="empty-state">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 21h18M6 21V10M12 21V4M18 21v-7" /></svg>
                    <p style="font-weight: 700; color: #1e293b">No completed sales in this period</p>
                    <p class="empty-hint">Revenue only counts Delivered orders. Try a wider date range.</p>
                </div>

                <template v-else-if="revenueTrend">
                    <div class="report-chart-canvas-wrap">
                        <canvas ref="revenueCanvasEl" role="img" :aria-label="revenueChartAriaLabel"></canvas>
                    </div>
                    <!-- Accessible text summary — a canvas chart has nothing for a
                         screen reader to read, so the same data is repeated here
                         as a real table rather than only living inside the chart. -->
                    <table class="sr-only">
                        <caption>Revenue by {{ granularity }}, current vs. previous period</caption>
                        <thead>
                            <tr><th scope="col">Date</th><th scope="col">Revenue</th><th scope="col">Previous period</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(b, i) in revenueTrend.current" :key="b.date">
                                <td>{{ b.date }}</td>
                                <td>{{ formatCurrency(b.revenue) }}</td>
                                <td>{{ revenueTrend.previous ? formatCurrency(revenueTrend.previous[i]?.revenue ?? 0) : 'No comparison data' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </div>

            <!-- Order breakdown -->
            <div class="card report-chart-card breakdown">
                <div class="report-chart-head">
                    <div>
                        <h3 class="report-card-title">Order Breakdown</h3>
                        <p class="report-card-subtitle">By current status · click a status to filter Orders</p>
                    </div>
                </div>

                <div v-if="isLoadingOrderBreakdown && !orderBreakdown" class="report-chart-skeleton" aria-hidden="true">
                    <div class="report-skeleton-line" style="width: 12rem; height: 12rem; border-radius: 50%; margin: 0 auto"></div>
                </div>

                <div v-else-if="orderBreakdownError" class="empty-state">
                    <p style="font-weight: 700; color: #b91c1c">Couldn't load the order breakdown</p>
                    <p class="empty-hint">{{ orderBreakdownError }}</p>
                    <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadOrderBreakdown">Try again</button>
                </div>

                <div v-else-if="orderBreakdown && orderBreakdown.total === 0" class="empty-state">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" /><path d="M9 9h6v6H9z" /></svg>
                    <p style="font-weight: 700; color: #1e293b">No orders during the selected period</p>
                    <p class="empty-hint">Try a wider date range.</p>
                </div>

                <template v-else-if="orderBreakdown">
                    <div class="report-donut-wrap">
                        <canvas ref="breakdownCanvasEl" role="img" :aria-label="breakdownChartAriaLabel"></canvas>
                        <div class="report-donut-center">
                            <span class="report-donut-total">{{ orderBreakdown.total }}</span>
                            <span class="report-donut-total-label">Total Orders</span>
                        </div>
                    </div>
                    <!-- Legend doubles as the accessible summary and the
                         click-to-filter control — never relies on color alone. -->
                    <ul class="report-breakdown-legend">
                        <li v-for="seg in orderBreakdown.segments" :key="seg.status">
                            <button type="button" class="report-legend-item" @click="goToOrdersFiltered(seg.status)">
                                <span class="report-legend-dot" :class="statusDotClass(seg.status)"></span>
                                <span class="report-legend-label">{{ seg.status }}</span>
                                <span class="report-legend-value">{{ seg.count }} ({{ seg.percent }}%)</span>
                            </button>
                        </li>
                    </ul>
                </template>
            </div>
        </div>

        <!-- ============ BOTTOM ROW ============ -->
        <div class="report-bottom-row">
            <!-- Store snapshot (replaces the reference's fake hardcoded targets —
                 this project has no targets/goals table, see controller docblock) -->
            <div class="card report-snapshot-card">
                <h3 class="report-card-title">Store Snapshot</h3>

                <div v-if="orderBreakdown && orderBreakdown.total > 0" class="report-snapshot-bars">
                    <div v-for="seg in orderBreakdown.segments" :key="seg.status" class="report-snapshot-row">
                        <div class="report-snapshot-row-top">
                            <span>{{ seg.status }}</span>
                            <span>{{ seg.count }}</span>
                        </div>
                        <div class="report-snapshot-bar-track">
                            <div class="report-snapshot-bar-fill" :class="statusDotClass(seg.status)" :style="{ width: seg.percent + '%' }"></div>
                        </div>
                    </div>
                </div>
                <p v-else class="report-context-empty">No orders yet in this period.</p>

                <div class="report-snapshot-divider"></div>

                <div v-if="bestSellingProduct">
                    <p class="report-snapshot-label">Best Selling Product</p>
                    <div class="report-best-product">
                        <img v-if="bestSellingProduct.image" :src="bestSellingProduct.image" :alt="bestSellingProduct.name" />
                        <div v-else class="feedback-product-thumb-placeholder" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></svg>
                        </div>
                        <div>
                            <p class="report-best-product-name">{{ bestSellingProduct.name }}</p>
                            <p class="report-best-product-meta">{{ formatCurrency(bestSellingProduct.revenue) }} · {{ bestSellingProduct.unitsSold }} sold</p>
                        </div>
                    </div>
                </div>
                <p v-else class="report-context-empty">No product sales in this period yet.</p>
            </div>

            <!-- Top performing products -->
            <div class="card report-products-card">
                <div class="report-chart-head">
                    <h3 class="report-card-title">Top Performing Products</h3>
                    <div class="report-sort-toggle" role="group" aria-label="Sort products by">
                        <button
                            v-for="s in sortOptions"
                            :key="s.value"
                            type="button"
                            class="report-granularity-btn"
                            :class="{ active: topProductsSort === s.value }"
                            :aria-pressed="topProductsSort === s.value"
                            @click="loadTopProducts(s.value)"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                </div>
                <button type="button" class="report-view-all-link" @click="goToInventory">View All Products</button>

                <div v-if="isLoadingTopProducts && !topProducts.length" class="report-products-skeleton" aria-hidden="true">
                    <div v-for="n in 3" :key="n" class="report-skeleton-line" style="width: 100%; height: 3rem"></div>
                </div>

                <div v-else-if="topProductsError" class="empty-state">
                    <p style="font-weight: 700; color: #b91c1c">Couldn't load product performance</p>
                    <p class="empty-hint">{{ topProductsError }}</p>
                    <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadTopProducts()">Try again</button>
                </div>

                <div v-else-if="!topProducts.length" class="empty-state">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 7 9 18l-5-5" /></svg>
                    <p style="font-weight: 700; color: #1e293b">No product performance data</p>
                    <p class="empty-hint">No products sold during the selected period.</p>
                </div>

                <template v-else>
                    <div class="report-products-table" role="table" aria-label="Top performing products">
                        <div class="report-products-row report-products-head" role="row">
                            <span role="columnheader">Product</span>
                            <span role="columnheader">Units</span>
                            <span role="columnheader">Revenue</span>
                            <span role="columnheader">Growth</span>
                        </div>
                        <div v-for="p in topProducts" :key="p.productId" class="report-products-row" role="row">
                            <div class="report-product-cell" role="cell">
                                <img v-if="p.image" :src="p.image" :alt="p.name" />
                                <div v-else class="feedback-product-thumb-placeholder" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></svg>
                                </div>
                                <div>
                                    <p class="report-product-name">{{ p.name }}</p>
                                    <p class="report-product-sku">{{ p.sku ? `SKU: ${p.sku}` : '' }}</p>
                                </div>
                            </div>
                            <span role="cell" data-label="Units">{{ p.unitsSold }}</span>
                            <span role="cell" data-label="Revenue" class="report-product-revenue">{{ formatCurrency(p.revenue) }}</span>
                            <span role="cell" data-label="Growth" class="report-trend" :class="trendClass(p.growth)">
                                <template v-if="p.growth === null">No comparison data</template>
                                <template v-else>{{ formatTrend(p.growth) }}</template>
                            </span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
    DoughnutController,
    ArcElement,
} from 'chart.js';
import { useReports } from '../composables/useReports';
import { useOrders } from '../composables/useOrders';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend, Filler, DoughnutController, ArcElement);

const {
    preset,
    range,
    rangeLabel,
    applyPreset,
    applyCustomRange,
    resetFilters,
    initFromUrl,

    summary,
    isLoadingSummary,
    summaryError,
    loadSummary,

    revenueTrend,
    granularity,
    isLoadingRevenueTrend,
    revenueTrendError,
    loadRevenueTrend,

    orderBreakdown,
    isLoadingOrderBreakdown,
    orderBreakdownError,
    loadOrderBreakdown,

    topProducts,
    topProductsSort,
    isLoadingTopProducts,
    topProductsError,
    loadTopProducts,

    loadAll,

    isExporting,
    exportError,
    exportCsv,
} = useReports();

const { formatCurrency } = useOrders();

const presetOptions = [
    { value: 'today', label: 'Today' },
    { value: 'last7', label: 'Last 7 Days' },
    { value: 'last30', label: 'Last 30 Days' },
    { value: 'thisMonth', label: 'This Month' },
    { value: 'lastMonth', label: 'Last Month' },
];
const sortOptions = [
    { value: 'revenue', label: 'Revenue' },
    { value: 'units', label: 'Units' },
    { value: 'orders', label: 'Orders' },
];

const showRangeMenu = ref(false);
const rangeMenuEl = ref(null);
const customFromInput = ref('');
const customToInput = ref('');
const customRangeError = ref('');

function selectPreset(value) {
    applyPreset(value);
    showRangeMenu.value = false;
}

function submitCustomRange() {
    customRangeError.value = '';

    if (!customFromInput.value || !customToInput.value) {
        customRangeError.value = 'Pick both a start and end date.';
        return;
    }
    if (customToInput.value < customFromInput.value) {
        customRangeError.value = 'End date must be on or after the start date.';
        return;
    }

    applyCustomRange(customFromInput.value, customToInput.value);
    showRangeMenu.value = false;
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

const lastUpdatedLabel = computed(() => {
    if (!summary.value?.generatedAt) return '';
    return new Date(summary.value.generatedAt).toLocaleString(undefined, {
        month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    });
});

function formatRangeShort(r) {
    const fmt = (s) => new Date(`${s}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    return `${fmt(r.from)} – ${fmt(r.to)}`;
}

function formatTrend(v) {
    if (v === null || v === undefined) return 'No comparison data';
    const sign = v > 0 ? '+' : '';
    return `${sign}${v}%`;
}
function trendClass(v) {
    if (v === null || v === undefined) return 'flat';
    return v > 0 ? 'up' : v < 0 ? 'down' : 'flat';
}

function kpiIcon(name) {
    const icons = {
        wallet: '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20M16 14h2"/>',
        box: '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        check: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        star: '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2Z"/>',
    };
    return icons[name] || '';
}

const mainKpis = computed(() => {
    if (!summary.value) return [];
    const m = summary.value.metrics;

    return [
        { key: 'revenue', label: 'Net Revenue', value: formatCurrency(m.netRevenue.value ?? 0), trend: m.netRevenue.trend, icon: 'wallet', iconClass: 'teal' },
        { key: 'orders', label: 'Delivered Orders', value: m.deliveredOrders.value ?? 0, trend: m.deliveredOrders.trend, icon: 'box', iconClass: 'blue' },
        {
            key: 'fulfillment',
            label: 'Fulfillment Rate',
            value: m.fulfillmentRate.value != null ? `${m.fulfillmentRate.value}%` : 'No data',
            trend: m.fulfillmentRate.trend,
            icon: 'check',
            iconClass: 'emerald',
        },
        {
            key: 'rating',
            label: 'Average Rating',
            value: m.averageRating.value != null ? `${m.averageRating.value}/5.0` : 'No reviews yet',
            trend: m.averageRating.value != null ? m.averageRating.trend : undefined,
            icon: 'star',
            iconClass: 'amber',
            sub: summary.value.ratingCount > 0 ? `${summary.value.ratingCount} review${summary.value.ratingCount > 1 ? 's' : ''}` : null,
        },
    ];
});

const secondaryKpis = computed(() => {
    if (!summary.value) return [];
    const m = summary.value.metrics;

    return [
        { key: 'aov', label: 'Average Order Value', value: m.averageOrderValue.value != null ? formatCurrency(m.averageOrderValue.value) : 'No data', trend: m.averageOrderValue.trend },
        { key: 'cancel', label: 'Cancellation Rate', value: m.cancellationRate.value != null ? `${m.cancellationRate.value}%` : 'No data', trend: m.cancellationRate.trend },
        {
            key: 'refund',
            label: 'Refund Rate',
            value: m.refundRate.value != null ? `${m.refundRate.value}%` : 'No data',
            trend: m.refundRate.trend,
            tip: 'Based on orders marked Refunded at checkout. This project tracks refunds per order, not per item, so this is an approximation.',
        },
    ];
});

const bestSellingProduct = computed(() => {
    if (topProductsSort.value !== 'revenue' || !topProducts.value.length) return null;
    const top = topProducts.value[0];
    return { name: top.name, image: top.image, revenue: top.revenue, unitsSold: top.unitsSold };
});

function granularityLabel(g) {
    return { day: 'Daily', week: 'Weekly', month: 'Monthly' }[g] || g;
}

// Deliberately NOT reusing useOrders.statusBadgeClass here: that
// mapping collapses New and In Transit to the same "sky" badge color
// (fine for a single-status badge, but this chart shows all 5 statuses
// at once and needs 5 visually distinct, chart-matching colors — see
// STATUS_COLORS below, which this must stay in exact sync with).
const STATUS_DOT_CLASS = {
    New: 'dot-new',
    Processing: 'dot-processing',
    'In Transit': 'dot-intransit',
    Delivered: 'dot-delivered',
    Cancelled: 'dot-cancelled',
};
function statusDotClass(status) {
    return STATUS_DOT_CLASS[status] || 'dot-slate';
}

function goToOrdersFiltered(status) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section: 'orders', statusFilter: status } }));
}
function goToInventory() {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: 'inventory' }));
}

const revenueChartAriaLabel = computed(() => {
    if (!revenueTrend.value) return 'Revenue trend chart';
    return `Revenue trend, ${granularity.value}, ${revenueTrend.value.current.length} data points. See the table below the chart for exact values.`;
});
const breakdownChartAriaLabel = computed(() => {
    if (!orderBreakdown.value) return 'Order status breakdown chart';
    return `Order breakdown: ${orderBreakdown.value.segments.map((s) => `${s.status} ${s.count}`).join(', ')}.`;
});

// ---- Chart.js instances (plain vars, not reactive — Chart.js manages
// its own internal state and re-creating from scratch on every data
// change is both wasteful and visually jarring) ----
const revenueCanvasEl = ref(null);
const breakdownCanvasEl = ref(null);
let revenueChart = null;
let breakdownChart = null;

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

const STATUS_COLORS = {
    New: '#0284c7',
    Processing: '#d97706',
    'In Transit': '#2c5aa0',
    Delivered: '#059669',
    Cancelled: '#ef4444',
};

function renderRevenueChart() {
    if (!revenueCanvasEl.value || !revenueTrend.value) return;

    const labels = revenueTrend.value.current.map((b) => b.date);
    const currentData = revenueTrend.value.current.map((b) => b.revenue);
    const previousData = revenueTrend.value.previous?.map((b) => b.revenue) ?? null;

    const datasets = [
        {
            label: 'This period',
            data: currentData,
            borderColor: '#1b9ba8',
            backgroundColor: 'rgba(27, 155, 168, 0.12)',
            fill: true,
            tension: 0.3,
            pointRadius: 2,
            pointHoverRadius: 5,
            borderWidth: 3,
        },
    ];

    if (previousData) {
        datasets.push({
            label: 'Previous period',
            data: previousData,
            borderColor: '#cbd5e1',
            backgroundColor: 'transparent',
            borderDash: [6, 4],
            tension: 0.3,
            pointRadius: 0,
            borderWidth: 2,
        });
    }

    revenueChart?.destroy();
    revenueChart = new Chart(revenueCanvasEl.value, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: prefersReducedMotion() ? false : { duration: 350 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: !!previousData, position: 'top', align: 'end', labels: { boxWidth: 12, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        title: (items) => items[0]?.label,
                        label: (item) => `${item.dataset.label}: ${formatCurrency(item.parsed.y)}`,
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
                y: { beginAtZero: true, ticks: { callback: (v) => `₱${v}` } },
            },
        },
    });
}

function renderBreakdownChart() {
    if (!breakdownCanvasEl.value || !orderBreakdown.value) return;

    const segments = orderBreakdown.value.segments.filter((s) => s.count > 0);

    breakdownChart?.destroy();
    breakdownChart = new Chart(breakdownCanvasEl.value, {
        type: 'doughnut',
        data: {
            labels: segments.map((s) => s.status),
            datasets: [{
                data: segments.map((s) => s.count),
                backgroundColor: segments.map((s) => STATUS_COLORS[s.status] || '#94a3b8'),
                borderWidth: 2,
                borderColor: '#ffffff',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            animation: prefersReducedMotion() ? false : { duration: 350 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (item) => {
                            const seg = segments[item.dataIndex];
                            return `${seg.status}: ${seg.count} (${seg.percent}%)`;
                        },
                    },
                },
            },
        },
    });
}

watch(revenueTrend, () => nextTick(renderRevenueChart));
watch(orderBreakdown, () => nextTick(renderBreakdownChart));

onMounted(() => {
    initFromUrl();
    customFromInput.value = range.value.from;
    customToInput.value = range.value.to;
    loadAll();
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onEscKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onEscKey);
    revenueChart?.destroy();
    breakdownChart?.destroy();
});
</script>