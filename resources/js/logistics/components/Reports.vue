<!-- resources/js/logistics/components/Reports.vue
     Built entirely from data this portal already fetches — there is no
     logistics reporting endpoint on the backend yet, so every figure here
     is derived client-side from the same parcel-assignment, barangay-
     assignment, application, and rider lists the other tabs use. Nothing
     that isn't tracked in this schema (delivery success/failure rate,
     returns, ratings — none of those exist here) is shown. If a real
     reporting endpoint (date-filtered exports, commission totals) is ever
     built, this page is the natural place to wire it in. -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div class="page-header-titles">
                <span class="page-icon-badge tone-info">
                    <NavIcon name="reports" :size="22" />
                </span>
                <div>
                    <h2 class="page-title">Reports</h2>
                    <p class="page-subtitle">
                        A live snapshot of
                        {{ companyName || 'your company' }}'s parcels,
                        coverage, and roster — not a historical export.
                    </p>
                </div>
            </div>
            <div class="page-header-actions">
                <span v-if="lastSyncedAt" class="sync-note">
                    Updated {{ formatRelative(lastSyncedAt) }}
                </span>
                <button
                    type="button"
                    class="btn-outline btn-icon"
                    :disabled="refreshing"
                    @click="load(true)"
                >
                    <NavIcon name="refresh" :size="15" />
                    Refresh
                </button>
            </div>
        </header>

        <CashFlowPanel
            endpoint="/api/logistics/cash-flow"
            title="Shipping earnings"
            description="Your leg of each delivery's shipping fee (after the 5% platform cut), released once the buyer confirms receipt."
        />

        <div v-if="loadError" class="callout-red callout-block" role="alert">
            <NavIcon name="alert" :size="18" />
            <div>
                <strong>We couldn't load your report data.</strong>
                <p>{{ loadError }}</p>
            </div>
            <button type="button" class="btn-outline" @click="load(true)">
                Try again
            </button>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-card-top">
                    <span class="kpi-icon">
                        <NavIcon name="parcels" :size="16" />
                    </span>
                </div>
                <p class="kpi-label">Parcels on record</p>
                <p class="kpi-value">{{ parcelStats.total }}</p>
                <p class="kpi-sub">Every parcel ever received at this hub</p>
            </div>
            <div class="kpi-card">
                <div class="kpi-card-top">
                    <span class="kpi-icon tone-success">
                        <NavIcon name="check" :size="16" />
                    </span>
                </div>
                <p class="kpi-label">Handed off / transferred</p>
                <p class="kpi-value">{{ closedCount }}</p>
                <p class="kpi-sub">
                    {{ closedRate }}% of all parcels on record
                </p>
            </div>
            <div class="kpi-card">
                <div class="kpi-card-top">
                    <span class="kpi-icon tone-info">
                        <NavIcon name="couriers" :size="16" />
                    </span>
                </div>
                <p class="kpi-label">Riders on roster</p>
                <p class="kpi-value">{{ acceptedRidersMeta.total }}</p>
                <p class="kpi-sub">{{ hiringPending }} application(s) pending</p>
            </div>
            <div class="kpi-card">
                <div class="kpi-card-top">
                    <span
                        class="kpi-icon"
                        :class="unstaffedAreas > 0 ? 'tone-warning' : 'tone-success'"
                    >
                        <NavIcon name="pin" :size="16" />
                    </span>
                </div>
                <p class="kpi-label">Barangay coverage</p>
                <p class="kpi-value">
                    {{ assignmentStats.staffed }}/{{ assignmentStats.active }}
                </p>
                <p class="kpi-sub">Active barangays with a rider appointed</p>
            </div>
        </div>

        <div class="chart-row">
            <section class="card chart-card">
                <div class="chart-card-head">
                    <div>
                        <p class="chart-title">Parcels received</p>
                        <p class="chart-sub">Last 30 days, by intake day</p>
                    </div>
                </div>
                <div v-if="loading" class="chart-empty">Loading…</div>
                <div v-else-if="receivedTrend.total === 0" class="chart-empty">
                    No parcels received in the last 30 days.
                </div>
                <div v-else class="chart-canvas-wrap">
                    <canvas ref="trendCanvasEl"></canvas>
                </div>
            </section>

            <section class="card chart-card">
                <div class="chart-card-head">
                    <div>
                        <p class="chart-title">Parcels by status</p>
                        <p class="chart-sub">All parcels on record, lifetime</p>
                    </div>
                </div>
                <div v-if="loading" class="chart-empty">Loading…</div>
                <div
                    v-else-if="lifecycleBreakdown.total === 0"
                    class="chart-empty"
                >
                    No parcels on record yet.
                </div>
                <template v-else>
                    <div class="chart-canvas-wrap" style="height: 150px">
                        <canvas ref="lifecycleCanvasEl"></canvas>
                    </div>
                    <div class="chart-legend-list">
                        <div
                            v-for="slice in lifecycleBreakdown.slices"
                            :key="slice.key"
                            class="chart-legend-row"
                        >
                            <span
                                class="chart-legend-dot"
                                :style="{ background: slice.color }"
                            ></span>
                            {{ slice.label }}
                            <strong>{{ slice.value }}</strong>
                        </div>
                    </div>
                </template>
            </section>
        </div>

        <div class="dashboard-split">
            <section class="card p-6">
                <div class="card-heading">
                    <h3 class="section-label">Hiring pipeline</h3>
                    <button
                        type="button"
                        class="btn-link"
                        @click="emit('open-section', 'applications')"
                    >
                        Review applications
                    </button>
                </div>
                <div v-if="loading" class="skeleton-list">
                    <span
                        v-for="n in 4"
                        :key="n"
                        class="skeleton skeleton-row"
                    ></span>
                </div>
                <dl v-else-if="hiringBreakdown.length" class="metric-list">
                    <div v-for="row in hiringBreakdown" :key="row.key">
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.value }}</dd>
                    </div>
                </dl>
                <div v-else class="empty-state">
                    <NavIcon name="applications" :size="28" />
                    <strong>No applications on record</strong>
                    <p>Riders who apply to your company will show up here.</p>
                </div>
            </section>

            <section class="card p-6">
                <div class="card-heading">
                    <h3 class="section-label">Rider workload</h3>
                    <button
                        type="button"
                        class="btn-link"
                        @click="emit('open-section', 'riders')"
                    >
                        View roster
                    </button>
                </div>
                <div v-if="loading" class="skeleton-list">
                    <span
                        v-for="n in 4"
                        :key="n"
                        class="skeleton skeleton-row"
                    ></span>
                </div>
                <div
                    v-else-if="topRiders.length === 0"
                    class="empty-state"
                >
                    <NavIcon name="couriers" :size="28" />
                    <strong>No riders carrying parcels</strong>
                    <p>Riders with parcels currently in hand show up here.</p>
                </div>
                <ul v-else class="people-list">
                    <li
                        v-for="rider in topRiders"
                        :key="rider.id"
                        class="person-row"
                    >
                        <span class="avatar" aria-hidden="true">{{
                            initials(rider.courier)
                        }}</span>
                        <div class="person-copy">
                            <strong>{{ personName(rider.courier) }}</strong>
                            <span>{{ rider.quota?.max ?? 20 }} parcel quota</span>
                        </div>
                        <span class="badge badge-indigo"
                            >{{ rider.quota?.active ?? 0 }} in hand</span
                        >
                    </li>
                </ul>
                <p class="kpi-sub" style="margin-top: 12px">
                    Ranked by parcels currently in hand (assigned or picked
                    up, not yet delivered or transferred).
                </p>
            </section>
        </div>
    </div>
