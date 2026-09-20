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
                    @click="openTransferRequests"
                >
                    <NavIcon name="inbox" :size="15" />
                    Transfer requests
                    <span v-if="pendingTransferCount" class="count-pill">{{
                        pendingTransferCount
                    }}</span>
                </button>
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
                        ? 'active barangay has'
                        : 'active barangays have'
                }}
                no appointed rider — parcels routed there fall back to the
                company-wide rotation.
            </span>
            <button
                type="button"
                class="btn-link"
                @click="emit('open-section', 'areas')"
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
                            <th>Address</th>
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
                                <span
                                    class="badge badge-slate address-phase-badge"
                                    >{{
                                        parcel.phase === 'pickup'
                                            ? 'Pick up from seller'
                                            : 'Deliver to buyer'
                                    }}</span
                                >
                                <span class="address-cell">{{
                                    parcel.order.address || 'Address incomplete'
                                }}</span>
                            </td>
                            <td>
                                <span v-if="parcel.barangay_assignment">{{
                                    parcel.barangay_assignment.barangay
                                }}, {{
                                    parcel.barangay_assignment.municipality_name
                                }}</span>
                                <span
                                    v-else-if="parcel.area_fallback_tier"
                                    class="badge badge-indigo"
                                    >{{
                                        parcel.area_fallback_tier ===
                                        'provincial'
                                            ? 'Provincial'
                                            : 'Regional'
                                    }}</span
                                >
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
                                    v-if="!parcel.is_scanned && stageOf(parcel) === 'toPickUp'"
                                    class="parcel-meta"
                                    >Not yet scanned in</span
                                >
                            </td>
                            <td class="text-right">
                                <button
                                    v-if="isParcelActionable(parcel)"
                                    class="btn-sm-primary parcel-manage-btn"
                                    @click="openAssignment(parcel)"
                                >
                                    {{ actionLabel() }}
                                </button>
                                <span
                                    v-else-if="
                                        parcel.status === 'transfer_pending'
                                    "
                                    class="pending-transfer-cell"
                                >
                                    <span class="handoff-time"
                                        >Requested
                                        {{
                                            formatRelative(
                                                parcel.transfer_request
                                                    ?.requested_at,
                                            )
                                        }}</span
                                    >
                                    <button
                                        type="button"
                                        class="btn-sm-outline"
                                        @click="cancelRequest(parcel)"
                                    >
                                        Cancel request
                                    </button>
                                </span>
                                <span
                                    v-else-if="parcel.status === 'ready_to_transfer'"
                                    class="handoff-time"
                                    >With courier, headed to
                                    {{
                                        parcel.transfer_to_company
                                            ?.company_name || 'the other company'
                                    }}</span
                                >
                                <span
                                    v-else-if="parcel.status === 'transferred'"
                                    class="handoff-time"
                                    >Transferred
                                    {{
                                        formatDate(parcel.transferred_at)
                                    }}</span
                                >
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
                <div v-if="pageCount > 1" class="queue-pagination">
                    <button
                        type="button"
                        class="btn-sm-outline btn-icon"
                        aria-label="Previous page"
                        :disabled="page <= 1"
                        @click="goToPage(page - 1)"
                    >
                        <NavIcon name="chevron-left" :size="14" />
                        Prev
                    </button>
                    <span class="queue-page-label"
                        >Page {{ page }} of {{ pageCount }}</span
                    >
                    <button
                        type="button"
                        class="btn-sm-outline btn-icon"
                        aria-label="Next page"
                        :disabled="page >= pageCount"
                        @click="goToPage(page + 1)"
                    >
                        Next
                        <NavIcon name="chevron-right" :size="14" />
                    </button>
                </div>
            </div>
        </section>

        <!-- Modals teleport out of .logistics-main (which has a
             transform, so it would otherwise be the containing block for
             the fixed overlay and let the dim backdrop scroll away). -->
        <Teleport to=".logistics-shell">
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
                        <span>{{
                            selectedParcel.phase === 'pickup'
                                ? 'Pick up from'
                                : 'Deliver to'
                        }}</span>
                        <strong>{{
                            selectedParcel.order.recipient_name
                        }}</strong>
                        <p>
                            {{
                                selectedParcel.order.address ||
                                'Address incomplete'
                            }}
                        </p>
                    </div>

                    <p
                        v-if="selectedParcel.required_vehicle_type"
                        class="callout-amber callout-block"
                    >
                        <NavIcon name="alert" :size="16" />
                        <span>
                            Requires
                            {{
                                vehicleRequirementLabel(
                                    selectedParcel.required_vehicle_type,
                                )
                            }}
                            — this parcel crosses a
                            {{ selectedParcel.transfer_trigger }} boundary.
                        </span>
                    </p>

                    <!-- Awaiting the For Inventory checkpoint — a courier has
                     already handed this company the parcel, but nobody can
                     act on it here until it's scanned in. Scanning is
                     mobile-only (device camera), so this is informational,
                     not an action. -->
                    <p
                        v-if="selectedParcel.status === 'for_inventory'"
                        class="callout-amber callout-block"
                    >
                        <NavIcon name="alert" :size="16" />
                        <span>
                            This parcel is awaiting an inventory scan.
                            Scan its QR code in the Logistics mobile app to
                            move it into inventory before it can be
                            delivered or transferred.
                        </span>
                    </p>

                    <!-- Post-pickup: the parcel is in hand, so this is where
                     "deliver it ourselves or hand it to another company"
                     gets decided. Before pickup there's no choice to make
                     — it just needs a courier sent to collect it. -->
                    <div
                        v-if="awaitingDispatchDecision"
                        class="filter-chips dispatch-mode-chips"
                        role="tablist"
                        aria-label="How should this parcel be routed?"
                    >
                        <button
                            type="button"
                            role="tab"
                            class="filter-chip"
                            :class="{ active: dispatchMode === 'deliver' }"
                            :aria-selected="dispatchMode === 'deliver'"
                            @click="useDeliverMode"
                        >
                            Deliver ourselves
                        </button>
                        <button
                            type="button"
                            role="tab"
                            class="filter-chip"
                            :class="{ active: dispatchMode === 'transfer' }"
                            :aria-selected="dispatchMode === 'transfer'"
                            @click="useTransferMode"
                        >
                            Transfer to another company
                        </button>
                    </div>

                    <template v-if="dispatchMode === 'transfer'">
                        <p
                            v-if="selectedParcel.is_transfer"
                            class="callout-amber callout-block"
                        >
                            <NavIcon name="alert" :size="16" />
                            <span>
                                This parcel is going to
                                {{
                                    selectedParcel.order.region_name ||
                                    'another region'
                                }}, which your company doesn't cover — hand it
                                to a company that operates there.
                            </span>
                        </p>
                        <p
                            v-else-if="
                                selectedParcel.area_fallback_tier ===
                                'provincial'
                            "
                            class="callout-amber callout-block"
                        >
                            <NavIcon name="alert" :size="16" />
                            <span>
                                This parcel is going to
                                {{
                                    selectedParcel.order.municipality_name ||
                                    'a municipality'
                                }}, which none of your barangay assignments
                                cover — hand it to a company that operates
                                there directly.
                            </span>
                        </p>
                        <div class="area-form-grid">
                            <label class="form-field full-field">
                                <span>
                                    Transfer to company
                                    <template v-if="transferMatchedBy === 'municipality' && transferMunicipality">
                                        (covering {{ transferMunicipality }})
                                    </template>
                                    <template v-else-if="transferRegion">
                                        (operating in {{ transferRegion }})
                                    </template>
                                </span>
                                <select
                                    v-model="
                                        transferForm.transfer_to_company_id
                                    "
                                    class="field-input"
                                    required
                                    :disabled="
                                        loadingTransferCompanies ||
                                        !awaitingDispatchDecision
                                    "
                                >
                                    <option value="" disabled>
                                        {{
                                            loadingTransferCompanies
                                                ? 'Loading companies…'
                                                : transferCompanies.length
                                                  ? 'Select a logistics company'
                                                  : transferMatchedBy ===
                                                        'municipality' &&
                                                      transferMunicipality
                                                    ? `No companies cover ${transferMunicipality}`
                                                    : 'No companies available in that region'
                                        }}
                                    </option>
                                    <option
                                        v-for="company in transferCompanies"
                                        :key="company.id"
                                        :value="company.id"
                                    >
                                        {{ company.company_name }}
                                        <template v-if="company.region">
                                            — {{ company.region }}
                                        </template>
                                    </option>
                                </select>
                            </label>
                            <p class="parcel-meta full-field">
                                The parcel stays with you, marked "Awaiting
                                acceptance", until the chosen company accepts or
                                rejects the request. Once accepted it moves to
                                "Transfer ongoing" — you'll then assign one of
                                your own couriers to carry it over.
                            </p>
                            <p
                                v-if="transferOptionsError"
                                class="callout-red full-field"
                            >
                                {{ transferOptionsError }}
                            </p>
                        </div>
                    </template>
                    <div v-else class="area-form-grid">
                        <label
                            v-if="!isTransferCourierLeg"
                            class="form-field full-field"
                        >
                            <span>
                                Barangay assignment
                                <template v-if="!awaitingDispatchDecision">
                                    (optional until pickup)
                                </template>
                            </span>
                            <select
                                v-model="assignmentForm.barangay_assignment_id"
                                class="field-input"
                                :required="awaitingDispatchDecision"
                                @change="selectAssignmentRider"
                            >
                                <option value="">
                                    {{
                                        awaitingDispatchDecision
                                            ? 'Select an active barangay'
                                            : 'Not needed yet — decide after pickup'
                                    }}
                                </option>
                                <option
                                    v-for="assignment in activeAssignments"
                                    :key="assignment.id"
                                    :value="assignment.id"
                                >
                                    {{ assignment.barangay }},
                                    {{ assignment.municipality_name }}
                                </option>
                            </select>
                        </label>
                        <p v-else class="parcel-meta full-field">
                            No barangay is needed — this parcel is headed to
                            {{
                                selectedParcel.transfer_to_company
                                    ?.company_name || 'the other company'
                            }}'s sorting hub, not a buyer's address.
                        </p>
                        <label class="form-field full-field">
                            <span>{{
                                isTransferCourierLeg
                                    ? 'Transfer courier'
                                    : awaitingDispatchDecision
                                      ? 'Delivery rider'
                                      : 'Pickup courier'
                            }}</span>
                            <select
                                v-model="assignmentForm.rider_profile_id"
                                class="field-input"
                                required
                            >
                                <option value="" disabled>
                                    Select an accepted rider
                                </option>
                                <option
                                    v-for="rider in assignmentRiders"
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
                            v-if="
                                selectedParcel.status === 'assigned' ||
                                selectedParcel.status === 'transfer_assigned'
                            "
                            type="button"
                            class="btn-primary"
                            :disabled="saving"
                            @click="handoff"
                        >
                            {{
                                saving
                                    ? 'Confirming…'
                                    : selectedParcel.status ===
                                        'transfer_assigned'
                                      ? 'Confirm courier handoff'
                                      : 'Confirm rider handoff'
                            }}
                        </button>
                        <button
                            v-else-if="dispatchMode === 'transfer'"
                            class="btn-primary"
                            :disabled="saving"
                        >
                            {{ saving ? 'Sending…' : 'Send transfer request' }}
                        </button>
                        <button v-else class="btn-primary" :disabled="saving">
                            {{
                                saving
                                    ? 'Assigning…'
                                    : isTransferCourierAssignment
                                      ? 'Assign transfer courier'
                                      : awaitingDispatchDecision
                                        ? 'Assign delivery'
                                        : 'Assign pickup courier'
                            }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- ---------------- Transfer requests inbox ---------------- -->
            <div
                v-if="showTransferRequests"
                class="modal-overlay"
                @click.self="closeTransferRequests"
            >
                <div class="modal-panel assignment-modal transfer-inbox">
                    <div class="modal-header">
                        <div>
                            <p class="eyebrow">Incoming transfers</p>
                            <h3>Transfer requests</h3>
                        </div>
                        <button
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="closeTransferRequests"
                        >
                            <NavIcon name="close" :size="15" />
                        </button>
                    </div>

                    <p class="panel-copy">
                        Another logistics company is asking you to take custody
                        of these parcels. Accepting doesn't move the parcel yet —
                        their courier still has to physically deliver it to your
                        hub. Once they do, it'll appear in your "Awaiting
                        inventory" queue for you to scan in before it can be
                        dispatched. Reject to send the request back to them.
                    </p>

                    <p v-if="transferRequestsLoading" class="parcel-meta">
                        Loading…
                    </p>
                    <p
                        v-else-if="pendingTransferRequests.length === 0"
                        class="empty-state"
                    >
                        <NavIcon name="inbox" :size="26" />
                        <strong>No transfer requests</strong>
                        <span
                            >Incoming requests from other companies show up
                            here.</span
                        >
                    </p>

                    <ul v-else class="transfer-request-list">
                        <li
                            v-for="req in pendingTransferRequests"
                            :key="req.id"
                            class="transfer-request-row"
                        >
                            <div class="transfer-request-head">
                                <strong>{{
                                    req.order.tracking_number ||
                                    req.order.order_number
                                }}</strong>
                                <span class="parcel-meta"
                                    >from {{ req.from_company?.company_name }}
                                    <template v-if="req.from_company?.region"
                                        >·
                                        {{ req.from_company.region }}</template
                                    ></span
                                >
                            </div>
                            <div class="transfer-request-body">
                                <span>{{ req.order.recipient_name }}</span>
                                <span class="parcel-meta">{{
                                    req.order.address || 'Address incomplete'
                                }}</span>
                                <span class="parcel-meta"
                                    >Requested
                                    {{ formatRelative(req.requested_at) }}</span
                                >
                            </div>

                            <div
                                v-if="rejectingId === req.id"
                                class="transfer-request-reject"
                            >
                                <textarea
                                    v-model.trim="rejectNote"
                                    class="field-input"
                                    rows="2"
                                    placeholder="Reason for rejecting (optional)"
                                ></textarea>
                                <div class="transfer-request-actions">
                                    <button
                                        type="button"
                                        class="btn-sm-outline"
                                        :disabled="respondingId === req.id"
                                        @click="cancelReject"
                                    >
                                        Back
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-sm-danger"
                                        :disabled="respondingId === req.id"
                                        @click="confirmReject(req)"
                                    >
                                        {{
                                            respondingId === req.id
                                                ? 'Rejecting…'
                                                : 'Confirm reject'
                                        }}
                                    </button>
                                </div>
                            </div>
                            <div v-else class="transfer-request-actions">
                                <button
                                    type="button"
                                    class="btn-sm-outline"
                                    :disabled="respondingId === req.id"
                                    @click="startReject(req)"
                                >
                                    Reject
                                </button>
                                <button
                                    type="button"
                                    class="btn-sm-primary"
                                    :disabled="respondingId === req.id"
                                    @click="acceptRequest(req)"
                                >
                                    {{
                                        respondingId === req.id
                                            ? 'Accepting…'
                                            : 'Accept'
                                    }}
                                </button>
                            </div>
                        </li>
                    </ul>

                    <p v-if="transferRequestError" class="callout-red">
                        {{ transferRequestError }}
                    </p>
                </div>
            </div>
        </Teleport>
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
    transferRequests,
    barangayAssignments,
    assignmentRiders,
    parcelStats,
    assignmentStats,
    lastSyncedAt,
    pendingTransferCount,
    loadParcelAssignments,
    loadBarangayAssignments,
    loadTransferRequests,
    receiveParcel,
    assignParcel,
    handoffParcel,
    assignTransferCourier,
    fetchTransferOptions,
    requestParcelTransfer,
    respondToTransferRequest,
    isParcelActionable,
} = useLogistics();
const { notify, notifyError, formatDate, formatRelative, personName } =
    useLogisticsUi();

