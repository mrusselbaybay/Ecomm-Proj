<!-- resources/js/seller/components/Dashboard.vue -->
<template>
    <div class="seller-dashboard">
        <!-- KPI Cards -->
        <div class="metric-grid kpi-grid grid">
            <div class="metric-card">
                <div class="metric-card-top">
                    <p class="metric-label">Total Sales</p>
                    <span class="metric-chip" :class="changeChipClass(salesChangeLabel)">{{
                        salesChangeLabel
                    }}</span>
                </div>
                <div class="kpi-body">
                    <h4 class="metric-value">{{ formatCurrencyValue(totalSales) }}</h4>
                    <svg class="kpi-spark" width="72" height="28" viewBox="0 0 72 28">
                        <polyline
                            :points="salesSparkline"
                            fill="none"
                            style="stroke: var(--sd-accent)"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <p class="metric-sub">Gross value of every order</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <p class="metric-label">Total Revenue</p>
                    <span class="metric-chip" :class="changeChipClass(revenueChangeLabel)">{{
                        revenueChangeLabel
                    }}</span>
                </div>
                <div class="kpi-body">
                    <h4 class="metric-value">{{ formatCurrencyValue(totalRevenue) }}</h4>
                    <svg class="kpi-spark" width="72" height="28" viewBox="0 0 72 28">
                        <polyline
                            :points="revenueSparkline"
                            fill="none"
                            style="stroke: var(--sd-accent-2)"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <p class="metric-sub">Collected from paid orders</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <p class="metric-label">Orders</p>
                    <span
                        class="metric-chip"
                        :class="thisWeekOrders.length > 0 ? 'up' : 'flat'"
                    >{{ thisWeekOrders.length > 0 ? '+' + thisWeekOrders.length + ' this week' : 'No new' }}</span>
                </div>
                <div class="kpi-body">
                    <h4 class="metric-value">{{ orders.length }} Orders</h4>
                    <svg class="kpi-spark" width="72" height="28" viewBox="0 0 72 28">
                        <polyline
                            :points="ordersSparkline"
                            fill="none"
                            style="stroke: var(--sd-accent)"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <p class="metric-sub">All-time, every status</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <p class="metric-label">Fulfillment Rate</p>
                    <span class="metric-chip flat">{{ fulfillmentRateLabel }}</span>
                </div>
                <div class="kpi-body">
                    <h4 class="metric-value">{{ completedOrdersCount }} Orders</h4>
                    <svg class="kpi-spark" width="72" height="28" viewBox="0 0 72 28">
                        <polyline
                            :points="deliveredSparkline"
                            fill="none"
                            style="stroke: var(--sd-good)"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <p class="metric-sub">Delivered to the buyer</p>
            </div>
        </div>

        <!-- Attention Strip -->
        <div class="strip">
            <div class="strip-chip">
                <span class="strip-ic warn"
                    ><svg
                        width="17"
                        height="17"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 3" /></svg
                ></span>
                <div class="strip-text">
                    <div class="t1">Pending orders</div>
                    <div class="t2">{{ pendingOrdersCount }} need action</div>
                </div>
            </div>
            <div class="strip-chip">
                <span class="strip-ic bad"
                    ><svg
                        width="17"
                        height="17"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path d="m12 2 9 5-9 5-9-5 9-5Z" />
                        <path d="m3 12 9 5 9-5" />
                        <path d="m3 17 9 5 9-5" /></svg
                ></span>
                <div class="strip-text">
                    <div class="t1">Low / out of stock</div>
                    <div class="t2">{{ lowStockProductsCount }} products</div>
                </div>
            </div>
            <div class="strip-chip">
                <span class="strip-ic good"
                    ><svg
                        width="17"
                        height="17"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <circle cx="10" cy="10" r="8" />
                        <path d="M10 6v8M6 10h8" /></svg
                ></span>
                <div class="strip-text">
                    <div class="t1">Active listings</div>
                    <div class="t2">{{ activeProductsCount }} live</div>
                </div>
            </div>
        </div>

        <!-- Sales Trend + Order Breakdown -->
        <div class="chart-row grid">
            <div class="card chart-card">
                <div class="chart-card-head">
                    <div>
                        <p class="chart-title">Sales Performance Trend</p>
                        <p class="chart-sub">Revenue over the past 7 days</p>
                    </div>
                    <div class="chart-toggle">
                        <button class="active" type="button">Weekly</button>
                        <button type="button">Monthly</button>
                    </div>
                </div>
                <div style="height: 220px; width: 100%; position: relative">
                    <svg
                        viewBox="0 0 800 200"
                        style="width: 100%; height: 100%; overflow: visible"
                    >
                        <defs>
                            <linearGradient
                                id="seller-line-gradient"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stop-color="#14b8a6"
                                    stop-opacity="0.3"
                                ></stop>
                                <stop
                                    offset="100%"
                                    stop-color="#14b8a6"
                                    stop-opacity="0"
                                ></stop>
                            </linearGradient>
                        </defs>
                        <path
                            :d="salesTrendLinePath"
                            fill="none"
                            stroke="#14b8a6"
                            stroke-width="4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        ></path>
                        <path
                            :d="salesTrendAreaPath"
                            fill="url(#seller-line-gradient)"
                        ></path>
                        <g v-for="(pt, idx) in salesTrendPoints" :key="idx">
                            <circle
                                :cx="pt.x"
                                :cy="pt.y"
                                :r="pt === salesTrendPeak ? 6 : 4"
                                fill="#14b8a6"
                                stroke="white"
                                stroke-width="3"
                            ></circle>
                            <circle
                                :cx="pt.x"
                                :cy="pt.y"
                                r="18"
                                fill="transparent"
                                style="cursor: pointer"
                            >
                                <title>{{ pt.label }}: {{ formatCurrencyValue(pt.total) }}</title>
                            </circle>
                        </g>
                    </svg>
                </div>
                <div class="chart-x-labels">
                    <span v-for="(day, idx) in salesTrendDays" :key="idx">{{
                        day.label
                    }}</span>
                </div>
            </div>

            <div class="card chart-card">
                <div class="chart-card-head">
                    <p class="chart-title">Order Breakdown</p>
                    <span class="sd-live-badge"><span class="live-dot"></span>Live</span>
                </div>
                <div
                    class="flex items-center justify-center"
                    style="flex-direction: column"
                >
                    <div
                        style="
                            position: relative;
                            width: 9.5rem;
                            height: 9.5rem;
                            margin-bottom: 1.5rem;
                        "
                    >
                        <svg
                            viewBox="0 0 36 36"
                            style="
                                width: 100%;
                                height: 100%;
                                transform: rotate(-90deg);
                            "
                        >
                            <circle
                                cx="18"
                                cy="18"
                                r="15.9"
                                fill="transparent"
                                style="stroke: var(--sd-hairline)"
                                stroke-width="4"
                            ></circle>
                            <circle
                                v-for="seg in orderDonutSegments"
                                :key="seg.key"
                                cx="18"
                                cy="18"
                                r="15.9"
                                fill="transparent"
                                :stroke="seg.color"
                                stroke-width="4"
                                :stroke-dasharray="`${seg.pct} ${100 - seg.pct}`"
                                :stroke-dashoffset="seg.dashoffset"
                            ></circle>
                        </svg>
                        <div
                            class="flex items-center justify-center"
                            style="
                                position: absolute;
                                inset: 0;
                                flex-direction: column;
                            "
                        >
                            <span class="donut-center-value">{{ orderBreakdownTotal }}</span>
                            <span class="donut-center-label">Orders</span>
                        </div>
                    </div>
                    <div
                        v-if="orderBreakdownTotal === 0"
                        class="empty-state"
                        style="padding: 0 0 1rem"
                    >
                        <p>No orders yet.</p>
                    </div>
                    <div v-else style="width: 100%">
                        <div
                            v-for="seg in orderDonutSegments"
                            :key="seg.key"
                            class="donut-legend-row"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="legend-dot"
                                    :style="{ background: seg.color }"
                                ></span
                                ><span>{{ seg.label }} ({{ seg.count }})</span>
                            </div>
                            <strong>{{ seg.pct }}%</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Sales Records + Live Store Activity -->
        <div class="bottom-grid mb-6 grid">
            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Recent Sales Records</h3>
                    <a
                        href="#"
                        class="panel-link"
                        @click.prevent="goTo('orders')"
                        >View All Transactions</a
                    >
                </div>
                <div style="overflow-x: auto">
                    <table class="sales-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="recentOrders.length === 0">
                                <td colspan="6" style="text-align: center; color: var(--sd-ink-500); padding: 1.5rem 0">
                                    No orders yet.
                                </td>
                            </tr>
                            <tr v-for="row in recentOrders" :key="row.id">
                                <td class="order-id">{{ row.id }}</td>
                                <td class="customer">
                                    <span class="sd-customer-avatar">{{ customerInitials(row.customer) }}</span>
                                    {{ row.customer }}
                                </td>
                                <td class="item-name">{{ orderItemsSummary(row) }}</td>
                                <td>{{ row.date }}</td>
                                <td class="amount">{{ formatCurrency(row.total) }}</td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="orderStatusBadgeClass(row.status)"
                                        >{{ row.status }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Live Store Activity</h3>
                    <div class="flex items-center gap-3">
                        <button
                            class="notif-btn"
                            title="Refresh status"
                            style="padding: 0.3rem"
                            @click="refresh"
                            :disabled="isRefreshing"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                :style="{
                                    animation: isRefreshing
                                        ? 'spin 0.7s linear infinite'
                                        : 'none',
                                }"
                            >
                                <path
                                    d="M3 10a7 7 0 0 1 12-5l1.5 1.5M17 10a7 7 0 0 1-12 5L3.5 13.5"
                                />
                                <path d="M14.5 3v3.5H11M5.5 17v-3.5H9" />
                            </svg>
                        </button>
                        <span class="sd-live-badge"><span class="live-dot"></span>Live</span>
                    </div>
                </div>
                <div class="activity-panel">
                    <div
                        v-if="mergedActivityLog.length === 0"
                        class="empty-state"
                        style="padding: 1rem 0"
                    >
                        <p>No activity yet.</p>
                        <p class="empty-hint">
                            New orders and account status changes will show
                            up here.
                        </p>
                    </div>
                    <div
                        v-for="item in visibleActivityLog"
                        :key="item.id"
                        class="activity-row"
                    >
                        <div
                            class="activity-icon-badge"
                            :class="item.type === 'order' ? 'blue' : 'teal'"
                        >
                            <svg
                                v-if="item.type === 'order'"
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M4 7h16l-1.5 11a2 2 0 0 1-2 1.8H7.5a2 2 0 0 1-2-1.8L4 7Z" />
                                <path d="M8 7V5a4 4 0 0 1 8 0v2" />
                            </svg>
                            <svg
                                v-else
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v5l3 3" />
                            </svg>
                        </div>
                        <div v-if="item.type === 'order'" class="flex-1">
                            <p class="activity-text">{{ item.text }}</p>
                            <p class="activity-time">
                                {{ formatDateTime(item.time) }}
                            </p>
                        </div>
                        <div v-else class="flex-1">
                            <p class="activity-text">
                                Status changed to
                                <span style="text-transform: capitalize">{{
                                    item.raw.new_status
                                }}</span>
                            </p>
                            <p class="activity-time">
                                {{ formatDateTime(item.raw.created_at)
                                }}<span v-if="item.raw.reason">
                                    — {{ item.raw.reason }}</span
                                >
                            </p>
                        </div>
                    </div>
                    <button
                        v-if="visibleActivityLog.length"
                        class="activity-clear-btn"
                        @click="visibleActivityLog = []"
                    >
                        Clear Activity Log
                    </button>
                </div>
            </div>
        </div>

        <!-- Best-Selling Products + Low-Stock Products -->
        <div class="bottom-grid mb-6 grid">
            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Best-Selling Products</h3>
                    <a href="#" class="panel-link" @click.prevent="goTo('inventory')">View Inventory</a>
                </div>

                <ol v-if="bestSellers.length" class="rank-list">
                    <li v-for="(p, i) in bestSellers" :key="p.name" class="rank-item">
                        <span class="rank-num">{{ i + 1 }}</span>
                        <div class="rank-body">
                            <div class="rank-row">
                                <span class="rank-name">{{ p.name }}</span>
                                <span class="rank-units">{{ p.units }} sold</span>
                            </div>
                            <div class="rank-bar"><span :style="{ width: p.pct + '%' }"></span></div>
                            <span class="rank-sub">{{ formatCurrency(p.revenue) }} in sales</span>
                        </div>
                    </li>
                </ol>
                <div v-else class="empty-state" style="padding: 1.5rem 0">
                    <p>No sales yet.</p>
                </div>
            </div>

            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Low-Stock Products</h3>
                    <a href="#" class="panel-link" @click.prevent="goTo('inventory')">Manage Stock</a>
                </div>

                <ul v-if="lowStockItems.length" class="stock-list">
                    <li v-for="p in lowStockItems" :key="p.id" class="stock-item">
                        <span class="stock-name">{{ p.name }}</span>
                        <span
                            class="stock-qty"
                            :class="p.stock === 0 ? 'is-out' : 'is-low'"
                        >{{ p.stock === 0 ? 'Out of stock' : p.stock + ' left' }}</span>
                    </li>
                </ul>
                <div v-else class="empty-state" style="padding: 1.5rem 0">
                    <p>Every product is well stocked.</p>
                </div>
            </div>
        </div>

        <!-- Store Health — compact summary of the same readiness/compliance
             signal the old two-card section showed; per-document detail
             (type, date, status) lives on Account → Compliance instead of
             being duplicated here. -->
        <div class="sd-store-health">
            <svg class="sd-health-ring" width="72" height="72" viewBox="0 0 72 72">
                <circle cx="36" cy="36" r="30" fill="none" style="stroke: var(--sd-hairline)" stroke-width="8" />
                <circle
                    cx="36"
                    cy="36"
                    r="30"
                    fill="none"
                    style="stroke: var(--sd-accent)"
                    stroke-width="8"
                    stroke-linecap="round"
                    :stroke-dasharray="`${(storeHealthPct / 100) * 188.5} 188.5`"
                    transform="rotate(-90 36 36)"
                />
                <text x="36" y="41" text-anchor="middle" class="sd-health-pct">{{ storeHealthPct }}%</text>
            </svg>
            <div class="sd-health-copy">
                <p class="sd-health-label">Store Health</p>
                <p class="sd-health-title">
                    {{
                        storeHealthPct === 100
                            ? 'All set — your store is in good standing.'
                            : 'Almost there — finish these steps to keep your store in good standing.'
                    }}
                </p>
                <div class="sd-health-checks">
                    <span class="sd-health-check" :class="hasProfileInfo ? 'done' : 'todo'">
                        <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 10l4 4 8-8" /></svg>
                        Personal information
                    </span>
                    <span class="sd-health-check" :class="hasAddress ? 'done' : 'todo'">
                        <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 10l4 4 8-8" /></svg>
                        Store address
                    </span>
                    <span class="sd-health-check" :class="hasBusinessInfo ? 'done' : 'todo'">
                        <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 10l4 4 8-8" /></svg>
                        Business details
                    </span>
                    <span
                        class="sd-health-check"
                        :class="verifiedDocsCount === totalDocsCount && totalDocsCount > 0 ? 'done' : 'todo'"
                    >
                        <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 10l4 4 8-8" /></svg>
                        Compliance documents
                    </span>
                </div>
            </div>
            <div class="sd-health-docs">
                <span><strong>{{ verifiedDocsCount }}</strong> verified</span>
                <span><strong>{{ pendingDocsCount }}</strong> pending</span>
                <span><strong>{{ rejectedDocsCount }}</strong> rejected</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useOrders } from '../composables/useOrders';
