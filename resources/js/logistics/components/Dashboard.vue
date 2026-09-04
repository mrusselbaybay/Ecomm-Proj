<!-- resources/js/logistics/components/Dashboard.vue
     An operations dashboard for a sorting centre: what is on the desk
     right now, then hiring. It previously showed four application-status
     counters — two of which ("Total Couriers" and "Accepted") were always
     the same number, because the courier roster IS the accepted
     applications, and the duplicate cost a second HTTP request to
     /applications?status=accepted for data already in hand. -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div>
                <h2 class="page-title">Dashboard</h2>
                <p class="page-subtitle">
                    {{ companyName || 'Your company' }} · today's operations at
                    a glance
                </p>
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

        <!-- ---------------- Parcels on the desk ---------------- -->
        <h3 class="section-label">Parcels on the desk</h3>
        <div class="stat-grid">
            <button
                v-for="card in parcelCards"
                :key="card.key"
                type="button"
                class="stat-card is-clickable"
                :class="card.accent"
                @click="emit('open-section', 'parcels')"
            >
                <div class="stat-card-top">
                    <p class="field-label">{{ card.label }}</p>
                    <span class="stat-icon" aria-hidden="true">
                        <NavIcon :name="card.icon" :size="16" />
                    </span>
                </div>
                <p v-if="loading" class="skeleton skeleton-stat"></p>
                <p v-else class="stat-value" :class="card.tone">
                    {{ card.value }}
                </p>
                <p class="stat-hint">{{ card.hint }}</p>
            </button>
        </div>

        <!-- ---------------- Network + hiring ---------------- -->
        <div class="dashboard-split">
            <section class="card p-6">
                <div class="card-heading">
                    <h3 class="section-label">Delivery network</h3>
                    <button
                        type="button"
                        class="btn-link"
                        @click="emit('open-section', 'couriers')"
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
                        <dt>Active delivery areas</dt>
                        <dd>{{ areaStats.active }}</dd>
                    </div>
                    <div>
                        <dt>Areas with an appointed rider</dt>
                        <dd
                            :class="{
                                'metric-warn': unstaffedAreas > 0,
                            }"
                        >
                            {{ areaStats.staffed }} / {{ areaStats.active }}
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
import { computed, onActivated, onMounted, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import NavIcon from './NavIcon.vue';

const emit = defineEmits(['open-section']);

const {
    companyName,
    applications,
    areaRiders,
    pendingCount,
    parcelStats,
    areaStats,
    lastSyncedAt,
    loadApplications,
    loadParcelAssignments,
    loadDeliveryAreas,
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
        accent: 'accent-pending',
        tone: 'stat-pending',
    },
    {
        key: 'toDeliver',
        label: 'To be delivered',
        value: parcelStats.value.toDeliver,
        hint: 'Collected — needs a delivery rider',
        icon: 'pin',
        accent: 'accent-total',
        tone: 'stat-total',
    },
    {
        key: 'outForDelivery',
        label: 'Out for delivery',
        value: parcelStats.value.outForDelivery,
        hint: 'With a rider, en route',
        icon: 'truck',
        accent: 'accent-active',
        tone: 'stat-active',
    },
    {
        key: 'total',
        label: 'In the queue',
        value: parcelStats.value.total,
        hint: 'All parcels held at this centre',
        icon: 'parcels',
        accent: 'accent-neutral',
        tone: 'stat-neutral',
    },
]);

const unstaffedAreas = computed(() =>
    Math.max(areaStats.value.active - areaStats.value.staffed, 0),
);
const rosterSize = computed(() => areaRiders.value.length);
const recentApplications = computed(() => applications.value.slice(0, 5));

/**
 * All three reads go through the shared cache, so the Parcel Sorting and
 * Riders & Areas tabs reuse exactly this data instead of refetching it.
 * `loading` is not flipped back on for refreshes — it is a first-load-only
 * skeleton gate, so re-entering the tab never flashes skeletons over data
 * that is already rendered.
 */
async function load(force = false) {
    loadError.value = '';
    refreshing.value = true;

    try {
        await Promise.all([
            loadApplications({}, { force }),
            loadParcelAssignments({ force }),
            loadDeliveryAreas({ force }),
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
</script>