const PAGE_SIZE = 5;

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
const assignmentForm = reactive({
    barangay_assignment_id: '',
    rider_profile_id: '',
});

// ---- "To Transfer" routing (cross-region parcels this company can't
// deliver itself — handed straight to a company that covers the buyer's
// region, no courier leg involved) ----
const transferForm = reactive({ transfer_to_company_id: '' });
const transferCompanies = ref([]);
const loadingTransferCompanies = ref(false);
const transferOptionsError = ref('');
// What the company list is scoped to, echoed back by the transfer-options
// endpoint so the picker can say what it's filtering on — the buyer's
// region for a regional-tier parcel, or their exact municipality for a
// provincial-tier one (see Api\Logistics\ParcelAssignmentController::
// transferOptions).
const transferRegion = ref('');
const transferMunicipality = ref('');
const transferMatchedBy = ref('region');

// ---- Transfer requests inbox (incoming — other companies asking this
// company to take custody of a parcel they picked up but can't deliver;
// each must be accepted or rejected before custody actually moves) ----
const showTransferRequests = ref(false);
const transferRequestsLoading = ref(false);
const transferRequestError = ref('');
const respondingId = ref('');
const rejectingId = ref('');
const rejectNote = ref('');

const pendingTransferRequests = computed(() =>
    transferRequests.value.filter((req) => req.status === 'pending'),
);