import { useSeller } from '../composables/useSeller';
import { useSellerProducts } from '../composables/useSellerProducts';

const {
    profile,
    address,
    sellerDetails,
    documents,
    activityLog,
    verifiedDocsCount,
    pendingDocsCount,
    totalDocsCount,
    refreshAll,
    formatDateTime,
} = useSeller();

const {
    orders,
    loadOrders,
    statusBadgeClass: orderStatusBadgeClass,
    formatCurrency,
} = useOrders();

const {
    products,
    loadProducts,
    stockStatusOf,
} = useSellerProducts();

// Sequenced, not Promise.all — the local dev server (php artisan serve)
// handles one request at a time, so firing these together doesn't load
// them any faster, it just means whichever one the server gets to last
// has to sit waiting. Awaiting them one at a time keeps each request's
// own execution short once it actually starts.
onMounted(async () => {
    await loadOrders();
    await loadProducts();
});

// Orders are created buyer-side straight in Supabase (not through this
// Laravel API — see useOrders.js's own header comment), so a brand new
// order placed while a seller is just sitting on the dashboard would
// otherwise never show up until they navigate away and back. Poll on
// the same cadence as the orders cache's own TTL (ORDERS_CACHE_TTL_MS
// in useOrders.js) so each tick lands right as the cache goes stale —
// a real fetch, not a wasted one — same 30s rhythm the notification
// bell and message threads already poll at.
const ORDERS_POLL_MS = 30 * 1000;
let ordersPollTimer = null;

