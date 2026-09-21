<!-- resources/js/logistics/components/Dashboard.vue
     Operations dashboard: "what needs my attention right now", then the
     full parcel queue breakdown, then trend/composition charts, then the
     network + hiring snapshot. Every number here comes from data the
     portal already fetches (useLogistics' parcelStats / assignmentStats /
     applications / transfer & resignation counts) — nothing is invented,
     and nothing that isn't tracked in this schema (e.g. "delayed" or
     "failed" parcels — there is no such status) is shown. -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div class="page-header-titles">
                <span class="page-icon-badge">
                    <NavIcon name="dashboard" :size="22" />
                </span>
                <div>
                    <h2 class="page-title">Dashboard</h2>
                    <p class="page-subtitle">
                        {{ companyName || 'Your company' }} · today's
                        operations at a glance
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

        <div v-if="loadError" class="callout-red callout-block" role="alert">
            <NavIcon name="alert" :size="18" />
            <div>
                <strong>We couldn't load the latest logistics data.</strong>
                <p>{{ loadError }}</p>
            </div>
            <button type="button" class="btn-outline" @click="load(true)">
                Try again
            </button>
        </div>

        <!-- ---------------- Needs attention ---------------- -->
        <template v-if="!loading">
            <section
                v-if="attentionItems.length"
                class="card attention-panel"
            >
                <div class="attention-panel-head">
                    <NavIcon name="alert" :size="17" />
                    <h3>Needs your attention</h3>
                </div>
                <p class="attention-panel-sub">
                    {{ attentionItems.length }}
                    {{
                        attentionItems.length === 1 ? 'thing' : 'things'
                    }}
                    waiting on a decision from your desk.
                </p>
                <div class="attention-list">
                    <button
                        v-for="item in attentionItems"
                        :key="item.key"
                        type="button"
                        class="attention-item"
                        @click="emit('open-section', item.tab)"
                    >
                        <span class="attention-item-count">{{
                            item.count
                        }}</span>
                        <span class="attention-item-copy">
                            <strong>{{ item.label }}</strong>
                            <span>{{ item.hint }}</span>
                        </span>
                        <span class="attention-item-go">
                            <NavIcon name="chevron-right" :size="16" />
                        </span>
                    </button>
                </div>
            </section>
            <div v-else class="attention-allclear">
                <NavIcon name="check" :size="18" />
                <span
                    ><strong>All clear.</strong> No pending decisions across
                    parcels, coverage, hiring, or the roster right
                    now.</span
                >
            </div>
        </template>

        <!-- ---------------- Parcel queue ---------------- -->
        <h3 class="section-label">Parcel queue</h3>
        <div class="kpi-grid">
            <button
                v-for="card in parcelCards"
                :key="card.key"
                type="button"
                class="kpi-card is-clickable"
                @click="emit('open-section', 'parcels')"
            >
                <div class="kpi-card-top">
                    <span class="kpi-icon" :class="card.tone">
                        <NavIcon :name="card.icon" :size="16" />
                    </span>
                </div>
                <p class="kpi-label">{{ card.label }}</p>
                <p v-if="loading" class="skeleton skeleton-stat"></p>
                <p v-else class="kpi-value">{{ card.value }}</p>
                <p class="kpi-sub">{{ card.hint }}</p>
            </button>
        </div>

        <!-- ---------------- Charts ---------------- -->
        <div class="chart-row">
            <section class="card chart-card">
                <div class="chart-card-head">
                    <div>
                        <p class="chart-title">Parcels received</p>
                        <p class="chart-sub">Last 7 days, by intake day</p>
                    </div>
                </div>
                <div v-if="loading" class="chart-empty">Loading…</div>
                <div v-else-if="receivedTrend.total === 0" class="chart-empty">
                    No parcels received in the last 7 days.
                </div>
                <div v-else class="chart-canvas-wrap">
                    <canvas ref="trendCanvasEl"></canvas>
                </div>
            </section>

            <section class="card chart-card">
                <div class="chart-card-head">
                    <div>
                        <p class="chart-title">Queue breakdown</p>
                        <p class="chart-sub">What still needs a decision</p>
                    </div>
                </div>
                <div v-if="loading" class="chart-empty">Loading…</div>
                <div
                    v-else-if="queueBreakdown.total === 0"
                    class="chart-empty"
                >
                    The queue is empty — nothing awaiting a decision.
                </div>
                <template v-else>
                    <div class="chart-canvas-wrap" style="height: 150px">
                        <canvas ref="breakdownCanvasEl"></canvas>
                    </div>
                    <div class="chart-legend-list">
                        <div
                            v-for="slice in queueBreakdown.slices"
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

        <!-- ---------------- Network + hiring ---------------- -->
        <div class="dashboard-split">
            <section class="card p-6">
                <div class="card-heading">
                    <h3 class="section-label">Delivery network</h3>
                    <button
                        type="button"
                        class="btn-link"
                        @click="emit('open-section', 'areas')"
                    >
                        Manage areas
                    </button>
                </div>

                <div v-if="loading" class="skeleton-list">
                    <span
                        v-for="n in 3"
                        :key="n"
                        class="skeleton skeleton-row"
                    ></span>
                </div>
                <dl v-else class="metric-list">
                    <div>
                        <dt>Active barangay assignments</dt>
                        <dd>{{ assignmentStats.active }}</dd>
                    </div>
                    <div>
                        <dt>Barangays with an appointed rider</dt>
                        <dd
                            :class="{
                                'metric-warn': unstaffedAreas > 0,
                            }"
                        >
                            {{ assignmentStats.staffed }} /
                            {{ assignmentStats.active }}
                        </dd>
                    </div>
                    <div>
                        <dt>Riders on the roster</dt>
                        <dd>{{ rosterSize }}</dd>
                    </div>
                </dl>

                <p v-if="!loading && unstaffedAreas > 0" class="callout-amber">
                    {{ unstaffedAreas }}
                    {{ unstaffedAreas === 1 ? 'area has' : 'areas have' }}
                    no appointed rider — parcels routed there can't be assigned.
                </p>
            </section>

            <section class="card p-6">
                <div class="card-heading">
                    <h3 class="section-label">Recent applications</h3>
                    <button
                        type="button"
                        class="btn-link"
                        @click="emit('open-section', 'applications')"
                    >
                        View all
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
                    v-else-if="recentApplications.length === 0"
                    class="empty-state"
                >
                    <NavIcon name="inbox" :size="28" />
                    <strong>No applications yet</strong>
                    <p>Riders who apply to your company will show up here.</p>
                </div>
                <ul v-else class="people-list">
                    <li
                        v-for="app in recentApplications"
                        :key="app.id"
                        class="person-row"
                    >
                        <span class="avatar" aria-hidden="true">{{
                            initials(app.courier)
                        }}</span>
                        <div class="person-copy">
                            <strong>{{
                                personName(app.courier, 'Unnamed applicant')
                            }}</strong>
                            <span
                                >Applied {{ formatDate(app.applied_at) }}</span
                            >
                        </div>
                        <span class="badge" :class="badgeClass(app.status)">{{
                            app.status
                        }}</span>
                    </li>
                </ul>

                <p v-if="!loading && pendingCount > 0" class="callout-amber">
                    {{ pendingCount }} application{{
                        pendingCount === 1 ? '' : 's'
                    }}
                    waiting on your decision.
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
    assignmentRiders,
    pendingCount,
    pendingTransferCount,
    pendingResignationCount,
    parcelStats,
    parcelAssignments,
    assignmentStats,
    lastSyncedAt,
    loadApplications,
    loadParcelAssignments,
    loadBarangayAssignments,
    loadTransferRequests,
    loadResignationRequests,
} = useLogistics();
const {
    notifyError,
    formatDate,
    formatRelative,
    initials,
    personName,
    badgeClass,
} = useLogisticsUi();