</template>

<script setup>
import {
    computed,
    nextTick,
    onActivated,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    ArcElement,
    LinearScale,
    Tooltip,
} from 'chart.js';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import NavIcon from './NavIcon.vue';
import CashFlowPanel from '../../shared/CashFlowPanel.vue';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    DoughnutController,
    ArcElement,
    Tooltip,
);

const emit = defineEmits(['open-section']);

const {
    companyName,
    applications,
    acceptedRiders,
    acceptedRidersMeta,
    parcelStats,
    parcelAssignments,
    assignmentStats,
    pendingCount,
    lastSyncedAt,
    loadApplications,
    loadParcelAssignments,
    loadBarangayAssignments,
    loadAcceptedRiders,
} = useLogistics();
const { notifyError, formatRelative, initials, personName } =
    useLogisticsUi();

const loading = ref(true);
const refreshing = ref(false);
const loadError = ref('');

const unstaffedAreas = computed(() =>
    Math.max(assignmentStats.value.active - assignmentStats.value.staffed, 0),
);
const hiringPending = computed(() => pendingCount.value);

// ---- KPI: closed rate ----
const closedCount = computed(
    () => parcelStats.value.transferred + closedHandedOffCount.value,
);
// "Handed off and delivered" isn't a status this schema tracks
// separately from "handed off" on this resource (delivered_at isn't
// exposed on the parcel-assignments list — see ParcelAssignmentResource)
// — so "closed" here means the two terminal-for-this-desk outcomes that
// ARE visible: a completed transfer, or a handed-off parcel with no
// open transfer question against it.
const closedHandedOffCount = computed(
    () =>
        parcelAssignments.value.filter(
            (p) =>
                p.status === 'handed_off' &&
                p.area_fallback_tier !== 'regional' &&
                p.area_fallback_tier !== 'provincial',
        ).length,
);
const closedRate = computed(() => {
    if (parcelStats.value.total === 0) {
        return 0;
    }
    return Math.round((closedCount.value / parcelStats.value.total) * 100);
});

// ---- Chart 1: parcels received per day, last 30 days ----
const trendCanvasEl = ref(null);
let trendChart = null;