onMounted(() => {
    ordersPollTimer = setInterval(() => loadOrders(), ORDERS_POLL_MS);
});

onBeforeUnmount(() => {
    clearInterval(ordersPollTimer);
});

const isRefreshing = ref(false);

async function refresh() {
    isRefreshing.value = true;

    try {
        await refreshAll();
        await loadOrders();
        await loadProducts();
    } finally {
        isRefreshing.value = false;
    }
}

function goTo(section) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: section }));
}

// ---------------------------------------------------------------
// REAL METRICS — sourced from the same orders/products the Orders and
// Inventory pages use (useOrders / useSellerProducts), not mock data.
// ---------------------------------------------------------------

function formatCurrencyValue(n) {
    return `₱${Number(n || 0).toFixed(2)}`;
}

function sumTotals(list) {
    return list.reduce((sum, o) => sum + (Number(o.total) || 0), 0);
}

// orders.*.placedAt is a real ISO timestamp (SellerOrderController@
// transformSummary) — used here instead of the pre-formatted `date`
// display string so week-over-week comparisons are actually reliable.
function ordersPlacedBetween(startMsAgo, endMsAgo) {
    const now = Date.now();
    const start = now - startMsAgo;
    const end = now - endMsAgo;

    return orders.value.filter((o) => {
        if (!o.placedAt) {
            return false;
        }

        const t = new Date(o.placedAt).getTime();

        return t > start && t <= end;
    });
}

