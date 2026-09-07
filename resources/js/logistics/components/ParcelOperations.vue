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
                    class="btn-primary btn-icon"
                    :disabled="
                        autoAssignCandidates.length === 0 ||
                        autoRun.phase === 'running'
                    "
                    :title="
                        autoAssignCandidates.length === 0
                            ? 'Every parcel on this desk already has a rider'
                            : 'Match each parcel to its area and rider automatically'
                    "
                    @click="openAutoAssign"
                >
                    <NavIcon name="truck" :size="15" />
                    Auto assign
                    <span
                        v-if="autoAssignCandidates.length"
                        class="count-pill count-pill-teal"
                        >{{ autoAssignCandidates.length }}</span
                    >
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
                        ? 'active area has'
                        : 'active areas have'
                }}
                no appointed rider, so parcels routed there can't be assigned.
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
            <!-- ---------------- Auto assign ----------------
             One modal, three phases: confirm what's about to happen,
             show live progress while the queue is swept a parcel at a
             time, then report what each parcel actually did. -->
            <div
                v-if="autoRun.open"
                class="modal-overlay"
                @click.self="autoRun.phase === 'running' || closeAutoAssign()"
            >
                <div
                    class="modal-panel auto-assign-modal"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="auto-assign-title"
                >
                    <div class="modal-header">
                        <div>
                            <p class="eyebrow">Automatic routing</p>
                            <h3 id="auto-assign-title">
                                {{
                                    autoRun.phase === 'done'
                                        ? 'Auto assign finished'
                                        : 'Auto assign'
                                }}
                            </h3>
                        </div>
                        <button
                            v-if="autoRun.phase !== 'running'"
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="closeAutoAssign"
                        >
                            <NavIcon name="close" :size="15" />
                        </button>
                    </div>

                    <!-- Phase 1 — confirm -->
                    <template v-if="autoRun.phase === 'confirm'">
                        <p class="panel-copy">
                            <strong
                                >{{ autoAssignCandidates.length }}
                                {{
                                    autoAssignCandidates.length === 1
                                        ? 'parcel'
                                        : 'parcels'
                                }}</strong
                            >
                            on this desk still need a rider. Each one will be
                            matched to a delivery area by its address, then
                            handed to the next available rider in that area.
                        </p>
                        <ul class="auto-assign-rules">
                            <li>
                                The area must match the parcel's province
                                <em>and</em> municipality exactly — no nearby or
                                approximate areas.
                            </li>
                            <li>
                                Riders take turns in rotation, so the work is
                                spread evenly across each area.
                            </li>
                            <li>
                                Riders who are off shift or already at their
                                parcel quota are skipped.
                            </li>
                            <li>
                                A parcel with no matching area, or no available
                                rider, is left untouched for you to handle by
                                hand.
                            </li>
                        </ul>
                        <p class="parcel-meta">
                            Parcels are routed one at a time, so this can take a
                            moment. You can stop it partway — anything already
                            assigned stays assigned.
                        </p>
                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-outline"
                                @click="closeAutoAssign"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="btn-primary"
                                @click="runAutoAssign"
                            >
                                Auto assign
                                {{ autoAssignCandidates.length }}
                                {{
                                    autoAssignCandidates.length === 1
                                        ? 'parcel'
                                        : 'parcels'
                                }}
                            </button>
                        </div>
                    </template>

                    <!-- Phase 2 — running -->
                    <template v-else-if="autoRun.phase === 'running'">
                        <div class="auto-assign-progress">
                            <div
                                class="loading-spinner"
                                role="status"
                                aria-label="Assigning parcels"
                            ></div>
                            <p class="auto-assign-count" aria-live="polite">
                                {{ autoRun.processed }} of {{ autoRun.total }}
                                {{ autoRun.total === 1 ? 'parcel' : 'parcels' }}
                                processed
                            </p>
                            <div
                                class="auto-assign-bar"
                                role="progressbar"
                                :aria-valuenow="autoRun.processed"
                                aria-valuemin="0"
                                :aria-valuemax="autoRun.total"
                            >
                                <span
                                    class="auto-assign-bar-fill"
                                    :style="{
                                        width: `${autoProgressPercent}%`,
                                    }"
                                ></span>
                            </div>
                            <p class="auto-assign-tally">
                                <span class="badge badge-teal"
                                    >{{ autoRun.assigned }} assigned</span
                                >
                                <span
                                    v-if="autoUnrouted"
                                    class="badge badge-amber"
                                    >{{ autoUnrouted }} need attention</span
                                >
                                <span
                                    v-if="autoRun.failed"
                                    class="badge badge-red"
                                    >{{ autoRun.failed }} failed</span
                                >
                            </p>
                            <p v-if="autoRun.current" class="parcel-meta">
                                Working on {{ autoRun.current }}…
                            </p>
                        </div>
                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-outline"
                                :disabled="autoRun.stopping"
                                @click="stopAutoAssign"
                            >
                                {{ autoRun.stopping ? 'Stopping…' : 'Stop' }}
                            </button>
                        </div>
                    </template>

                    <!-- Phase 3 — summary -->
                    <template v-else>
                        <p class="panel-copy">
                            {{ autoSummary }}
                        </p>

                        <ul class="auto-assign-outcomes">
                            <li>
                                <strong>{{ autoRun.assigned }}</strong>
                                <span>assigned to a rider</span>
                            </li>
                            <li v-if="autoRun.noArea">
                                <strong>{{ autoRun.noArea }}</strong>
                                <span>no matching delivery area</span>
                            </li>
                            <li v-if="autoRun.noRider">
                                <strong>{{ autoRun.noRider }}</strong>
                                <span
                                    >area matched, but no rider available</span
                                >
                            </li>
                            <li v-if="autoRun.skipped">
                                <strong>{{ autoRun.skipped }}</strong>
                                <span>already routed</span>
                            </li>
                            <li v-if="autoRun.failed">
                                <strong>{{ autoRun.failed }}</strong>
                                <span>failed</span>
                            </li>
                        </ul>

                        <template v-if="autoRun.problems.length">
                            <p class="field-label auto-assign-problems-label">
                                Left for you to handle
                            </p>
                            <ul class="auto-assign-problems">
                                <li
                                    v-for="problem in autoRun.problems"
                                    :key="problem.id"
                                >
                                    <strong>{{ problem.tracking }}</strong>
                                    <span>{{ problem.message }}</span>
                                </li>
                            </ul>
                            <p
                                v-if="autoRun.noArea"
                                class="callout-amber callout-block"
                            >
                                <NavIcon name="alert" :size="16" />
                                <span>
                                    Parcels with no matching area need a
                                    delivery area covering that province and
                                    municipality before they can be routed
                                    automatically.
                                </span>
                                <button
                                    type="button"
                                    class="btn-link"
                                    @click="goToAreas"
                                >
                                    Manage areas
                                </button>
                            </p>
                        </template>

                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-primary"
                                @click="closeAutoAssign"
                            >
                                Done
                            </button>
                        </div>
                    </template>
                </div>
            </div>

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
                        <div class="area-form-grid">
                            <label class="form-field full-field">
                                <span>
                                    Transfer to company
                                    <template v-if="transferRegion">
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
                                No courier is needed. The parcel stays with you,
                                marked "Awaiting acceptance", until the chosen
                                company accepts the request — only then does it
                                move to their queue. They can also reject it.
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
                        <label class="form-field full-field">
                            <span>
                                Delivery area
                                <template v-if="!awaitingDispatchDecision">
                                    (optional until pickup)
                                </template>
                            </span>
                            <select
                                v-model="assignmentForm.delivery_area_id"
                                class="field-input"
                                :required="awaitingDispatchDecision"
                                @change="selectAreaRider"
                            >
                                <option value="">
                                    {{
                                        awaitingDispatchDecision
                                            ? 'Select an active area'
                                            : 'Not needed yet — decide after pickup'
                                    }}
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
                            <span>{{
                                awaitingDispatchDecision
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
                            {{
                                saving ? 'Confirming…' : 'Confirm rider handoff'
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
                        of these parcels. Accept to pull the parcel into your
                        "To be delivered" queue; reject to send it back to them.
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
    deliveryAreas,
    areaRiders,
    parcelStats,
    areaStats,
    lastSyncedAt,
    pendingTransferCount,
    loadParcelAssignments,
    loadDeliveryAreas,
    loadTransferRequests,
    receiveParcel,
    assignParcel,
    autoAssignParcel,
    handoffParcel,
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
const assignmentForm = reactive({ delivery_area_id: '', rider_profile_id: '' });

// ---- "To Transfer" routing (cross-region parcels this company can't
// deliver itself — handed straight to a company that covers the buyer's
// region, no courier leg involved) ----
const transferForm = reactive({ transfer_to_company_id: '' });
const transferCompanies = ref([]);
const loadingTransferCompanies = ref(false);
const transferOptionsError = ref('');
// The buyer's region the company list is scoped to, echoed back by the
// transfer-options endpoint so the picker can say what it's filtering on.
const transferRegion = ref('');

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

const stage = ref('actionable');
const search = ref('');
const page = ref(1);

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
//
// Every parcel starts in "To pick up" regardless of where it's headed —
// whether it can be delivered locally or has to go to another company is
// only decided at the "To be delivered" step, once a courier has
// actually collected it. Handing it to another company takes effect
// immediately, so it goes straight from this desk to "Transferred".
function stageOf(parcel) {
    if (parcel.status === 'transferred') {
        return 'transferred';
    }

    // Offered to another company, waiting on their accept/reject.
    if (parcel.status === 'transfer_pending') {
        return 'transferPending';
    }

    if (parcel.status !== 'handed_off') {
        return 'toPickUp';
    }

    if (parcel.rider) {
        return 'outForDelivery';
    }

    // Picked up and back on this desk. If this company doesn't cover the
    // buyer's region (is_transfer, set at intake) it can't deliver this
    // itself, so surface it as needing a transfer rather than leaving
    // staff to notice — they can still override and deliver it.
    return parcel.is_transfer ? 'toTransfer' : 'toDeliver';
}

const stageOptions = computed(() => [
    {
        value: 'actionable',
        label: 'Needs action',
        count:
            parcelStats.value.toPickUp +
            parcelStats.value.toDeliver +
            parcelStats.value.toTransfer,
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
        value: 'toTransfer',
        label: 'To transfer',
        count: parcelStats.value.toTransfer,
    },
    {
        value: 'transferPending',
        label: 'Awaiting acceptance',
        count: parcelStats.value.transferPending,
    },
    {
        value: 'outForDelivery',
        label: 'Out for delivery',
        count: parcelStats.value.outForDelivery,
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
            actionable: 'Nothing needs action',
            toPickUp: 'No parcels waiting for pickup',
            toDeliver: 'No parcels waiting for a delivery rider',
            toTransfer: 'No parcels waiting to be transferred',
            transferPending: 'No transfer requests awaiting acceptance',
            outForDelivery: 'No parcels out for delivery',
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

function formatCoverage(area) {
    const municipalities = area.municipalities || [];
    const names = municipalities.map((m) => m.name);
    const summary =
        names.length > 2
            ? `${names.slice(0, 2).join(', ')} +${names.length - 2}`
            : names.join(', ');

    return [summary, area.province_name].filter(Boolean).join(', ');
}

function statusLabel(parcel) {
    return {
        toPickUp: 'To pick up',
        toDeliver: 'To be delivered',
        toTransfer: 'To transfer',
        transferPending: 'Awaiting acceptance',
        outForDelivery: 'Out for delivery',
        transferred: 'Transferred',
    }[stageOf(parcel)];
}

function statusClass(parcel) {
    const stage = stageOf(parcel);

    if (stage === 'outForDelivery' || stage === 'transferred') {
        return 'badge-teal';
    }

    return stage === 'toTransfer' || stage === 'transferPending'
        ? 'badge-indigo'
        : 'badge-amber';
}

function actionLabel(parcel) {
    const stage = stageOf(parcel);

    if (stage === 'toTransfer') {
        return 'Request transfer';
    }

    // Post-pickup: this is where "deliver locally or hand to another
    // company" gets decided, so the button opens that choice.
    if (stage === 'toDeliver') {
        return 'Assign delivery';
    }

    return parcel.status === 'assigned' ? 'Review' : 'Assign pickup';
}

// Which side of the post-pickup decision the routing modal is showing.
// Only meaningful for a parcel that's already been collected — a fresh
// parcel just gets a pickup courier, no destination decision yet.
const dispatchMode = ref('deliver'); // 'deliver' | 'transfer'

// True once a courier has picked the parcel up and handed it back — the
// point where staff choose between a local delivery and a transfer.
const awaitingDispatchDecision = computed(
    () =>
        selectedParcel.value?.status === 'handed_off' &&
        !selectedParcel.value?.rider,
);

async function openAssignment(parcel) {
    selectedParcel.value = parcel;
    assignmentError.value = '';
    transferOptionsError.value = '';

    assignmentForm.delivery_area_id = parcel.delivery_area?.id || '';
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
    } catch (error) {
        transferOptionsError.value = error.message;
        transferCompanies.value = [];
        transferRegion.value = '';
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
        if (dispatchMode.value === 'transfer') {
            await requestParcelTransfer(
                selectedParcel.value.id,
                transferForm.transfer_to_company_id,
            );
            notify(
                'Transfer request sent. The parcel moves once the other company accepts.',
            );
        } else {
            await assignParcel(
                selectedParcel.value.id,
                assignmentForm.delivery_area_id,
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
        await handoffParcel(selectedParcel.value.id);
        notify('Rider handoff confirmed.');
        closeAssignment();
    } catch (error) {
        assignmentError.value = error.message;
    } finally {
        saving.value = false;
    }
}

// ---- Auto assign ----
//
// Sweeps the queue a parcel at a time, letting the server match each
// one's address to a delivery area and pick the next rider in that
// area's rotation (App\Services\ParcelAutoAssignService).
//
// Sequential rather than parallel on purpose: the round-robin cursor is
// read and written per parcel, so firing them at once would hand several
// parcels to the same rider. It's also what makes a real progress count
// possible, which matters because a full queue takes a while.

const autoRun = reactive({
    open: false,
    phase: 'confirm', // 'confirm' | 'running' | 'done'
    total: 0,
    processed: 0,
    assigned: 0,
    noArea: 0,
    noRider: 0,
    skipped: 0,
    failed: 0,
    current: '', // tracking number currently in flight
    stopping: false,
    problems: [],
});

// Parcels auto-assign will act on: still on this desk, and with nobody
// on them yet. The "no rider" half is what stops a second click from
// re-routing parcels the first click already assigned.
const autoAssignCandidates = computed(() =>
    parcelAssignments.value.filter(
        (parcel) => isParcelActionable(parcel) && !parcel.rider,
    ),
);

const autoProgressPercent = computed(() =>
    autoRun.total ? Math.round((autoRun.processed / autoRun.total) * 100) : 0,
);

// Routed nowhere, but for an ordinary reason staff can act on — as
// opposed to `failed`, which means the request itself broke.
const autoUnrouted = computed(() => autoRun.noArea + autoRun.noRider);

const autoSummary = computed(() => {
    if (autoRun.assigned === 0) {
        return 'No parcels could be routed automatically. The ones below need a delivery area or an available rider first.';
    }

    const assigned = `${autoRun.assigned} of ${autoRun.total} ${
        autoRun.total === 1 ? 'parcel' : 'parcels'
    } assigned to a rider.`;

    return autoUnrouted.value + autoRun.failed > 0
        ? `${assigned} The rest were left untouched.`
        : assigned;
});

function openAutoAssign() {
    Object.assign(autoRun, {
        open: true,
        phase: 'confirm',
        total: 0,
        processed: 0,
        assigned: 0,
        noArea: 0,
        noRider: 0,
        skipped: 0,
        failed: 0,
        current: '',
        stopping: false,
        problems: [],
    });
}

function closeAutoAssign() {
    autoRun.open = false;
    focusScanner();
}

function stopAutoAssign() {
    autoRun.stopping = true;
}

function goToAreas() {
    closeAutoAssign();
    emit('open-section', 'areas');
}

function parcelLabel(parcel) {
    return (
        parcel.order?.tracking_number || parcel.order?.order_number || 'Parcel'
    );
}

async function runAutoAssign() {
    // Snapshotted up front: every assignment replaces rows in
    // `parcelAssignments`, so iterating the live computed list would
    // shrink out from under the loop.
    const queue = autoAssignCandidates.value.map((parcel) => ({
        id: parcel.id,
        tracking: parcelLabel(parcel),
    }));

    autoRun.phase = 'running';
    autoRun.total = queue.length;

    for (const item of queue) {
        if (autoRun.stopping) {
            break;
        }

        autoRun.current = item.tracking;

        try {
            const { outcome, message } = await autoAssignParcel(item.id);

            if (outcome === 'assigned') {
                autoRun.assigned += 1;
            } else if (outcome === 'skipped') {
                autoRun.skipped += 1;
            } else {
                // 'no_area' / 'no_rider' — the parcel is untouched and
                // needs a person, so it's named in the summary.
                autoRun[outcome === 'no_area' ? 'noArea' : 'noRider'] += 1;
                autoRun.problems.push({
                    id: item.id,
                    tracking: item.tracking,
                    message,
                });
            }
        } catch (error) {
            autoRun.failed += 1;
            autoRun.problems.push({
                id: item.id,
                tracking: item.tracking,
                message: error.message,
            });
        }

        autoRun.processed += 1;
    }

    autoRun.current = '';
    autoRun.phase = 'done';

    if (autoRun.assigned > 0) {
        notify(
            `${autoRun.assigned} ${
                autoRun.assigned === 1 ? 'parcel' : 'parcels'
            } assigned automatically.`,
        );
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
// origin company withdrawing). Either way the sorting queue changed —
// a new "to be delivered" row on accept, a released origin row otherwise
// — so pull it fresh.
async function respond(req, action, note = null) {
    respondingId.value = req.id;
    transferRequestError.value = '';

    try {
        await respondToTransferRequest(req.id, action, note);
        await loadParcelAssignments({ force: true });
        notify(
            action === 'accept'
                ? 'Transfer accepted. The parcel is now in your delivery queue.'
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
            loadDeliveryAreas({ force }),
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