const stage = ref('all');
const search = ref('');
const page = ref(1);

const activeAssignments = computed(() =>
    barangayAssignments.value.filter((a) => a.is_active),
);
const unstaffedAreas = computed(() =>
    Math.max(assignmentStats.value.active - assignmentStats.value.staffed, 0),
);

// Four stages, deliberately coarser than the underlying status machine
// (assigned/handed_off/transfer_pending/transfer_ongoing/transfer_assigned/
// ready_to_transfer/transferred) — the row's own status badge and action
// column (see statusLabel/the queue template) still show the granular
// detail, this just decides which tab a row filters into:
//   - toPickUp ....... not yet collected from the seller.
//   - toTransfer ..... needs a transfer decision, or already made one and
//     is waiting on the other company's answer (transfer_pending).
//   - toDeliver ...... needs delivering — an ordinary local delivery
//     (with or without a rider yet) or an accepted transfer already in
//     a courier's hands, on the way to the other company.
//   - transferred .... terminal: handed off to the other company for
//     good.
//
// Kept in sync with useLogistics.js's parcelStats, which mirrors this
// exact branching for the tab counts.
function stageOf(parcel) {
    if (parcel.status === 'transferred') {
        return 'transferred';
    }

    // A courier has physically handed this company the parcel, but
    // Logistics hasn't scanned it in yet — the For Inventory checkpoint
    // (see App\Models\ParcelAssignment::STATUS_FOR_INVENTORY). Checked
    // before the `status !== 'handed_off'` fallback below, which would
    // otherwise wrongly bucket it into "To pick up". Scanning itself only
    // happens in the Logistics mobile app — this tab is read-only here.
    if (parcel.status === 'for_inventory') {
        return 'awaitingInventory';
    }

    // Offered to another company, waiting on their accept/reject — folded
    // into "To transfer" alongside a parcel that hasn't been offered yet.
    if (parcel.status === 'transfer_pending') {
        return 'toTransfer';
    }

    // Accepted — a courier is carrying it to the other company. Folded
    // into "To be delivered" alongside an ordinary local delivery.
    if (
        parcel.status === 'transfer_ongoing' ||
        parcel.status === 'transfer_assigned' ||
        parcel.status === 'ready_to_transfer'
    ) {
        return 'toDeliver';
    }

    if (parcel.status !== 'handed_off') {
        return 'toPickUp';
    }

    // Regional- and provincial-tier deliveries (buyer outside this
    // company's own region, or in-province but outside any barangay it
    // directly covers — see Delivery Areas' Regional/Provincial pools)
    // are always framed as a transfer job, whether or not a pool rider
    // has already been auto-assigned to it. Skipped for a transfer
    // receipt (is_transfer_receipt): the receiving company was CHOSEN
    // for covering this area, so its own regional/provincial pool is a
    // real, ordinary way to deliver it here — not a sign it needs yet
    // another transfer.
    if (
        !parcel.is_transfer_receipt &&
        (parcel.area_fallback_tier === 'regional' ||
            parcel.area_fallback_tier === 'provincial')
    ) {
        return 'toTransfer';
    }

    // Picked up and back on this desk, whether or not a delivery rider
    // is already on it — both read as "To be delivered" now. If this
    // company doesn't cover the buyer's region (is_transfer, set at
    // intake) it can't deliver this itself, so surface it as needing a
    // transfer rather than leaving staff to notice — they can still
    // override and deliver it. A row that arrived AS a transfer
    // (is_transfer_receipt) skips this: the receiving company was chosen
    // because it covers the buyer's region, so it just needs an ordinary
    // local delivery, not another transfer.
    return parcel.is_transfer && !parcel.is_transfer_receipt
        ? 'toTransfer'
        : 'toDeliver';
}