const DAY_MS = 24 * 60 * 60 * 1000;
const thisWeekOrders = computed(() => ordersPlacedBetween(7 * DAY_MS, 0));
const lastWeekOrders = computed(() => ordersPlacedBetween(14 * DAY_MS, 7 * DAY_MS));

function pctChangeLabel(current, previous) {
    if (previous === 0) {
        return current > 0 ? '+100.0%' : '0.0%';
    }

    const change = ((current - previous) / previous) * 100;

    return `${change >= 0 ? '+' : ''}${change.toFixed(1)}%`;
}

function changeChipClass(label) {
    if (label.startsWith('+')) {
return 'up';
}

    if (label.startsWith('-')) {
return 'down';
}

    return 'flat';
}

// "Total Sales" = gross value of every order placed (sales volume);
// "Total Revenue" = value of orders actually paid — two genuinely
// different real numbers rather than the same figure twice.
const totalSales = computed(() => sumTotals(orders.value));
const totalRevenue = computed(
    () => sumTotals(orders.value.filter((o) => o.paymentStatus === 'Paid')),
);
const salesChangeLabel = computed(() =>
    pctChangeLabel(sumTotals(thisWeekOrders.value), sumTotals(lastWeekOrders.value)),
);
const revenueChangeLabel = computed(() =>
    pctChangeLabel(
        sumTotals(thisWeekOrders.value.filter((o) => o.paymentStatus === 'Paid')),
        sumTotals(lastWeekOrders.value.filter((o) => o.paymentStatus === 'Paid')),
    ),
);

const activeProductsCount = computed(
    () => products.value.filter((p) => p.status === 'active').length,
);
const lowStockProductsCount = computed(
    () => products.value.filter((p) => stockStatusOf(p) === 'low_stock').length,
);

// The actual low/out-of-stock products (not just a count) so the
// dashboard can list what needs restocking, worst first.
const lowStockItems = computed(() =>
    products.value
        .filter((p) => ['low_stock', 'out_of_stock'].includes(stockStatusOf(p)))
        .map((p) => ({ id: p.id, name: p.name, stock: Number(p.stock) || 0 }))
        .sort((a, b) => a.stock - b.stock)
        .slice(0, 6),
);

// "New" = placed but not yet accepted by the seller — the real
// equivalent of "pending" for orders (this used to accidentally show
// pendingDocsCount, a completely unrelated document-verification
// figure — fixed here to use real order data).
const pendingOrdersCount = computed(
    () => orders.value.filter((o) => o.status === 'New').length,
);
// "Completed" = fulfilled all the way to Delivered.
const completedOrdersCount = computed(
    () => orders.value.filter((o) => o.status === 'Delivered').length,
);
// Delivered / (Delivered + Cancelled) — orders still in flight aren't
// counted either way, matching Reports' fulfillment-rate definition.
const fulfillmentRateLabel = computed(() => {
    const delivered = completedOrdersCount.value;
    const cancelled = orders.value.filter((o) => o.status === 'Cancelled').length;
    const settled = delivered + cancelled;

    return settled === 0 ? '—' : `${Math.round((delivered / settled) * 100)}% fulfilled`;
});

// ---- Best-Selling Products (units sold across every order that
// actually resulted in a sale, from the same order data the Orders
// page loads) — Cancelled AND Rejected both excluded: a rejected order
// was never fulfilled either, so its items were never actually sold. ----
const bestSellers = computed(() => {
    const tally = new Map();

    for (const order of orders.value) {
        if (order.status === 'Cancelled' || order.status === 'Rejected' || !order.items?.length) {
            continue;
        }

        for (const item of order.items) {
            const key = item.name || 'Unnamed product';
            const row = tally.get(key) || { name: key, units: 0, revenue: 0 };

            row.units += Number(item.qty) || 0;
            row.revenue += (Number(item.qty) || 0) * (Number(item.price) || 0);
            tally.set(key, row);
        }
    }

    const rows = [...tally.values()].sort((a, b) => b.units - a.units).slice(0, 5);
    const top = rows[0]?.units || 1;

    return rows.map((r) => ({ ...r, pct: Math.round((r.units / top) * 100) }));
});

// ---- Sales Performance Trend (last 7 days, real daily totals) ----
const salesTrendDays = computed(() => {
    const days = [];
    const now = new Date();

    for (let i = 6; i >= 0; i--) {
        const d = new Date(now);
        d.setDate(d.getDate() - i);
        d.setHours(0, 0, 0, 0);
        days.push(d);
    }

    return days.map((d) => {
        const total = orders.value.reduce((sum, o) => {
            if (!o.placedAt) {
return sum;
}

            const placed = new Date(o.placedAt);
            const sameDay =
                placed.getFullYear() === d.getFullYear() &&
                placed.getMonth() === d.getMonth() &&
                placed.getDate() === d.getDate();

            return sameDay ? sum + (Number(o.total) || 0) : sum;
        }, 0);

        return { label: d.toLocaleDateString('en-US', { weekday: 'short' }), total };
    });
});

const salesTrendMax = computed(() => {
    const max = Math.max(...salesTrendDays.value.map((d) => d.total), 0);

    return max > 0 ? max : 1; // avoid a divide-by-zero flatline when everything is 0
});

const salesTrendPoints = computed(() => {
    const days = salesTrendDays.value;
    const n = days.length;

    return days.map((d, idx) => ({
        x: n > 1 ? (idx / (n - 1)) * 800 : 0,
        y: 190 - (d.total / salesTrendMax.value) * 170,
        total: d.total,
        label: d.label,
    }));
});

const salesTrendLinePath = computed(() => {
    const pts = salesTrendPoints.value;

    if (!pts.length) {
return '';
}

    return pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
});

const salesTrendAreaPath = computed(() => {
    const pts = salesTrendPoints.value;

    if (!pts.length) {
return '';
}

    const first = pts[0];

    return `${salesTrendLinePath.value} V200 H${first.x.toFixed(1)} Z`;
});

