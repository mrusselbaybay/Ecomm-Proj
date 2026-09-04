<!-- resources/js/logistics/components/ParcelOperations.vue
     The sorting desk. Structured around the one thing staff do here all
     day — scan a parcel in, then route it — so the scanner is the first
     thing on the page and the queue below it can be narrowed to the
     lifecycle stage being worked.

     The queue used to render every parcel the centre had ever received,
     unfiltered and unpaginated; a busy centre would put thousands of rows
     in the DOM. It is now filtered by stage, searchable, and windowed. -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div>
                <h2 class="page-title">Parcel sorting</h2>
                <p class="page-subtitle">
                    Receive parcels from sellers, match the delivery area, and
                    hand each parcel to the correct rider.
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
                    @click="refresh"
                >
                    <NavIcon name="refresh" :size="15" />
                    Refresh
                </button>
            </div>
        </header>

        <!-- ---------------- Intake ---------------- -->
        <section class="card scan-card">
            <div class="scan-card-copy">
                <p class="eyebrow">Parcel intake</p>
                <h3>Scan tracking or order number</h3>
                <p class="panel-copy">
                    The parcel must already be marked In Transit by the seller.
                    A handheld scanner can type straight into this field.
                </p>
            </div>
            <form class="scan-form" @submit.prevent="receive">
                <div class="scan-input-wrap">
                    <NavIcon name="scan" :size="18" class="scan-input-icon" />
                    <input
                        ref="scanInput"
                        v-model.trim="trackingNumber"
                        class="field-input scan-input"
                        placeholder="Tracking number or SN-12345"
                        aria-label="Tracking or order number"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
                <button
                    class="btn-primary"
                    :disabled="!trackingNumber || receiving"
                >
                    {{ receiving ? 'Receiving…' : 'Receive parcel' }}
                </button>
            </form>
            <p
                v-if="lookupMessage"
                class="lookup-message"
                :class="{ 'is-error': lookupIsError }"
                role="status"
            >
                {{ lookupMessage }}
            </p>
        </section>

        <p v-if="unstaffedAreas > 0" class="callout-amber callout-block">
            <NavIcon name="alert" :size="16" />
            <span>
                {{ unstaffedAreas }}
                {{
                    unstaffedAreas === 1
                        ? 'active area has'
                        : 'active areas have'
                }}
                no appointed rider, so parcels routed there can't be assigned.
            </span>
            <button
                type="button"
                class="btn-link"
                @click="emit('open-section', 'couriers')"
            >
                Manage areas
            </button>
        </p>

        <!-- ---------------- Queue ---------------- -->
        <section class="card queue-card">
            <div class="queue-toolbar">
                <div
                    class="filter-chips"
                    role="tablist"
                    aria-label="Filter the queue"
                >
                    <button
                        v-for="option in stageOptions"
                        :key="option.value"
                        type="button"
                        role="tab"
                        class="filter-chip"
                        :class="{ active: stage === option.value }"
                        :aria-selected="stage === option.value"
                        @click="setStage(option.value)"
                    >
                        {{ option.label }}
                        <span class="filter-chip-count">{{
                            option.count
                        }}</span>
                    </button>
                </div>
                <div class="search-input queue-search">
                    <NavIcon name="search" :size="15" class="icon" />
                    <input
                        v-model.trim="search"
                        type="search"
                        placeholder="Search tracking no., recipient or address"
                        aria-label="Search the sorting queue"
                    />
                </div>
            </div>

            <div class="table-scroll">
                <table class="admin-table parcel-queue-table">
                    <thead>
                        <tr>
                            <th>Parcel</th>
                            <th>Delivery address</th>
                            <th>Area</th>
                            <th>Rider</th>
                            <th>Stage</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="6">
                                <div class="skeleton-list skeleton-table">
                                    <span
                                        v-for="n in 5"
                                        :key="n"
                                        class="skeleton skeleton-row"
                                    ></span>
                                </div>
                            </td>
                        </tr>
                        <tr v-else-if="filtered.length === 0">
                            <td colspan="6">
                                <div class="empty-state">
                                    <NavIcon name="parcels" :size="30" />
                                    <strong>{{ emptyTitle }}</strong>
                                    <p>{{ emptyHint }}</p>
                                    <button
                                        v-if="hasActiveFilters"
                                        type="button"
                                        class="btn-outline"
                                        @click="clearFilters"
                                    >
                                        Clear filters
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="parcel in visible" :key="parcel.id">
                            <td>
                                <strong class="parcel-number">{{
                                    parcel.order.tracking_number ||
                                    parcel.order.order_number
                                }}</strong>
                                <span class="parcel-recipient">{{
                                    parcel.order.recipient_name
                                }}</span>
                                <span class="parcel-meta"
                                    >Received
                                    {{
                                        formatRelative(parcel.received_at)
                                    }}</span
                                >
                            </td>
                            <td>
                                <span class="address-cell">{{
                                    parcel.order.address || 'Address incomplete'
                                }}</span>
                            </td>
                            <td>
                                <span v-if="parcel.delivery_area">{{
                                    parcel.delivery_area.name
                                }}</span>
                                <span v-else class="muted-cell"
                                    >Needs sorting</span
                                >
                            </td>
                            <td>
                                <span v-if="parcel.rider">{{
                                    personName(parcel.rider)
                                }}</span>
                                <span v-else class="muted-cell"
                                    >Unassigned</span
                                >
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="statusClass(parcel)"
                                    >{{ statusLabel(parcel) }}</span
                                >
                                <span
                                    v-if="!parcel.is_scanned"
                                    class="parcel-meta"
                                    >Not yet scanned in</span
                                >
                            </td>
                            <td class="text-right">
                                <button
                                    v-if="isParcelActionable(parcel)"
                                    class="btn-sm-primary"
                                    @click="openAssignment(parcel)"
                                >
                                    {{ actionLabel(parcel) }}
                                </button>
                                <span v-else class="handoff-time"
                                    >Handed off
                                    {{ formatDate(parcel.handed_off_at) }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="!loading && filtered.length > 0" class="queue-footer">
                <p class="queue-count">
                    Showing {{ visible.length }} of {{ filtered.length }}
                    {{ filtered.length === 1 ? 'parcel' : 'parcels' }}
                </p>
                <button
                    v-if="visible.length < filtered.length"
                    type="button"
                    class="btn-outline"
                    @click="pageSize += PAGE_STEP"
                >
                    Show
                    {{ Math.min(PAGE_STEP, filtered.length - visible.length) }}
                    more
                </button>
            </div>
        </section>

        <!-- ---------------- Routing modal ---------------- -->
        <div
            v-if="selectedParcel"
            class="modal-overlay"
            @click.self="closeAssignment"
        >
            <form
                class="modal-panel assignment-modal"
                @submit.prevent="confirmAssignment"
            >
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Parcel routing</p>
                        <h3>
                            {{
                                selectedParcel.order.tracking_number ||
                                selectedParcel.order.order_number
                            }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        class="modal-close"
                        aria-label="Close"
                        @click="closeAssignment"
                    >
                        <NavIcon name="close" :size="15" />
                    </button>
                </div>

                <div class="parcel-address-card">
                    <span>Deliver to</span>
                    <strong>{{ selectedParcel.order.recipient_name }}</strong>
                    <p>
                        {{
                            selectedParcel.order.address || 'Address incomplete'
                        }}
                    </p>
                </div>

                <div class="area-form-grid">
                    <label class="form-field full-field">
                        <span>Delivery area</span>
                        <select
                            v-model="assignmentForm.delivery_area_id"
                            class="field-input"
                            required
                            @change="selectAreaRider"
                        >
                            <option value="" disabled>
                                Select an active area
                            </option>
                            <option
                                v-for="area in activeAreas"
                                :key="area.id"
                                :value="area.id"
                            >
                                {{ area.name }} — {{ formatCoverage(area) }}
                            </option>
                        </select>
                    </label>
                    <label class="form-field full-field">
                        <span>Assigned rider</span>
                        <select
                            v-model="assignmentForm.rider_profile_id"
                            class="field-input"
                            required
                        >
                            <option value="" disabled>
                                Select an accepted rider
                            </option>
                            <option
                                v-for="rider in areaRiders"
                                :key="rider.id"
                                :value="rider.id"
                            >
                                {{ personName(rider) }}
                            </option>
                        </select>
                    </label>
                </div>

                <p v-if="assignmentError" class="callout-red">
                    {{ assignmentError }}
                </p>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="btn-outline"
                        @click="closeAssignment"
                    >
                        Cancel
                    </button>
                    <button
                        v-if="selectedParcel.status === 'assigned'"
                        type="button"
                        class="btn-primary"
                        :disabled="saving"
                        @click="handoff"
                    >
                        {{ saving ? 'Confirming…' : 'Confirm rider handoff' }}
                    </button>
                    <button v-else class="btn-primary" :disabled="saving">
                        {{ saving ? 'Assigning…' : 'Assign parcel' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import {
    computed,
    nextTick,
    onActivated,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import NavIcon from './NavIcon.vue';

const emit = defineEmits(['open-section']);

const {
    parcelAssignments,
    deliveryAreas,
    areaRiders,
    parcelStats,
    areaStats,
    lastSyncedAt,
    loadParcelAssignments,
    loadDeliveryAreas,
    receiveParcel,
    assignParcel,
    handoffParcel,
    isParcelActionable,
} = useLogistics();
const { notify, notifyError, formatDate, formatRelative, personName } =
    useLogisticsUi();

const PAGE_STEP = 25;

const trackingNumber = ref('');
const lookupMessage = ref('');
const lookupIsError = ref(false);
const scanInput = ref(null);
const loading = ref(true); // first-load skeleton gate; never re-armed for refreshes
const refreshing = ref(false);
const receiving = ref(false);
const saving = ref(false);
const selectedParcel = ref(null);
const assignmentError = ref('');
const assignmentForm = reactive({ delivery_area_id: '', rider_profile_id: '' });

const stage = ref('actionable');
const search = ref('');
const pageSize = ref(PAGE_STEP);

const activeAreas = computed(() =>
    deliveryAreas.value.filter((area) => area.is_active),
);
const unstaffedAreas = computed(() =>
    Math.max(areaStats.value.active - areaStats.value.staffed, 0),
);

// The lifecycle is received -> sorted -> assigned -> handed_off, but
// pickup and delivery are two legs run by two people, so there are three
// "what's left to do" stages:
//   - not handed off yet ......... "To pick up" (needs a pickup rider)
//   - handed off, no rider ....... "To be delivered" (the pickup courier
//     confirmed collection; the parcel is back here and needs a delivery
//     rider — see Driver\DriverDeliveryController::pickup)
//   - handed off, has a rider .... "Out for delivery" (done at this desk)
function stageOf(parcel) {
    if (parcel.status !== 'handed_off') {
        return 'toPickUp';
    }

    return parcel.rider ? 'outForDelivery' : 'toDeliver';
}

const stageOptions = computed(() => [
    {
        value: 'actionable',
        label: 'Needs action',
        count: parcelStats.value.toPickUp + parcelStats.value.toDeliver,
    },
    {
        value: 'toPickUp',
        label: 'To pick up',
        count: parcelStats.value.toPickUp,
    },
    {
        value: 'toDeliver',
        label: 'To be delivered',
        count: parcelStats.value.toDeliver,
    },
    {
        value: 'outForDelivery',
        label: 'Out for delivery',
        count: parcelStats.value.outForDelivery,
    },
    { value: 'all', label: 'All', count: parcelStats.value.total },
]);

const filtered = computed(() => {
    const term = search.value.toLowerCase();

    return parcelAssignments.value.filter((parcel) => {
        if (stage.value === 'actionable' && !isParcelActionable(parcel)) {
            return false;
        }

        if (
            stage.value !== 'all' &&
            stage.value !== 'actionable' &&
            stageOf(parcel) !== stage.value
        ) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [
            parcel.order?.tracking_number,
            parcel.order?.order_number,
            parcel.order?.recipient_name,
            parcel.order?.address,
            parcel.delivery_area?.name,
        ]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(term));
    });
});

// Windowed so a queue of thousands doesn't put thousands of rows in the
// DOM. `filtered` stays the true count for the footer.
const visible = computed(() => filtered.value.slice(0, pageSize.value));

const hasActiveFilters = computed(
    () => stage.value !== 'all' || search.value.length > 0,
);

const emptyTitle = computed(() => {
    if (search.value) {
        return 'No parcels match that search';
    }

    return (
        {
            actionable: 'Nothing needs action',
            toPickUp: 'No parcels waiting for pickup',
            toDeliver: 'No parcels waiting for a delivery rider',
            outForDelivery: 'No parcels out for delivery',
            all: 'No parcels in the sorting queue',
        }[stage.value] || 'No parcels here'
    );
});

const emptyHint = computed(() => {
    if (search.value) {
        return 'Try a different tracking number, recipient, or address.';
    }

    return stage.value === 'all'
        ? 'Scan the first In Transit parcel to begin sorting.'
        : 'Parcels at this stage will appear here.';
});

// Reset the window whenever the result set changes, so switching to a
// stage with 3 parcels doesn't keep a 200-row window open.
watch([stage, search], () => {
    pageSize.value = PAGE_STEP;
});

function setStage(value) {
    stage.value = value;
}

function clearFilters() {
    stage.value = 'all';
    search.value = '';
}

function focusScanner() {
    nextTick(() => scanInput.value?.focus());
}

function formatCoverage(area) {
    return [area.barangay, area.municipality_name, area.province_name]
        .filter(Boolean)
        .join(', ');
}

function statusLabel(parcel) {
    return {
        toPickUp: 'To pick up',
        toDeliver: 'To be delivered',
        outForDelivery: 'Out for delivery',
    }[stageOf(parcel)];
}

function statusClass(parcel) {
    return stageOf(parcel) === 'outForDelivery' ? 'badge-teal' : 'badge-amber';
}

function actionLabel(parcel) {
    if (stageOf(parcel) === 'toDeliver') {
        return 'Assign delivery';
    }

    return parcel.status === 'assigned' ? 'Review' : 'Assign';
}

function openAssignment(parcel) {
    selectedParcel.value = parcel;
    assignmentForm.delivery_area_id = parcel.delivery_area?.id || '';
    assignmentForm.rider_profile_id = parcel.rider?.id || '';
    assignmentError.value = '';
}

function closeAssignment() {
    selectedParcel.value = null;
    assignmentError.value = '';
    focusScanner();
}

function selectAreaRider() {
    const area = deliveryAreas.value.find(
        (item) => item.id === assignmentForm.delivery_area_id,
    );

    // Only auto-fill when the area has exactly one appointed rider —
    // several appointed riders means the staff picks, not a guess.
    if (area?.riders?.length === 1) {
        assignmentForm.rider_profile_id = area.riders[0].id;
    }
}

async function receive() {
    receiving.value = true;
    lookupMessage.value = '';
    lookupIsError.value = false;

    try {
        const parcel = await receiveParcel(trackingNumber.value);

        lookupMessage.value = parcel.delivery_area
            ? `${parcel.delivery_area.name} matched. Review and confirm the rider.`
            : 'Parcel received. No delivery area matched; assign it manually.';
        trackingNumber.value = '';
        openAssignment(parcel);
    } catch (error) {
        lookupIsError.value = true;
        lookupMessage.value = error.message;
        notifyError(error, 'Could not receive that parcel.');
        // Keep the value selected so a mistyped code can be rescanned over.
        focusScanner();
        scanInput.value?.select();
    } finally {
        receiving.value = false;
    }
}

async function confirmAssignment() {
    saving.value = true;
    assignmentError.value = '';

    try {
        await assignParcel(
            selectedParcel.value.id,
            assignmentForm.delivery_area_id,
            assignmentForm.rider_profile_id,
        );
        notify('Parcel assigned to the rider.');
        closeAssignment();
    } catch (error) {
        assignmentError.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function handoff() {
    saving.value = true;
    assignmentError.value = '';

    try {
        await handoffParcel(selectedParcel.value.id);
        notify('Rider handoff confirmed.');
        closeAssignment();
    } catch (error) {
        assignmentError.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function load(force = false) {
    refreshing.value = true;

    try {
        await Promise.all([
            loadParcelAssignments({ force }),
            loadDeliveryAreas({ force }),
        ]);
    } catch (error) {
        notifyError(error, 'Could not load the sorting queue.');
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

const refresh = () => load(true);

onMounted(async () => {
    await load();
    focusScanner();
});

// Re-entering the tab re-checks staleness (free while the cache is fresh)
// and puts the cursor back in the scanner, ready for the next parcel.
onActivated(() => {
    load();
    focusScanner();
});
</script>