const stageOptions = computed(() => [
    {
        value: 'toPickUp',
        label: 'To pick up',
        count: parcelStats.value.toPickUp,
    },
    {
        value: 'awaitingInventory',
        label: 'Awaiting inventory',
        count: parcelStats.value.awaitingInventory,
    },
    {
        value: 'toDeliver',
        label: 'To be delivered',
        count: parcelStats.value.toDeliver,
    },
    {
        value: 'toTransfer',
        label: 'To transfer',
        count: parcelStats.value.toTransfer,
    },
    {
        value: 'transferred',
        label: 'Transferred',
        count: parcelStats.value.transferred,
    },
    { value: 'all', label: 'All', count: parcelStats.value.total },
]);

const filtered = computed(() => {
    const term = search.value.toLowerCase();

    return parcelAssignments.value.filter((parcel) => {
        if (stage.value !== 'all' && stageOf(parcel) !== stage.value) {
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
            parcel.barangay_assignment?.barangay,
            parcel.barangay_assignment?.municipality_name,
        ]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(term));
    });
});

// Paginated so a queue of thousands doesn't put thousands of rows in the
// DOM. `filtered` stays the true count for the footer.
const pageCount = computed(() =>
    Math.max(1, Math.ceil(filtered.value.length / PAGE_SIZE)),
);
const visible = computed(() =>
    filtered.value.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE),
);