const salesTrendPeak = computed(() => {
    const pts = salesTrendPoints.value;

    if (!pts.length) {
return null;
}

    return pts.reduce((max, p) => (p.total > max.total ? p : max), pts[0]);
});

// ---- KPI sparklines (last 7 days, real per-day data — same window as
// the sales trend chart above, just three more angles on it) ----
function lastNDays(n) {
    const days = [];
    const now = new Date();

    for (let i = n - 1; i >= 0; i--) {
        const d = new Date(now);
        d.setDate(d.getDate() - i);
        d.setHours(0, 0, 0, 0);
        days.push(d);
    }

    return days;
}

function ordersOnDay(day) {
    return orders.value.filter((o) => {
        if (!o.placedAt) {
            return false;
        }

        const placed = new Date(o.placedAt);

        return (
            placed.getFullYear() === day.getFullYear() &&
            placed.getMonth() === day.getMonth() &&
            placed.getDate() === day.getDate()
        );
    });
}

// SVG `points` string for a 72×28 sparkline — normalized to the series'
// own min/max so a flat week still shows a visible (if flat) line.
function sparklinePoints(values) {
    const width = 72;
    const height = 28;
    const max = Math.max(...values, 0);
    const min = Math.min(...values, 0);
    const range = max - min || 1;
    const n = values.length;

    return values
        .map((v, i) => {
            const x = n > 1 ? (i / (n - 1)) * width : 0;
            const y = height - ((v - min) / range) * height;

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
}

const revenueTrendValues = computed(() =>
    lastNDays(7).map((d) => sumTotals(ordersOnDay(d).filter((o) => o.paymentStatus === 'Paid'))),
);
const orderCountTrendValues = computed(() => lastNDays(7).map((d) => ordersOnDay(d).length));
const deliveredTrendValues = computed(() =>
    lastNDays(7).map((d) => ordersOnDay(d).filter((o) => o.status === 'Delivered').length),
);

const salesSparkline = computed(() => sparklinePoints(salesTrendDays.value.map((d) => d.total)));
const revenueSparkline = computed(() => sparklinePoints(revenueTrendValues.value));
const ordersSparkline = computed(() => sparklinePoints(orderCountTrendValues.value));
const deliveredSparkline = computed(() => sparklinePoints(deliveredTrendValues.value));

// ---- Order Breakdown donut (real order status counts) ----
// The r=15.9 circle trick: circumference = 2π×15.9 ≈ 99.9 ≈ 100, so a
// percentage value can be used directly as the stroke-dasharray length
// without any extra circumference math.
const orderStatusCounts = computed(() => {
    const counts = { delivered: 0, inTransit: 0, processing: 0 };

    for (const o of orders.value) {
        if (o.status === 'Delivered') {
counts.delivered++;
} else if (o.status === 'In Transit') {
counts.inTransit++;
} else if (o.status === 'New' || o.status === 'Processing') {
counts.processing++;
}
        // Cancelled orders are excluded from this breakdown, matching
        // the original 3-segment design.
    }

    return counts;
});

const orderBreakdownTotal = computed(
    () =>
        orderStatusCounts.value.delivered +
        orderStatusCounts.value.inTransit +
        orderStatusCounts.value.processing,
);

const orderDonutSegments = computed(() => {
    const total = orderBreakdownTotal.value || 1;
    const counts = orderStatusCounts.value;
    const segments = [
        { key: 'delivered', label: 'Delivered', color: '#14b8a6', count: counts.delivered },
        { key: 'inTransit', label: 'In Transit', color: '#6fa3e0', count: counts.inTransit },
        { key: 'processing', label: 'Processing', color: '#fbbf7d', count: counts.processing },
    ];

    let offsetAcc = 0;

    return segments.map((s) => {
        const pct = Math.round((s.count / total) * 100);
        const seg = { ...s, pct, dashoffset: -offsetAcc };

        offsetAcc += pct;

        return seg;
    });
});

// ---- Recent Sales Records (real orders, most recent first) ----
const recentOrders = computed(() =>
    [...orders.value]
        .sort((a, b) => new Date(b.placedAt || 0) - new Date(a.placedAt || 0))
        .slice(0, 5),
);

function orderItemsSummary(order) {
    if (!order.items?.length) {
return '—';
}

    if (order.items.length === 1) {
return order.items[0].name;
}

    return `${order.items[0].name} +${order.items.length - 1} more`;
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

// Real order-creation events (orders.*.placedAt, see the comment near
// ordersPlacedBetween above) merged into the same timeline as the
// account/compliance activity log — both are genuine timestamped events,
// just from two different sources. No status-change timestamp exists for
// orders server-side, so this only ever claims "received", never a
// fabricated "marked Delivered at such-and-such time".
const orderActivityEvents = computed(() =>
    [...orders.value]
        .filter((o) => o.placedAt)
        .sort((a, b) => new Date(b.placedAt) - new Date(a.placedAt))
        .slice(0, 5)
        .map((o) => ({
            id: `order-${o.id}`,
            type: 'order',
            text: `New order received — ${o.id}`,
            time: o.placedAt,
        })),
);

const accountActivityEvents = computed(() =>
    activityLog.value.map((item) => ({
        id: `account-${item.id}`,
        type: 'account',
        raw: item,
        time: item.created_at,
    })),
);

const mergedActivityLog = computed(() =>
    [...orderActivityEvents.value, ...accountActivityEvents.value]
        .sort((a, b) => new Date(b.time) - new Date(a.time))
        .slice(0, 4),
);

// Local, non-destructive view of the merged feed so "Clear Activity Log"
// only clears what's on screen — it never mutates the underlying
// composable state or deletes anything server-side.
const visibleActivityLog = ref([]);
watch(
    mergedActivityLog,
    (val) => {
        visibleActivityLog.value = [...val];
    },
    { immediate: true },
);

const hasProfileInfo = computed(() =>
    Boolean(
        profile.value?.first_name &&
        profile.value?.last_name &&
        profile.value?.birthday &&
        profile.value?.contact_no,
    ),
);
const hasAddress = computed(() =>
    Boolean(
        address.value?.province_name &&
        address.value?.municipality_name &&
        address.value?.barangay,
    ),
);
const hasBusinessInfo = computed(() =>
    Boolean(
        sellerDetails.value?.business_name &&
        sellerDetails.value?.line_of_business,
    ),
);

const rejectedDocsCount = computed(
    () => documents.value.filter((d) => d.status === 'rejected').length,
);

// ---- Store Health (compact readiness summary for the dashboard band —
// same four checks the old Store Readiness checklist showed) ----
const storeHealthChecks = computed(() => [
    hasProfileInfo.value,
    hasAddress.value,
    hasBusinessInfo.value,
    verifiedDocsCount.value === totalDocsCount.value && totalDocsCount.value > 0,
]);
const storeHealthPct = computed(() =>
    Math.round(
        (storeHealthChecks.value.filter(Boolean).length / storeHealthChecks.value.length) * 100,
    ),
);
</script>

<style scoped>
/* ============================================================
   Seller Dashboard — visual layout redesign (presentation only).

   Scope: <template> structure + this <style> block only. No
   <script> changes, no data-flow changes. The existing colour
   palette is kept verbatim — every rule below sets geometry
   (spacing, grid, alignment), type scale/weight, radius, shadow
   or density. Where a colour value appears it is the element's
   own current value, restated because this block replaces the
   component's previous scoped styles; borders/dividers reuse the
   palette's existing neutrals (#e2e8f0 card hairline,
   #f1f5f9 divider).

   Design-system reference: ui-ux-pro-max "Data-Dense Dashboard"
   (density 8 / motion 3) — 8px spacing rhythm, KPI type ramp,
   tabular figures on data, subtle 150-200ms motion, one card
   frame. Every selector is confined to `.seller-dashboard`, so
   layout.css and all other seller pages are untouched.
   ============================================================ */

.seller-dashboard {
    /* spacing rhythm (8px base) */
    --sd-space-1: 0.25rem;
    --sd-space-2: 0.5rem;
    --sd-space-3: 0.75rem;
    --sd-space-4: 1rem;
    --sd-space-5: 1.5rem;
    --sd-space-6: 2rem;

    --sd-radius: 0.6rem;
    --sd-radius-lg: 0.85rem;

    /* Dark surfaces + neutrals — the whole page (not just the sidebar)
       now sits on a near-black shell (see SellerLayout.vue's
       .theme-dark), so every card here needs its own dark surface
       instead of the plain-white .card default. */
    --sd-surface: #161b17;
    --sd-surface-2: #1d231e;
    --sd-hairline: rgba(255, 255, 255, 0.08);
    --sd-divider: rgba(255, 255, 255, 0.05);

    --sd-shadow-rest: 0 1px 2px rgba(0, 0, 0, 0.25);
    --sd-shadow-hover: 0 10px 24px -10px rgba(0, 0, 0, 0.5);

    --sd-ease: cubic-bezier(0, 0, 0.2, 1);

    /* BuyTheWay near-black / teal system (matches the redesigned
       sidebar in SellerLayout.vue) — scoped to this page only.
       Brighter than the light-mode originals since these now sit on
       dark surfaces, not white ones. */
    --sd-accent: #14b8a6;
    --sd-accent-strong: #5eead4;
    --sd-accent-soft: rgba(15, 118, 110, 0.22);
    --sd-accent-2: #6fa3e0;
    --sd-good: #5eead4;
    --sd-good-soft: rgba(20, 184, 166, 0.22);
    --sd-warn: #fbbf7d;
    --sd-warn-soft: rgba(181, 121, 27, 0.22);
    --sd-bad: #f7a49f;
    --sd-bad-soft: rgba(200, 67, 61, 0.22);
    --sd-ink-900: #f2f4f1;
    --sd-ink-500: #97a099;
    --sd-ink-400: #6d766e;

    /* Base text color for everything in this page. .seller-app itself
       sets a dark, light-mode default (color: #1e293b) that every
       unstyled span/strong here would otherwise silently inherit —
       invisible against the new dark cards. */
    color: var(--sd-ink-900);
}

.seller-dashboard .card,
.seller-dashboard .metric-card {
    background: var(--sd-surface);
    border-color: var(--sd-hairline);
}
.seller-dashboard .chart-title,
.seller-dashboard .donut-center-value,
.seller-dashboard .panel-head h3,
.seller-dashboard .activity-text {
    color: var(--sd-ink-900);
}
.seller-dashboard .chart-sub,
.seller-dashboard .sales-table thead th,
.seller-dashboard .activity-time {
    color: var(--sd-ink-500);
}
.seller-dashboard .sales-table thead th {
    background: var(--sd-surface-2);
}
.seller-dashboard .sales-table .customer {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    color: var(--sd-ink-900);
}
.seller-dashboard .sales-table .amount {
    color: var(--sd-ink-900);
}
.seller-dashboard .sales-table .item-name {
    color: var(--sd-ink-500);
}
.seller-dashboard .sales-table tbody td {
    border-top-color: var(--sd-hairline);
}
.seller-dashboard .sales-table tbody tr:hover {
    background: var(--sd-surface-2);
}
.seller-dashboard .sd-customer-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 50%;
    background: var(--sd-surface-2);
    color: var(--sd-accent-strong);
    font-size: 0.62rem;
    font-weight: 800;
    flex-shrink: 0;
}
.seller-dashboard .sd-live-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.22rem 0.6rem;
    border-radius: 999px;
    border: 1px solid rgba(15, 118, 110, 0.35);
    background: rgba(15, 118, 110, 0.14);
    color: var(--sd-accent-strong);
    font-size: 0.68rem;
    font-weight: 700;
}
.seller-dashboard .panel-link {
    color: var(--sd-accent-strong);
}
.seller-dashboard .chart-toggle {
    background: var(--sd-surface-2);
}
.seller-dashboard .chart-toggle button {
    color: var(--sd-ink-500);
}
.seller-dashboard .chart-toggle button.active {
    background: #f2f4f1;
    color: #10140f;
    box-shadow: none;
}

/* Tabular figures wherever a number is shown as data, so columns
   and values stop shifting width digit-to-digit. */
.seller-dashboard .metric-value,
.seller-dashboard .stat-value,
.seller-dashboard .donut-center-value,
.seller-dashboard .sales-table .amount,
.seller-dashboard .sales-table .order-id,
.seller-dashboard .rank-num,
.seller-dashboard .rank-units,
.seller-dashboard .stock-qty {
    font-variant-numeric: tabular-nums;
}

/* One consistent card frame: unified radius + soft rest shadow. */
.seller-dashboard .card {
    border-radius: var(--sd-radius-lg);
    box-shadow: var(--sd-shadow-rest);
}

/* ============================================================
   1 · KPI cards — label + delta ▸ value ▸ context hierarchy
   ============================================================ */
.seller-dashboard .kpi-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--sd-space-3);
    margin-bottom: var(--sd-space-3);
}
.seller-dashboard .metric-card {
    padding: var(--sd-space-4);
    border-radius: var(--sd-radius-lg);
    box-shadow: var(--sd-shadow-rest);
    transition:
        transform 0.16s var(--sd-ease),
        box-shadow 0.16s var(--sd-ease);
}
.seller-dashboard .metric-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--sd-shadow-hover);
}
.seller-dashboard .metric-card-top {
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--sd-space-2);
}
.seller-dashboard .metric-chip {
    font-size: 0.62rem;
    letter-spacing: 0.03em;
    padding: 0.14rem 0.5rem;
    border-radius: 999px;
}
.seller-dashboard .metric-chip.up {
    color: var(--sd-good);
    background: var(--sd-good-soft);
}
.seller-dashboard .metric-chip.down {
    color: var(--sd-bad);
    background: var(--sd-bad-soft);
}
.seller-dashboard .metric-chip.flat {
    color: var(--sd-accent-2);
    background: rgba(111, 163, 224, 0.18);
}
.seller-dashboard .metric-label {
    font-size: 0.68rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--sd-ink-500);
    font-weight: 700;
    margin: 0;
}
.seller-dashboard .metric-value {
    font-size: 1.55rem;
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: -0.01em;
    color: var(--sd-ink-900);
    font-variant-numeric: tabular-nums;
}
.seller-dashboard .kpi-body {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--sd-space-3);
}
.seller-dashboard .kpi-spark {
    flex-shrink: 0;
}
.seller-dashboard .metric-sub {
    margin-top: 0.35rem;
    font-size: 0.74rem;
    line-height: 1.4;
    color: var(--sd-ink-400);
}