const loading = ref(true); // first-load skeleton gate; never re-armed for refreshes
const refreshing = ref(false);
const loadError = ref('');

const parcelCards = computed(() => [
    {
        key: 'toPickUp',
        label: 'To pick up',
        value: parcelStats.value.toPickUp,
        hint: 'Waiting on a pickup rider',
        icon: 'inbox',
        tone: 'tone-warning',
    },
    {
        key: 'awaitingInventory',
        label: 'Awaiting scan-in',
        value: parcelStats.value.awaitingInventory,
        hint: 'New custody, not yet scanned at this hub',
        icon: 'scan',
        tone: 'tone-info',
    },
    {
        key: 'toDeliver',
        label: 'To be delivered',
        value: parcelStats.value.toDeliver,
        hint: 'Collected — with or awaiting a delivery rider',
        icon: 'pin',
        tone: '',
    },
    {
        key: 'toTransfer',
        label: 'Transfer decision',
        value: parcelStats.value.toTransfer,
        hint: 'Outside this company’s coverage — needs a call',
        icon: 'truck',
        tone: 'tone-warning',
    },
    {
        key: 'transferred',
        label: 'Transferred out',
        value: parcelStats.value.transferred,
        hint: 'Handed to another company, delivery complete for us',
        icon: 'check',
        tone: 'tone-success',
    },
    {
        key: 'total',
        label: 'In the queue',
        value: parcelStats.value.total,
        hint: 'All parcels held at this centre',
        icon: 'parcels',
        tone: 'tone-neutral',
    },
]);

const unstaffedAreas = computed(() =>
    Math.max(assignmentStats.value.active - assignmentStats.value.staffed, 0),
);
const rosterSize = computed(() => assignmentRiders.value.length);
const recentApplications = computed(() => applications.value.slice(0, 5));