const hasActiveFilters = computed(
    () => stage.value !== 'all' || search.value.length > 0,
);

const emptyTitle = computed(() => {
    if (search.value) {
        return 'No parcels match that search';
    }

    return (
        {
            toPickUp: 'No parcels waiting for pickup',
            awaitingInventory: 'No parcels awaiting an inventory scan',
            toDeliver: 'No parcels waiting to be delivered',
            toTransfer: 'No parcels waiting to be transferred',
            transferred: 'No parcels handed to another company',
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

// Back to page 1 whenever the result set changes, so switching to a stage
// with 3 parcels doesn't land on an empty page 4.
watch([stage, search], () => {
    page.value = 1;
});

// Assigning or transferring a parcel can drop it out of the current
// stage; if that empties the last page, step back rather than showing a
// blank table.
watch(pageCount, (count) => {
    if (page.value > count) {
        page.value = count;
    }
});

function goToPage(next) {
    page.value = Math.min(Math.max(next, 1), pageCount.value);
}

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

function vehicleRequirementLabel(requiredVehicleType) {
    return (
        {
            car: 'a Car',
            van_or_truck: 'a Van or Truck',
        }[requiredVehicleType] || requiredVehicleType
    );
}

// One label per stage (see stageOf) — the action column next to it
// already spells out finer detail where it matters (e.g. "With courier,
// headed to X", "Requested X ago"). 'transfer_assigned' is checked before
// falling back to the coarse per-stage label: a courier has only been
// *picked* for that leg, not yet physically handed the parcel (that now
// routes through the For Inventory scan — see handoff()'s transfer-leg
// branch), so grouping it under the same "To be delivered" text as
// 'ready_to_transfer' (courier already has it, en route) reads as further
// along than it actually is.
function statusLabel(parcel) {
    if (parcel.status === 'transfer_assigned') {
        return 'Courier assigned';
    }

    return {
        toPickUp: 'To pick up',
        awaitingInventory: 'Awaiting inventory',
        toDeliver: 'To be delivered',
        toTransfer: 'To transfer',
        // parcel.status only ever reaches the literal value 'transferred'
        // via a completed cross-company transfer (confirmTransfer()) — an
        // ordinary local delivery's completion lives on the Order model
        // instead (see this file's class-level stageOf() docblock), so
        // there's no ambiguity in calling this "Transferred" outright.
        transferred: 'Transferred',
    }[stageOf(parcel)];
}

function statusClass(parcel) {
    const stage = stageOf(parcel);

    if (stage === 'transferred') {
        return 'badge-teal';
    }

    if (stage === 'awaitingInventory') {
        return 'badge-slate';
    }

    return stage === 'toTransfer' ? 'badge-indigo' : 'badge-amber';
}

// One label for every stage — what happens when it's clicked still
// depends on the parcel (see openAssignment/stageOf), but the button
// itself no longer changes width row to row as text length varies.
function actionLabel() {
    return 'Manage';
}

// Which side of the post-pickup decision the routing modal is showing.
// Only meaningful for a parcel that's already been collected — a fresh
// parcel just gets a pickup courier, no destination decision yet.
const dispatchMode = ref('deliver'); // 'deliver' | 'transfer'

// True once a courier has picked the parcel up and handed it back — the
// point where staff choose between a local delivery and a transfer.
// Normally that means no rider yet, but a regional- or provincial-tier
// match (buyer outside this company's own region, or in-province but
// outside any barangay it directly covers) auto-assigns a pool rider
// immediately — staff still need the option to hand it to another
// company instead, so this stays true for either case too. Skipped for
// a transfer receipt once it has a rider (is_transfer_receipt): the
// receiving company was chosen for covering this area, so its own
// regional/provincial pool is an ordinary way to deliver it, not
// something to keep re-offering a transfer for. Mirrors Api\Logistics\
// ParcelAssignmentController::awaitingDispatchDecision.
const awaitingDispatchDecision = computed(
    () =>
        selectedParcel.value?.status === 'handed_off' &&
        (!selectedParcel.value?.rider ||
            (!selectedParcel.value?.is_transfer_receipt &&
                (selectedParcel.value?.area_fallback_tier === 'regional' ||
                    selectedParcel.value?.area_fallback_tier ===
                        'provincial'))),
);

// An accepted transfer, whether or not a courier has been picked yet —
// the routing modal skips the barangay picker entirely for both: there's
// no buyer address involved, only which of this company's own riders
// carries it to the target company's hub.
const isTransferCourierLeg = computed(
    () =>
        selectedParcel.value?.status === 'transfer_ongoing' ||
        selectedParcel.value?.status === 'transfer_assigned',
);

// Narrower than isTransferCourierLeg: only 'transfer_ongoing' still has an
// assign action to submit — 'transfer_assigned' only has the handoff
// button below (see the modal-actions button chain).
const isTransferCourierAssignment = computed(
    () => selectedParcel.value?.status === 'transfer_ongoing',
);

async function openAssignment(parcel) {
    selectedParcel.value = parcel;
    assignmentError.value = '';
    transferOptionsError.value = '';

    assignmentForm.barangay_assignment_id = parcel.barangay_assignment?.id || '';
    assignmentForm.rider_profile_id = parcel.rider?.id || '';
    transferForm.transfer_to_company_id = '';

    // A picked-up parcel this company can't deliver (buyer is in another
    // region) opens straight on the transfer side — that's the expected
    // action for it. Staff can still switch back to delivering it.
    if (stageOf(parcel) === 'toTransfer') {
        await useTransferMode();

        return;
    }

    dispatchMode.value = 'deliver';
}

// Staff flipping to "send this to another company" on a picked-up
// parcel — the company list is only fetched when they actually ask for
// it, since most parcels never need it.
async function useTransferMode() {
    dispatchMode.value = 'transfer';
    assignmentError.value = '';

    if (transferCompanies.value.length === 0) {
        await loadTransferCompanies(selectedParcel.value.id);
    }
}

function useDeliverMode() {
    dispatchMode.value = 'deliver';
    assignmentError.value = '';
}

async function loadTransferCompanies(parcelId) {
    loadingTransferCompanies.value = true;
    transferOptionsError.value = '';

    try {
        const payload = await fetchTransferOptions(parcelId);
        transferCompanies.value = payload.data || [];
        transferRegion.value = payload.meta?.buyer_region || '';
        transferMunicipality.value = payload.meta?.buyer_municipality || '';
        transferMatchedBy.value = payload.meta?.matched_by || 'region';
    } catch (error) {
        transferOptionsError.value = error.message;
        transferCompanies.value = [];
        transferRegion.value = '';
        transferMunicipality.value = '';
    } finally {
        loadingTransferCompanies.value = false;
    }
}

function closeAssignment() {
    selectedParcel.value = null;
    assignmentError.value = '';
    // Cleared because the list is scoped to one parcel's buyer region —
    // the next parcel opened must fetch its own.
    transferCompanies.value = [];
    transferRegion.value = '';
    transferMunicipality.value = '';
    focusScanner();
}

function selectAssignmentRider() {
    const assignment = barangayAssignments.value.find(
        (item) => item.id === assignmentForm.barangay_assignment_id,
    );

    // A barangay has at most one appointed rider — auto-fill it as a
    // starting point, staff can still override before confirming.
    if (assignment?.rider) {
        assignmentForm.rider_profile_id = assignment.rider.id;
    }
}

async function receive() {
    receiving.value = true;
    lookupMessage.value = '';
    lookupIsError.value = false;

    try {
        const parcel = await receiveParcel(trackingNumber.value);

        lookupMessage.value = parcel.barangay_assignment
            ? `${parcel.barangay_assignment.barangay}, ${parcel.barangay_assignment.municipality_name} matched. Review and confirm the rider.`
            : 'Parcel received. No barangay assignment matched; assign it manually.';
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
        if (dispatchMode.value === 'transfer') {
            await requestParcelTransfer(
                selectedParcel.value.id,
                transferForm.transfer_to_company_id,
            );
            notify(
                'Transfer request sent. The parcel moves once the other company accepts and a courier carries it over.',
            );
        } else if (isTransferCourierAssignment.value) {
            await assignTransferCourier(
                selectedParcel.value.id,
                assignmentForm.rider_profile_id,
            );
            notify('Transfer courier assigned.');
        } else {
            await assignParcel(
                selectedParcel.value.id,
                assignmentForm.barangay_assignment_id,
                assignmentForm.rider_profile_id,
            );
            notify(
                awaitingDispatchDecision.value
                    ? 'Delivery rider assigned.'
                    : 'Pickup courier assigned.',
            );
        }

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
        const isTransferHandoff = selectedParcel.value?.status === 'transfer_assigned';
        await handoffParcel(selectedParcel.value.id);
        notify(
            isTransferHandoff
                ? 'Courier handoff confirmed. Scan it into inventory from the Logistics mobile app before they can confirm the transfer.'
                : 'Rider handoff confirmed. Scan it into inventory from the Logistics mobile app before it can be dispatched.',
        );
        closeAssignment();
    } catch (error) {
        assignmentError.value = error.message;
    } finally {
        saving.value = false;
    }
}

// ---- Transfer requests inbox ----

async function openTransferRequests() {
    showTransferRequests.value = true;
    transferRequestError.value = '';
    cancelReject();
    transferRequestsLoading.value = true;

    try {
        await loadTransferRequests({ force: true });
    } catch (error) {
        transferRequestError.value = error.message;
    } finally {
        transferRequestsLoading.value = false;
    }
}

function closeTransferRequests() {
    showTransferRequests.value = false;
    cancelReject();
    focusScanner();
}

function startReject(req) {
    rejectingId.value = req.id;
    rejectNote.value = '';
}

function cancelReject() {
    rejectingId.value = '';
    rejectNote.value = '';
}

// action: 'accept' | 'reject' (this company answering) or 'cancel' (the
// origin company withdrawing). Accepting doesn't create anything in this
// company's own sorting queue yet — custody only moves once the origin's
// courier physically delivers it here (Api\Logistics\
// ParcelAssignmentController::acceptTransferRequest's docblock) — but the
// origin's own queue view of the request changes either way, so pull it
// fresh regardless.
async function respond(req, action, note = null) {
    respondingId.value = req.id;
    transferRequestError.value = '';

    try {
        await respondToTransferRequest(req.id, action, note);
        await loadParcelAssignments({ force: true });
        notify(
            action === 'accept'
                ? "Transfer accepted. It'll appear in your \"Awaiting inventory\" queue once their courier delivers it."
                : 'Transfer request rejected.',
        );
        cancelReject();
    } catch (error) {
        transferRequestError.value = error.message;
    } finally {
        respondingId.value = '';
    }
}

const acceptRequest = (req) => respond(req, 'accept');
const confirmReject = (req) => respond(req, 'reject', rejectNote.value || null);

// Origin side: withdraw a request the target hasn't answered yet, from
// the parcel's row in the queue.
async function cancelRequest(parcel) {
    const requestId = parcel.transfer_request?.id;

    if (!requestId) {
        return;
    }

    try {
        await respondToTransferRequest(requestId, 'cancel');
        await loadParcelAssignments({ force: true });
        notify('Transfer request cancelled.');
    } catch (error) {
        notifyError(error, 'Could not cancel the transfer request.');
    }
}

async function load(force = false) {
    refreshing.value = true;

    try {
        await Promise.all([
            loadParcelAssignments({ force }),
            loadBarangayAssignments({ force }),
            loadTransferRequests({ force }).catch(() => {}),
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

<style scoped>
.count-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    margin-left: 7px;
    padding: 0 5px;
    border-radius: 999px;
    background: #dc2626;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
}

/* The auto-assign badge counts work waiting, not a problem, so it reads
   in the brand teal rather than the red used for the transfers inbox. */
.count-pill-teal {
    background: var(--lg-primary, #0d9488);
}

.auto-assign-modal {
    max-width: 520px;
}

.auto-assign-rules {
    margin: 0 0 16px;
    padding-left: 20px;
    display: flex;
    flex-direction: column;
    gap: 7px;
    font-size: 13px;
    color: var(--lg-slate-600, #475569);
}

.auto-assign-progress {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 26px 0 18px;
    text-align: center;
}

.auto-assign-count {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: var(--lg-ink, #0f172a);
}

.auto-assign-bar {
    width: 100%;
    max-width: 320px;
    height: 7px;
    border-radius: 999px;
    background: var(--lg-border, #e2e8f0);
    overflow: hidden;
}

.auto-assign-bar-fill {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--lg-primary, #0d9488);
    transition: width 0.25s ease;
}

.auto-assign-tally {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 7px;
    margin: 0;
}

.auto-assign-outcomes {
    list-style: none;
    margin: 0 0 16px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
    border: 1px solid var(--lg-border, #e2e8f0);
    border-radius: 10px;
    overflow: hidden;
}

.auto-assign-outcomes li {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding: 9px 14px;
    background: #f8fafc;
    font-size: 13px;
    color: var(--lg-slate-600, #475569);
}

.auto-assign-outcomes strong {
    min-width: 26px;
    font-size: 15px;
    color: var(--lg-ink, #0f172a);
}

.auto-assign-problems-label {
    margin-bottom: 6px;
}

.auto-assign-problems {
    list-style: none;
    margin: 0 0 16px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 30vh;
    overflow-y: auto;
}

.auto-assign-problems li {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 9px 12px;
    border: 1px solid var(--lg-border, #e2e8f0);
    border-radius: 8px;
    font-size: 12.5px;
    color: var(--lg-slate-600, #475569);
}

.auto-assign-problems strong {
    font-size: 13px;
    color: var(--lg-ink, #0f172a);
}

.pending-transfer-cell {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}

.transfer-inbox {
    max-width: 560px;
}

.transfer-request-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 60vh;
    overflow-y: auto;
}

.transfer-request-row {
    border: 1px solid var(--lg-border, #e2e8f0);
    border-radius: 10px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.transfer-request-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 8px;
}

.transfer-request-body {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.transfer-request-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.transfer-request-reject {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.btn-sm-danger {
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: var(--lg-radius-sm, 8px);
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.btn-sm-danger:hover {
    background: #b91c1c;
}
.btn-sm-danger:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>