@media (max-width: 1280px) {
    .seller-dashboard .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 460px) {
    .seller-dashboard .kpi-grid {
        grid-template-columns: 1fr;
    }
}

/* ---- attention strip (pending / low stock / active listings) ---- */
.seller-dashboard .strip {
    display: flex;
    gap: var(--sd-space-3);
    flex-wrap: wrap;
    margin-bottom: var(--sd-space-5);
}
.seller-dashboard .strip-chip {
    flex: 1;
    min-width: 13rem;
    display: flex;
    align-items: center;
    gap: var(--sd-space-3);
    padding: 0.8rem 1rem;
    background: var(--sd-surface);
    border: 1px solid var(--sd-divider);
    border-radius: var(--sd-radius);
}
.seller-dashboard .strip-ic {
    width: 2.2rem;
    height: 2.2rem;
    border-radius: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.seller-dashboard .strip-ic.warn {
    background: var(--sd-warn-soft);
    color: var(--sd-warn);
}
.seller-dashboard .strip-ic.bad {
    background: var(--sd-bad-soft);
    color: var(--sd-bad);
}
.seller-dashboard .strip-ic.good {
    background: var(--sd-good-soft);
    color: var(--sd-good);
}
.seller-dashboard .strip-text .t1 {
    font-size: 0.68rem;
    color: var(--sd-ink-500);
    font-weight: 600;
}
.seller-dashboard .strip-text .t2 {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--sd-ink-900);
}