const receivedTrend = computed(() => {
    const days = [];
    const counts = new Map();
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let i = 29; i >= 0; i -= 1) {
        const d = new Date(today);
        d.setDate(d.getDate() - i);
        const key = d.toISOString().slice(0, 10);
        days.push({
            key,
            label: d.toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
            }),
        });
        counts.set(key, 0);
    }

    let total = 0;
    for (const parcel of parcelAssignments.value) {
        if (!parcel.received_at) {
            continue;
        }
        const key = String(parcel.received_at).slice(0, 10);
        if (counts.has(key)) {
            counts.set(key, counts.get(key) + 1);
            total += 1;
        }
    }

    return {
        total,
        labels: days.map((d) => d.label),
        values: days.map((d) => counts.get(d.key) ?? 0),
    };
});

function renderTrendChart() {
    if (!trendCanvasEl.value || receivedTrend.value.total === 0) {
        return;
    }

    trendChart?.destroy();
    trendChart = new Chart(trendCanvasEl.value, {
        type: 'bar',
        data: {
            labels: receivedTrend.value.labels,
            datasets: [
                {
                    label: 'Parcels received',
                    data: receivedTrend.value.values,
                    backgroundColor: '#0d9488',
                    borderRadius: 4,
                    maxBarThickness: 18,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxTicksLimit: 8 },
                },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: '#eef2f6' },
                },
            },
        },
    });
}

// ---- Chart 2: lifetime status breakdown ----
const lifecycleCanvasEl = ref(null);
let lifecycleChart = null;

const lifecycleBreakdown = computed(() => {
    const stats = parcelStats.value;
    const slices = [
        { key: 'toPickUp', label: 'Awaiting pickup', value: stats.toPickUp, color: '#b45309' },
        { key: 'awaitingInventory', label: 'Awaiting scan-in', value: stats.awaitingInventory, color: '#4338ca' },
        { key: 'toDeliver', label: 'Out for delivery', value: stats.toDeliver, color: '#0d9488' },
        { key: 'toTransfer', label: 'Transfer in progress', value: stats.toTransfer, color: '#d97706' },
        { key: 'transferred', label: 'Transferred out', value: stats.transferred, color: '#7e22ce' },
    ].filter((s) => s.value > 0);

    return {
        total: slices.reduce((sum, s) => sum + s.value, 0),
        slices,
    };
});

function renderLifecycleChart() {
    if (!lifecycleCanvasEl.value || lifecycleBreakdown.value.total === 0) {
        return;
    }

    lifecycleChart?.destroy();
    lifecycleChart = new Chart(lifecycleCanvasEl.value, {
        type: 'doughnut',
        data: {
            labels: lifecycleBreakdown.value.slices.map((s) => s.label),
            datasets: [
                {
                    data: lifecycleBreakdown.value.slices.map((s) => s.value),
                    backgroundColor: lifecycleBreakdown.value.slices.map(
                        (s) => s.color,
                    ),
                    borderWidth: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: { legend: { display: false } },
        },
    });
}

watch([receivedTrend, trendCanvasEl], () => nextTick(renderTrendChart));
watch([lifecycleBreakdown, lifecycleCanvasEl], () =>
    nextTick(renderLifecycleChart),
);

// ---- Hiring pipeline breakdown ----
const hiringBreakdown = computed(() => {
    const rows = [
        { key: 'pending', label: 'New applications', value: 0 },
        { key: 'interviewing', label: 'Interviewing', value: 0 },
        { key: 'accepted', label: 'Accepted (on roster)', value: 0 },
        { key: 'rejected', label: 'Rejected', value: 0 },
        { key: 'withdrawn', label: 'Withdrawn', value: 0 },
    ];
    const byKey = Object.fromEntries(rows.map((r) => [r.key, r]));

    for (const app of applications.value) {
        if (app.status === 'pending' && app.interview_invited_at) {
            byKey.interviewing.value += 1;
        } else if (byKey[app.status]) {
            byKey[app.status].value += 1;
        }
    }

    return rows.filter((r) => r.value > 0);
});

// ---- Rider workload ----
const topRiders = computed(() =>
    [...acceptedRiders.value]
        .filter((r) => (r.quota?.active ?? 0) > 0)
        .sort((a, b) => (b.quota?.active ?? 0) - (a.quota?.active ?? 0))
        .slice(0, 6),
);

async function load(force = false) {
    loadError.value = '';
    refreshing.value = true;

    try {
        await Promise.all([
            loadApplications({}, { force }),
            loadParcelAssignments({ force }),
            loadBarangayAssignments({ force }),
            loadAcceptedRiders({}, { force }),
        ]);
    } catch (error) {
        loadError.value =
            error.message || 'Please refresh the page and try again.';

        if (force) {
            notifyError(error, 'Could not refresh your reports.');
        }
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

onMounted(() => load());
onActivated(() => load());
onBeforeUnmount(() => {
    trendChart?.destroy();
    lifecycleChart?.destroy();
});
</script>