// Each row is a real, already-fetched count paired with the tab that
// resolves it. Nothing renders here with a count of zero — see the
// all-clear state in the template for that case.
const attentionItems = computed(() => {
    const items = [];

    if (pendingCount.value > 0) {
        items.push({
            key: 'applications',
            tab: 'applications',
            count: pendingCount.value,
            label: `${pendingCount.value} rider application${pendingCount.value === 1 ? '' : 's'} pending review`,
            hint: 'New couriers waiting on an interview or a decision',
        });
    }
    if (unstaffedAreas.value > 0) {
        items.push({
            key: 'areas',
            tab: 'areas',
            count: unstaffedAreas.value,
            label: `${unstaffedAreas.value} barangay${unstaffedAreas.value === 1 ? '' : 's'} with no appointed rider`,
            hint: 'Parcels there fall back to the company-wide rotation',
        });
    }
    if (pendingTransferCount.value > 0) {
        items.push({
            key: 'transfers',
            tab: 'parcels',
            count: pendingTransferCount.value,
            label: `${pendingTransferCount.value} transfer request${pendingTransferCount.value === 1 ? '' : 's'} awaiting a reply`,
            hint: 'Another company is waiting on your accept/reject',
        });
    }
    if (pendingResignationCount.value > 0) {
        items.push({
            key: 'resignations',
            tab: 'applications',
            count: pendingResignationCount.value,
            label: `${pendingResignationCount.value} resignation request${pendingResignationCount.value === 1 ? '' : 's'} pending`,
            hint: 'A rider on the roster has asked to leave',
        });
    }

    return items;
});

// ---- Chart 1: parcels received per day, last 7 days ----
// Built from the same parcelAssignments list the queue itself reads —
// no separate history endpoint exists, so this is exactly what's
// already on screen, grouped by day instead of by status.
const trendCanvasEl = ref(null);
let trendChart = null;

const receivedTrend = computed(() => {
    const days = [];
    const counts = new Map();
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let i = 6; i >= 0; i -= 1) {
        const d = new Date(today);
        d.setDate(d.getDate() - i);
        const key = d.toISOString().slice(0, 10);
        days.push({
            key,
            label: d.toLocaleDateString(undefined, { weekday: 'short' }),
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
                    borderRadius: 6,
                    maxBarThickness: 36,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: '#eef2f6' },
                },
            },
        },
    });
}

// ---- Chart 2: queue breakdown (what's still actionable) ----
const breakdownCanvasEl = ref(null);
let breakdownChart = null;

const queueBreakdown = computed(() => {
    const stats = parcelStats.value;
    const slices = [
        { key: 'toPickUp', label: 'To pick up', value: stats.toPickUp, color: '#b45309' },
        { key: 'awaitingInventory', label: 'Awaiting scan-in', value: stats.awaitingInventory, color: '#4338ca' },
        { key: 'toDeliver', label: 'To deliver', value: stats.toDeliver, color: '#0d9488' },
        { key: 'toTransfer', label: 'Transfer decision', value: stats.toTransfer, color: '#d97706' },
    ].filter((s) => s.value > 0);

    return {
        total: slices.reduce((sum, s) => sum + s.value, 0),
        slices,
    };
});

function renderBreakdownChart() {
    if (!breakdownCanvasEl.value || queueBreakdown.value.total === 0) {
        return;
    }

    breakdownChart?.destroy();
    breakdownChart = new Chart(breakdownCanvasEl.value, {
        type: 'doughnut',
        data: {
            labels: queueBreakdown.value.slices.map((s) => s.label),
            datasets: [
                {
                    data: queueBreakdown.value.slices.map((s) => s.value),
                    backgroundColor: queueBreakdown.value.slices.map(
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
watch([queueBreakdown, breakdownCanvasEl], () => nextTick(renderBreakdownChart));

/**
 * All reads go through the shared cache, so the Parcel Sorting, Riders &
 * Areas, and Applications tabs reuse exactly this data instead of
 * refetching it. `loading` is not flipped back on for refreshes — it is
 * a first-load-only skeleton gate, so re-entering the tab never flashes
 * skeletons over data that is already rendered.
 */
async function load(force = false) {
    loadError.value = '';
    refreshing.value = true;

    try {
        await Promise.all([
            loadApplications({}, { force }),
            loadParcelAssignments({ force }),
            loadBarangayAssignments({ force }),
            loadTransferRequests({ force }),
            loadResignationRequests({ force }),
        ]);
    } catch (error) {
        loadError.value =
            error.message || 'Please refresh the page and try again.';

        if (force) {
            notifyError(error, 'Could not refresh the dashboard.');
        }
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

onMounted(() => load());

// <KeepAlive> means onMounted fires once, so re-entering the tab re-checks
// staleness. The shared cache makes this free when the data is still
// fresh and refetches only once it has aged past the TTL.
onActivated(() => load());

onBeforeUnmount(() => {
    trendChart?.destroy();
    breakdownChart?.destroy();
});
</script>