/* ============================================================
   3 · Chart cards — shared header rhythm, calmer frame
   ============================================================ */
.seller-dashboard .chart-row {
    gap: var(--sd-space-4);
    margin-bottom: var(--sd-space-5);
}
.seller-dashboard .chart-card {
    padding: var(--sd-space-5);
}
.seller-dashboard .chart-card-head {
    align-items: center;
    margin-bottom: var(--sd-space-4);
}
.seller-dashboard .chart-title {
    font-size: 0.95rem;
    font-weight: 700;
}
.seller-dashboard .chart-sub {
    font-size: 0.75rem;
}
.seller-dashboard .chart-toggle button {
    font-size: 0.68rem;
    transition:
        background 0.15s var(--sd-ease),
        color 0.15s var(--sd-ease);
}
.seller-dashboard .chart-x-labels {
    margin-top: var(--sd-space-2);
}
.seller-dashboard .donut-center-value {
    font-size: 1.5rem;
    font-weight: 800;
}
.seller-dashboard .donut-legend-row {
    padding: 0.42rem 0;
    font-size: 0.8rem;
    border-top: 1px solid var(--sd-divider);
}
.seller-dashboard .donut-legend-row:first-child {
    border-top: 0;
}
.seller-dashboard .donut-legend-row + .donut-legend-row {
    margin-top: 0;
}

/* ============================================================
   4 & 5 · Panels — one frame for all four bottom cards
   ============================================================ */
.seller-dashboard .bottom-grid {
    gap: var(--sd-space-4);
    margin-bottom: var(--sd-space-5);
}
.seller-dashboard .panel-card {
    border-radius: var(--sd-radius-lg);
}
.seller-dashboard .panel-head {
    padding: var(--sd-space-4) var(--sd-space-5);
}
.seller-dashboard .panel-head h3 {
    font-size: 0.95rem;
    font-weight: 700;
}
.seller-dashboard .panel-link {
    font-size: 0.78rem;
}

/* Recent Sales Records table */
.seller-dashboard .sales-table {
    font-size: 0.82rem;
}
.seller-dashboard .sales-table thead th {
    padding: 0.65rem var(--sd-space-5);
    font-size: 0.66rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border-bottom: 1px solid var(--sd-hairline);
}
.seller-dashboard .sales-table tbody td {
    padding: 0.7rem var(--sd-space-5);
    border-bottom: 1px solid var(--sd-divider);
}
.seller-dashboard .sales-table .order-id {
    font-weight: 600;
}
.seller-dashboard .sales-table thead th:nth-child(5),
.seller-dashboard .sales-table tbody td:nth-child(5),
.seller-dashboard .sales-table .amount {
    text-align: right;
}
.seller-dashboard .sales-table .amount {
    font-weight: 700;
}

/* Live Store Activity — timeline rail (grouping device, uses the
   existing divider neutral). */
.seller-dashboard .activity-panel {
    padding: var(--sd-space-4) var(--sd-space-5);
}
.seller-dashboard .activity-row {
    position: relative;
    gap: var(--sd-space-3);
    padding-left: var(--sd-space-4);
}
.seller-dashboard .activity-row::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.35rem;
    bottom: 0;
    width: 2px;
    border-radius: 999px;
    background: var(--sd-hairline);
}
.seller-dashboard .activity-text {
    font-size: 0.82rem;
}
.seller-dashboard .activity-time {
    font-size: 0.72rem;
}

/* Best-Selling Products */
.seller-dashboard .rank-list {
    list-style: none;
    margin: 0;
    padding: var(--sd-space-5);
    display: flex;
    flex-direction: column;
    gap: var(--sd-space-4);
}
.seller-dashboard .rank-item {
    display: flex;
    align-items: flex-start;
    gap: var(--sd-space-3);
}
.seller-dashboard .rank-num {
    flex-shrink: 0;
    width: 1.4rem;
    height: 1.4rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.45rem;
    background: var(--sd-surface-2);
    color: var(--sd-ink-500);
    font-size: 0.72rem;
    font-weight: 800;
}
.seller-dashboard .rank-body {
    flex: 1;
    min-width: 0;
}
.seller-dashboard .rank-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--sd-space-3);
}
.seller-dashboard .rank-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--sd-ink-900);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.seller-dashboard .rank-units {
    flex-shrink: 0;
    font-size: 0.76rem;
    font-weight: 700;
    color: var(--sd-accent-strong);
}
.seller-dashboard .rank-bar {
    margin: 0.4rem 0 0.3rem;
    height: 6px;
    border-radius: 999px;
    background: var(--sd-surface-2);
    overflow: hidden;
}
.seller-dashboard .rank-bar > span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--sd-accent), var(--sd-accent-2));
    transition: width 0.4s var(--sd-ease);
}
.seller-dashboard .rank-sub {
    font-size: 0.72rem;
    color: var(--sd-ink-500);
}

/* Low-Stock Products */
.seller-dashboard .stock-list {
    list-style: none;
    margin: 0;
    padding: var(--sd-space-2) var(--sd-space-5) var(--sd-space-4);
    display: flex;
    flex-direction: column;
}
.seller-dashboard .stock-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sd-space-3);
    padding: 0.7rem 0;
    border-bottom: 1px solid var(--sd-hairline);
}
.seller-dashboard .stock-item:last-child {
    border-bottom: 0;
}
.seller-dashboard .stock-name {
    font-size: 0.85rem;
    color: var(--sd-ink-900);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.seller-dashboard .stock-qty {
    flex-shrink: 0;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
}
.seller-dashboard .stock-qty.is-low {
    background: var(--sd-warn-soft);
    color: var(--sd-warn);
}
.seller-dashboard .stock-qty.is-out {
    background: var(--sd-bad-soft);
    color: var(--sd-bad);
}

/* Recent Sales Records — order id + Live Store Activity icon badge */
.seller-dashboard .sales-table .order-id {
    color: var(--sd-accent-2);
}
.seller-dashboard .activity-icon-badge.teal {
    background: var(--sd-accent-soft);
    color: var(--sd-accent-strong);
}
.seller-dashboard .activity-icon-badge.blue {
    background: rgba(111, 163, 224, 0.18);
    color: var(--sd-accent-2);
}

/* ============================================================
   6 · Store Health — one quiet band, not a whole section, so
   compliance status is visible without competing with today's
   operational data above it.
   ============================================================ */
.seller-dashboard .sd-store-health {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--sd-space-5);
    align-items: center;
    margin-top: var(--sd-space-6);
    padding: var(--sd-space-4) var(--sd-space-5);
    background: var(--sd-divider);
    border: 1px solid var(--sd-hairline);
    border-radius: var(--sd-radius-lg);
}
.seller-dashboard .sd-health-pct {
    font-size: 0.95rem;
    font-weight: 800;
    fill: var(--sd-ink-900);
}
.seller-dashboard .sd-health-label {
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--sd-ink-500);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin: 0;
}
.seller-dashboard .sd-health-title {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--sd-ink-900);
    margin: 0.2rem 0 0;
}
.seller-dashboard .sd-health-checks {
    display: flex;
    gap: var(--sd-space-4);
    flex-wrap: wrap;
    margin-top: 0.55rem;
}
.seller-dashboard .sd-health-check {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.76rem;
    color: var(--sd-ink-500);
}
.seller-dashboard .sd-health-check .icon-xs {
    width: 1.05rem;
    height: 1.05rem;
    border-radius: 50%;
    padding: 0.15rem;
    flex-shrink: 0;
}
.seller-dashboard .sd-health-check.done {
    color: var(--sd-ink-900);
}
.seller-dashboard .sd-health-check.done .icon-xs {
    background: var(--sd-accent-soft);
    color: var(--sd-accent-strong);
}
.seller-dashboard .sd-health-check.todo .icon-xs {
    background: var(--sd-warn-soft);
    color: var(--sd-warn);
}
.seller-dashboard .sd-health-docs {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    font-size: 0.76rem;
    color: var(--sd-ink-500);
    white-space: nowrap;
}
.seller-dashboard .sd-health-docs strong {
    color: var(--sd-ink-900);
    font-weight: 800;
}

@media (max-width: 720px) {
    .seller-dashboard .sd-store-health {
        grid-template-columns: 1fr;
        text-align: center;
    }
    .seller-dashboard .sd-health-docs {
        flex-direction: row;
        justify-content: center;
        gap: 1rem;
    }
}

/* ---- reduced motion ---- */
@media (prefers-reduced-motion: reduce) {
    .seller-dashboard *,
    .seller-dashboard *::before,
    .seller-dashboard *::after {
        transition-duration: 0.01ms !important;
    }
    .seller-dashboard .metric-card:hover {
        transform: none;
    }
}
</style>