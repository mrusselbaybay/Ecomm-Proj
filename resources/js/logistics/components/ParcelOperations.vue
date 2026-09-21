<!-- resources/js/logistics/components/ParcelOperations.vue
     The sorting desk — a filterable, searchable queue of every parcel
     moving through this company, from pickup through delivery or
     transfer. Parcels enter the queue on their own (the seller's handover
     creates the row; see ParcelIntakeService) — this page is where staff
     route each one, not where they're scanned in.

     2026 redesign: the six workflow stages now double as the queue's
     filter (one "stage rail" instead of a separate KPI grid + tab strip
     saying the same thing twice), rows carry an exception flag for
     parcels that need a specific vehicle, staff can multi-select a page
     of "To pick up" rows and assign one courier to all of them in one
     confirmation, and the Details view is a side drawer so staff never
     leave the queue to read an order. Still filtered by stage, searchable,
     and windowed — a busy centre's queue never lands thousands of rows in
     the DOM at once. -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div class="page-header-titles">
                <span class="page-icon-badge">
                    <NavIcon name="parcels" :size="22" />
                </span>
                <div>
                    <h2 class="page-title">Parcel sorting</h2>
                    <p class="page-subtitle">
                        Receive parcels from sellers, match the delivery area,
                        and hand each parcel to the correct rider.
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
                <button
                    type="button"
                    class="btn-outline btn-icon"
                    aria-label="Account settings"
                    title="Account settings"
                    @click="emit('open-section', 'account')"
                >
                    <NavIcon name="account" :size="15" />
                </button>
            </div>
        </header>

        <!-- ---------------- Stage rail ----------------
             Every stage is both the count you'd otherwise put in a KPI
             card AND the filter you'd otherwise put in a tab strip — one
             row of tiles instead of two blocks of UI stating the same six
             numbers twice. -->
        <div class="stage-rail" role="tablist" aria-label="Filter by stage">
            <button
                v-for="tile in stageRail"
                :key="tile.key"
                type="button"
                role="tab"
                class="stage-tile"
                :class="[`tone-${tile.tone}`, { 'is-active': stage === tile.key }]"
                :aria-selected="stage === tile.key"
                @click="setStage(tile.key)"
            >
                <span class="stage-tile-icon">
                    <NavIcon :name="tile.icon" :size="16" />
                </span>
                <span class="stage-tile-copy">
                    <span v-if="loading" class="skeleton skeleton-stat"></span>
                    <span v-else class="stage-tile-value">{{ tile.count }}</span>
                    <span class="stage-tile-label">{{ tile.label }}</span>
                </span>
            </button>
        </div>

        <!-- ---------------- Queue ---------------- -->
        <section class="card queue-card">
            <div class="sort-toolbar">
                <div class="search-input sort-search">
                    <NavIcon name="search" :size="15" class="icon" />
                    <input
                        v-model.trim="search"
                        type="search"
                        placeholder="Search tracking no., recipient, address or courier"
                        aria-label="Search the sorting queue"
                    />
                </div>
                <button
                    type="button"
                    class="exception-toggle"
                    :class="{ 'is-active': showExceptionsOnly }"
                    :aria-pressed="showExceptionsOnly"
                    @click="toggleExceptionsOnly"
                >
                    <NavIcon name="alert" :size="14" />
                    Exceptions
                    <span v-if="exceptionCount" class="exception-toggle-count">{{
                        exceptionCount
                    }}</span>
                </button>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="btn-sm-outline"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <div class="table-scroll">
                <table class="admin-table sort-table">
                    <thead>
                        <tr>
                            <th>Parcel</th>
                            <th>Destination</th>
                            <th class="text-right">Amount</th>
                            <th>Courier</th>
                            <th>Status</th>
                            <th class="col-action text-right">Actions</th>
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
                        <tr
                            v-for="parcel in visible"
                            :key="parcel.id"
                            class="sort-row"
                        >
                            <td data-label="Parcel">
                                <div class="parcel-id-cell">
                                    <strong class="parcel-number">{{
                                        parcel.order.tracking_number ||
                                        parcel.order.order_number
                                    }}</strong>
                                    <span
                                        v-if="isException(parcel)"
                                        class="exception-flag"
                                        :title="`Requires ${vehicleRequirementLabel(parcel.required_vehicle_type)}`"
                                    >
                                        <NavIcon name="alert" :size="12" />
                                    </span>
                                </div>
                                <span class="parcel-meta">
                                    {{
                                        parcel.order.placed_at
                                            ? formatDate(parcel.order.placed_at)
                                            : '—'
                                    }}
                                    <span class="parcel-meta-dot">·</span>
                                    {{ parcel.order.service_type || 'Standard' }}
                                </span>
                            </td>
                            <td data-label="Destination">
                                <span
                                    class="badge badge-slate address-phase-badge"
                                    >{{
                                        parcel.phase === 'pickup'
                                            ? 'Pick up from seller'
                                            : 'Deliver to buyer'
                                    }}</span
                                >
                                <span class="parcel-recipient">{{
                                    parcel.order.buyer_name || '—'
                                }}</span>
                                <span class="address-cell">{{
                                    parcel.order.address || 'Address incomplete'
                                }}</span>
                            </td>
                            <td class="text-right" data-label="Amount">
                                <span class="row-price">{{
                                    formatCurrency(parcel.order.product_price)
                                }}</span>
                            </td>
                            <td data-label="Courier">
                                <span v-if="parcel.rider">{{
                                    personName(parcel.rider)
                                }}</span>
                                <span v-else class="muted-cell"
                                    >Unassigned</span
                                >
                            </td>
                            <td data-label="Status">
                                <span
                                    class="badge status-badge"
                                    :class="statusClass(parcel)"
                                >
                                    <NavIcon
                                        :name="statusIcon(parcel)"
                                        :size="12"
                                    />
                                    {{ statusLabel(parcel) }}
                                </span>
                                <span
                                    v-if="
                                        !parcel.is_scanned &&
                                        stageOf(parcel) === 'toPickUp'
                                    "
                                    class="parcel-meta"
                                    >Not yet scanned in</span
                                >
                            </td>
                            <td class="col-action text-right" data-label="Actions">
                                <div class="row-actions">
                                    <button
                                        type="button"
                                        class="row-icon-btn"
                                        aria-label="View parcel details"
                                        title="Details"
                                        @click="openDetails(parcel)"
                                    >
                                        <NavIcon name="search" :size="15" />
                                    </button>
                                    <button
                                        v-if="isParcelActionable(parcel)"
                                        type="button"
                                        class="row-icon-btn is-primary"
                                        aria-label="Manage this parcel"
                                        title="Manage"
                                        @click="openAssignment(parcel)"
                                    >
                                        <NavIcon name="truck" :size="15" />
                                    </button>
                                </div>
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

        <!-- Modals/drawer teleport out of .logistics-main (which has a
             transform, so it would otherwise be the containing block for
             the fixed overlay and let the dim backdrop scroll away). -->
        <Teleport to=".logistics-shell">
            <!-- ---------------- Details drawer ---------------- -->
            <Transition name="drawer">
                <div
                    v-if="detailsParcel"
                    class="drawer-overlay"
                    @click.self="closeDetails"
                >
                    <aside class="drawer-panel">
                        <div class="modal-header drawer-header">
                            <div>
                                <p class="eyebrow">Parcel details</p>
                                <h3>
                                    {{
                                        detailsParcel.order.tracking_number ||
                                        detailsParcel.order.order_number
                                    }}
                                </h3>
                                <p class="details-subtitle">
                                    {{ detailsParcel.order.buyer_name }}
                                    <span class="details-subtitle-dot"
                                        >·</span
                                    >
                                    {{
                                        detailsParcel.phase === 'pickup'
                                            ? 'Pick up from seller'
                                            : 'Deliver to buyer'
                                    }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="modal-close"
                                aria-label="Close"
                                @click="closeDetails"
                            >
                                <NavIcon name="close" :size="15" />
                            </button>
                        </div>

                        <div class="modal-tabs" role="tablist">
                            <button
                                type="button"
                                role="tab"
                                class="modal-tab-button"
                                :class="{ active: detailsTab === 'product' }"
                                :aria-selected="detailsTab === 'product'"
                                @click="detailsTab = 'product'"
                            >
                                <NavIcon name="parcels" :size="14" />
                                Product
                            </button>
                            <button
                                type="button"
                                role="tab"
                                class="modal-tab-button"
                                :class="{ active: detailsTab === 'seller' }"
                                :aria-selected="detailsTab === 'seller'"
                                @click="detailsTab = 'seller'"
                            >
                                <NavIcon name="store" :size="14" />
                                Seller
                            </button>
                            <button
                                type="button"
                                role="tab"
                                class="modal-tab-button"
                                :class="{ active: detailsTab === 'status' }"
                                :aria-selected="detailsTab === 'status'"
                                @click="detailsTab = 'status'"
                            >
                                <NavIcon name="clock" :size="14" />
                                Status
                            </button>
                        </div>

                        <div class="details-body drawer-body">
                            <p v-if="detailsError" class="callout-red">
                                {{ detailsError }}
                            </p>

                            <div
                                v-else-if="detailsLoading"
                                class="details-skeleton"
                            >
                                <div
                                    v-for="n in 3"
                                    :key="n"
                                    class="details-skeleton-row"
                                >
                                    <span
                                        class="skeleton details-skeleton-thumb"
                                    ></span>
                                    <span
                                        class="skeleton details-skeleton-line"
                                    ></span>
                                </div>
                            </div>

                            <template v-else-if="detailsData">
                                <!-- ---- Product tab ---- -->
                                <div
                                    v-if="detailsTab === 'product'"
                                    class="details-panel"
                                >
                                    <dl
                                        class="details-info-list details-info-list-inline"
                                    >
                                        <div class="details-info-row">
                                            <dt>Payment type</dt>
                                            <dd>
                                                {{
                                                    detailsData.payment_method ||
                                                    'Not on file'
                                                }}
                                            </dd>
                                        </div>
                                    </dl>

                                    <p
                                        v-if="detailsData.items.length === 0"
                                        class="empty-state"
                                    >
                                        <NavIcon name="parcels" :size="26" />
                                        <span>No line items on this order.</span>
                                    </p>
                                    <ul v-else class="details-item-list">
                                        <li
                                            v-for="(
                                                item, idx
                                            ) in detailsData.items"
                                            :key="idx"
                                            class="details-item-row"
                                        >
                                            <span class="details-item-thumb">
                                                <img
                                                    v-if="item.image"
                                                    :src="item.image"
                                                    :alt="item.name"
                                                    loading="lazy"
                                                    width="96"
                                                    height="96"
                                                />
                                                <NavIcon
                                                    v-else
                                                    name="parcels"
                                                    :size="34"
                                                />
                                            </span>
                                            <div class="details-item-copy">
                                                <strong>{{ item.name }}</strong>
                                                <span
                                                    v-if="item.variant"
                                                    class="details-item-variant"
                                                    >{{ item.variant }}</span
                                                >
                                                <dl class="details-info-list">
                                                    <div
                                                        class="details-info-row"
                                                    >
                                                        <dt>
                                                            Quantity ordered
                                                        </dt>
                                                        <dd>
                                                            {{ item.quantity }}
                                                        </dd>
                                                    </div>
                                                    <div
                                                        class="details-info-row"
                                                    >
                                                        <dt>Package weight</dt>
                                                        <dd>
                                                            {{
                                                                formatWeight(
                                                                    item.weight,
                                                                )
                                                            }}
                                                        </dd>
                                                    </div>
                                                    <div
                                                        class="details-info-row"
                                                    >
                                                        <dt>Package size</dt>
                                                        <dd>
                                                            {{
                                                                formatDimensions(
                                                                    item.dimensions,
                                                                )
                                                            }}
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </div>
                                        </li>
                                    </ul>
                                </div>

                                <!-- ---- Seller tab ---- -->
                                <div
                                    v-else-if="detailsTab === 'seller'"
                                    class="details-panel details-seller-panel"
                                >
                                    <span class="details-seller-icon">
                                        <NavIcon name="store" :size="34" />
                                    </span>
                                    <div class="details-seller-copy">
                                        <div>
                                            <strong>{{
                                                detailsData.seller
                                                    .shop_name ||
                                                detailsData.seller.name ||
                                                'Seller'
                                            }}</strong>
                                            <span
                                                v-if="
                                                    detailsData.seller
                                                        .shop_name &&
                                                    detailsData.seller.name
                                                "
                                                class="parcel-meta"
                                                >{{
                                                    detailsData.seller.name
                                                }}</span
                                            >
                                        </div>
                                        <dl class="details-info-list">
                                            <div
                                                v-if="
                                                    detailsData.seller
                                                        .line_of_business
                                                "
                                                class="details-info-row"
                                            >
                                                <dt>Line of business</dt>
                                                <dd>
                                                    {{
                                                        detailsData.seller
                                                            .line_of_business
                                                    }}
                                                </dd>
                                            </div>
                                            <div
                                                v-if="
                                                    detailsData.seller
                                                        .contact_no
                                                "
                                                class="details-info-row"
                                            >
                                                <dt>Contact</dt>
                                                <dd>
                                                    {{
                                                        detailsData.seller
                                                            .contact_no
                                                    }}
                                                </dd>
                                            </div>
                                            <div
                                                v-if="detailsData.seller.email"
                                                class="details-info-row"
                                            >
                                                <dt>Email</dt>
                                                <dd>
                                                    {{
                                                        detailsData.seller
                                                            .email
                                                    }}
                                                </dd>
                                            </div>
                                            <div class="details-info-row">
                                                <dt>Pickup address</dt>
                                                <dd>
                                                    {{
                                                        detailsData.seller
                                                            .address ||
                                                        'Address incomplete'
                                                    }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>

                                <!-- ---- Status tab ---- -->
                                <div v-else class="details-panel">
                                    <div
                                        v-if="currentStatusNote"
                                        class="details-current-status"
                                    >
                                        <NavIcon name="truck" :size="16" />
                                        <span>{{ currentStatusNote }}</span>
                                        <button
                                            v-if="
                                                detailsParcel.status ===
                                                'transfer_pending'
                                            "
                                            type="button"
                                            class="btn-sm-outline"
                                            @click="
                                                cancelRequest(detailsParcel);
                                                closeDetails();
                                            "
                                        >
                                            Cancel request
                                        </button>
                                    </div>

                                    <p
                                        v-if="
                                            detailsData.status_history
                                                .length === 0
                                        "
                                        class="empty-state"
                                    >
                                        <NavIcon name="clock" :size="26" />
                                        <span
                                            >No status changes recorded
                                            yet.</span
                                        >
                                    </p>
                                    <ul v-else class="details-history-list">
                                        <li
                                            v-for="(
                                                entry, idx
                                            ) in detailsData.status_history"
                                            :key="idx"
                                            class="details-history-row"
                                            :class="{
                                                'is-current':
                                                    idx ===
                                                    detailsData.status_history
                                                        .length -
                                                        1,
                                            }"
                                        >
                                            <span class="details-history-rail">
                                                <span
                                                    class="details-history-dot"
                                                ></span>
                                            </span>
                                            <div class="details-history-copy">
                                                <strong>{{
                                                    entry.status
                                                }}</strong>
                                                <span class="parcel-meta">{{
                                                    formatDate(
                                                        entry.created_at,
                                                    )
                                                }}</span>
                                                <span
                                                    v-if="entry.note"
                                                    class="parcel-meta"
                                                    >{{ entry.note }}</span
                                                >
                                                <span
                                                    v-if="entry.changed_by"
                                                    class="details-history-by"
                                                    >{{
                                                        entry.changed_by
                                                    }}</span
                                                >
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </template>
                        </div>

                        <div class="modal-actions">
                            <button
                                v-if="isParcelActionable(detailsParcel)"
                                type="button"
                                class="btn-primary"
                                @click="
                                    openAssignment(detailsParcel);
                                    closeDetails();
                                "
                            >
                                Manage this parcel
                            </button>
                            <button
                                type="button"
                                class="btn-outline"
                                @click="closeDetails"
                            >
                                Close
                            </button>
                        </div>
                    </aside>
                </div>
            </Transition>

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
                            This parcel is awaiting an inventory scan. Scan its
                            QR code in the Logistics mobile app to move it into
                            inventory before it can be delivered or transferred.
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
                                cover — hand it to a company that operates there
                                directly.
                            </span>
                        </p>
                        <div class="area-form-grid">
                            <label class="form-field full-field">
                                <span>
                                    Transfer to company
                                    <template
                                        v-if="
                                            transferMatchedBy ===
                                                'municipality' &&
                                            transferMunicipality
                                        "
                                    >
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
                        of these parcels. Accepting doesn't move the parcel yet
                        — their courier still has to physically deliver it to
                        your hub. Once they do, it'll appear in your "Awaiting
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
import { computed, onActivated, onMounted, reactive, ref, watch } from 'vue';
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
    lastSyncedAt,
    pendingTransferCount,
    loadParcelAssignments,
    loadBarangayAssignments,
    loadTransferRequests,
    assignParcel,
    handoffParcel,
    assignTransferCourier,
    fetchTransferOptions,
    fetchParcelDetails,
    requestParcelTransfer,
    respondToTransferRequest,
    isParcelActionable,
} = useLogistics();
const { notify, notifyError, formatDate, formatRelative, personName } =
    useLogisticsUi();

const PAGE_SIZE = 5;

const loading = ref(true); // first-load skeleton gate; never re-armed for refreshes
const refreshing = ref(false);
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
const showExceptionsOnly = ref(false);

const activeAssignments = computed(() =>
    barangayAssignments.value.filter((a) => a.is_active),
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

// One entry per stage, each carrying the icon/tone the badge, the stage
// rail tile and (via statusClass/statusIcon below) the table row all
// share — a single source of truth for "what does this stage look and
// read like", instead of three places that used to drift.
const STAGE_META = {
    all: { label: 'All parcels', icon: 'parcels', tone: 'brand' },
    toPickUp: { label: 'To pick up', icon: 'inbox', tone: 'warning' },
    awaitingInventory: {
        label: 'Awaiting inventory',
        icon: 'scan',
        tone: 'slate',
    },
    toDeliver: { label: 'To be delivered', icon: 'truck', tone: 'stage' },
    toTransfer: { label: 'To transfer', icon: 'couriers', tone: 'indigo' },
    transferred: { label: 'Transferred', icon: 'check', tone: 'success' },
};

const STAGE_BADGE_CLASS = {
    brand: 'badge-teal',
    warning: 'badge-amber',
    slate: 'badge-slate',
    stage: 'badge-stage',
    indigo: 'badge-indigo',
    success: 'badge-success',
};

const stageRail = computed(() =>
    Object.keys(STAGE_META).map((key) => ({
        key,
        ...STAGE_META[key],
        count:
            key === 'all' ? parcelStats.value.total : parcelStats.value[key],
    })),
);

// Parcels that need a specific vehicle (car / van / truck) to move — easy
// to miss in a fast-scrolling queue, so they get their own cross-stage
// filter rather than only a note buried in the routing modal.
function isException(parcel) {
    return Boolean(parcel.required_vehicle_type);
}

const exceptionCount = computed(
    () => parcelAssignments.value.filter(isException).length,
);

function toggleExceptionsOnly() {
    showExceptionsOnly.value = !showExceptionsOnly.value;
}

const filtered = computed(() => {
    const term = search.value.toLowerCase();

    return parcelAssignments.value.filter((parcel) => {
        if (stage.value !== 'all' && stageOf(parcel) !== stage.value) {
            return false;
        }

        if (showExceptionsOnly.value && !isException(parcel)) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [
            parcel.order?.tracking_number,
            parcel.order?.order_number,
            parcel.order?.recipient_name,
            parcel.order?.buyer_name,
            parcel.order?.address,
            parcel.barangay_assignment?.barangay,
            parcel.barangay_assignment?.municipality_name,
            parcel.rider ? personName(parcel.rider) : '',
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
    () =>
        stage.value !== 'all' ||
        search.value.length > 0 ||
        showExceptionsOnly.value,
);

const emptyTitle = computed(() => {
    if (search.value) {
        return 'No parcels match that search';
    }

    if (showExceptionsOnly.value) {
        return 'No exceptions right now';
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
        return 'Try a different tracking number, recipient, address or courier.';
    }

    if (showExceptionsOnly.value) {
        return 'Parcels that require a specific vehicle will appear here.';
    }

    return stage.value === 'all'
        ? 'Scan the first In Transit parcel to begin sorting.'
        : 'Parcels at this stage will appear here.';
});

// Back to page 1 whenever the result set changes, so switching to a stage
// with 3 parcels doesn't land on an empty page 4.
watch([stage, search, showExceptionsOnly], () => {
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
    showExceptionsOnly.value = false;
}

// ---- Details drawer (Product / Seller / Status tabs) ----
// Fetched lazily, one parcel at a time, only once the drawer is actually
// opened — the queue list itself never carries this weight (see
// ParcelAssignmentController::details on the backend).
const detailsParcel = ref(null);
const detailsData = ref(null);
const detailsLoading = ref(false);
const detailsError = ref('');
const detailsTab = ref('product');

async function openDetails(parcel) {
    detailsParcel.value = parcel;
    detailsTab.value = 'product';
    detailsData.value = null;
    detailsError.value = '';
    detailsLoading.value = true;

    try {
        detailsData.value = await fetchParcelDetails(parcel.id);
    } catch (error) {
        detailsError.value = error.message;
    } finally {
        detailsLoading.value = false;
    }
}

function closeDetails() {
    detailsParcel.value = null;
    detailsData.value = null;
    detailsError.value = '';
}

// The per-row status note (transfer pending/in transit/transferred/handed
// off) used to live next to the Action button — same branching as
// stageOf()/isParcelActionable(), just phrased as a sentence instead of a
// stage bucket. It now lives here, above the Status tab's history list.
const currentStatusNote = computed(() => {
    const parcel = detailsParcel.value;

    if (!parcel) {
        return '';
    }

    if (parcel.status === 'transfer_pending') {
        return `Requested ${formatRelative(parcel.transfer_request?.requested_at)}`;
    }

    if (parcel.status === 'ready_to_transfer') {
        return `With courier, headed to ${parcel.transfer_to_company?.company_name || 'the other company'}`;
    }

    if (parcel.status === 'transferred') {
        return `Transferred ${formatDate(parcel.transferred_at)}`;
    }

    if (!isParcelActionable(parcel)) {
        return `Handed off ${formatDate(parcel.handed_off_at)}`;
    }

    return '';
});

function formatCurrency(value) {
    return value === null || value === undefined
        ? '—'
        : `₱${Number(value).toFixed(2)}`;
}

function formatWeight(weight) {
    return weight === null || weight === undefined
        ? 'Not specified'
        : `${weight} kg`;
}

function formatDimensions(dimensions) {
    if (!dimensions || typeof dimensions !== 'object') {
        return 'Not specified';
    }

    const { length, width, height, unit } = dimensions;

    if (!length && !width && !height) {
        return 'Not specified';
    }

    return `${length || '—'}×${width || '—'}×${height || '—'} ${unit || 'cm'}`;
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

    return STAGE_META[stageOf(parcel)].label;
}

function statusClass(parcel) {
    return STAGE_BADGE_CLASS[STAGE_META[stageOf(parcel)].tone];
}

function statusIcon(parcel) {
    return STAGE_META[stageOf(parcel)].icon;
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
// company instead, so this stays true for either case too. Also true
// while a rider is only ParcelInventoryController::scan()'s auto-match
// (assigned_by not set yet) — staff haven't actually confirmed that
// handoff, so this is still their delivery decision to make, not a
// stale pickup-courier form. Skipped for a transfer receipt once it has
// a rider (is_transfer_receipt): the receiving company was chosen for
// covering this area, so its own regional/provincial pool is an
// ordinary way to deliver it, not something to keep re-offering a
// transfer for. Mirrors Api\Logistics\
// ParcelAssignmentController::awaitingDispatchDecision, plus the
// assign()/$isDeliveryDispatch confirmation check.
const awaitingDispatchDecision = computed(
    () =>
        selectedParcel.value?.status === 'handed_off' &&
        (!selectedParcel.value?.rider ||
            !selectedParcel.value?.assigned_by ||
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

    assignmentForm.barangay_assignment_id =
        parcel.barangay_assignment?.id || '';
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
        const isTransferHandoff =
            selectedParcel.value?.status === 'transfer_assigned';
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
                ? 'Transfer accepted. It\'ll appear in your "Awaiting inventory" queue once their courier delivers it.'
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
});

// Re-entering the tab re-checks staleness (free while the cache is fresh).
onActivated(() => {
    load();
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

/* ===================== Stage rail ===================== */
.stage-rail {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    overflow-x: auto;
    padding-bottom: 2px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.stage-rail::-webkit-scrollbar {
    display: none;
}
.stage-tile {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1 0 150px;
    min-width: 150px;
    padding: 12px 14px;
    background: var(--lg-surface);
    border: 1.5px solid var(--lg-border);
    border-radius: var(--lg-radius-md);
    box-shadow: var(--lg-shadow-sm);
    cursor: pointer;
    text-align: left;
    font: inherit;
    color: inherit;
    transition:
        transform 0.15s var(--lg-ease),
        border-color 0.15s var(--lg-ease),
        box-shadow 0.15s var(--lg-ease);
}
.stage-tile:hover {
    transform: translateY(-1px);
    box-shadow: var(--lg-shadow-md);
}
.stage-tile.is-active {
    border-color: var(--stage-color, var(--lg-primary));
    box-shadow: 0 0 0 1px var(--stage-color, var(--lg-primary));
}
.stage-tile-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: var(--stage-bg, var(--lg-primary-light));
    color: var(--stage-color, var(--lg-primary));
}
.stage-tile-copy {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}
.stage-tile-value {
    font-family: var(--lg-font-display);
    font-size: 20px;
    font-weight: 800;
    line-height: 1.1;
    color: var(--lg-ink);
}
.stage-tile-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--lg-slate-600);
    white-space: nowrap;
}
.stage-tile.tone-brand {
    --stage-color: var(--lg-primary);
    --stage-bg: var(--lg-primary-light);
}
.stage-tile.tone-warning {
    --stage-color: var(--lg-warning);
    --stage-bg: var(--lg-warning-bg);
}
.stage-tile.tone-slate {
    --stage-color: var(--lg-slate-600);
    --stage-bg: #f1f5f9;
}
.stage-tile.tone-stage {
    --stage-color: var(--lg-stage);
    --stage-bg: var(--lg-stage-bg);
}
.stage-tile.tone-indigo {
    --stage-color: var(--lg-info);
    --stage-bg: var(--lg-info-bg);
}
.stage-tile.tone-success {
    --stage-color: var(--lg-success);
    --stage-bg: var(--lg-success-bg);
}

/* ===================== Toolbar / exceptions / bulk bar ===================== */
.sort-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 14px 16px 10px;
}
.sort-search {
    flex: 1;
    min-width: 220px;
}
.exception-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 13px;
    border-radius: 999px;
    border: 1.5px solid var(--lg-border);
    background: var(--lg-surface);
    color: var(--lg-slate-600);
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    transition:
        border-color 0.15s var(--lg-ease),
        background 0.15s var(--lg-ease),
        color 0.15s var(--lg-ease);
}
.exception-toggle:hover {
    border-color: var(--lg-warning);
    color: var(--lg-warning);
}
.exception-toggle.is-active {
    background: var(--lg-warning-bg);
    border-color: var(--lg-warning-border);
    color: var(--lg-warning);
}
.exception-toggle-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: currentColor;
    font-size: 10px;
    font-weight: 800;
}
.exception-toggle.is-active .exception-toggle-count {
    color: var(--lg-warning-bg);
}
.exception-toggle:not(.is-active) .exception-toggle-count {
    color: var(--lg-surface);
}

/* ===================== Table ===================== */
.parcel-id-cell {
    display: flex;
    align-items: center;
    gap: 6px;
}
.exception-flag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 999px;
    background: var(--lg-warning-bg);
    color: var(--lg-warning);
    flex-shrink: 0;
}
.parcel-meta-dot {
    margin: 0 4px;
    opacity: 0.6;
}
.status-badge {
    white-space: nowrap;
}
.row-actions {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}
.row-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border: 1px solid var(--lg-border);
    border-radius: var(--lg-radius-sm);
    background: var(--lg-surface);
    color: var(--lg-slate-600);
    cursor: pointer;
    transition:
        background 0.15s var(--lg-ease),
        border-color 0.15s var(--lg-ease),
        color 0.15s var(--lg-ease);
}
.row-icon-btn:hover {
    background: var(--lg-bg);
    color: var(--lg-ink);
}
.row-icon-btn.is-primary {
    background: var(--lg-primary);
    border-color: var(--lg-primary);
    color: #fff;
}
.row-icon-btn.is-primary:hover {
    background: var(--lg-primary-dark);
    border-color: var(--lg-primary-dark);
}

/* ---- Header summary tiles ---- */
.row-price {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--lg-success, #16a34a);
}

/* ===================== Details drawer ===================== */
.drawer-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.5);
    backdrop-filter: blur(1px);
    z-index: 200;
    display: flex;
    justify-content: flex-end;
    align-items: flex-start;
    padding: 20px;
}
/* Sizes to its content instead of stretching to the full viewport — a
   one-item order used to leave a "Close" button stranded 400px below the
   last line, which read as a broken/empty page rather than a finished
   one. Still caps at the viewport (minus the overlay's own padding) and
   scrolls internally once a parcel's history/items actually run long. */
.drawer-panel {
    width: 460px;
    max-width: 100%;
    max-height: calc(100vh - 40px);
    background: var(--lg-surface);
    border-radius: var(--lg-radius-lg);
    box-shadow: var(--lg-shadow-lg);
    padding: 22px 22px 18px;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}
.drawer-header {
    flex-shrink: 0;
}
.drawer-body {
    flex: 0 1 auto;
    max-height: none;
}
.drawer-enter-active .drawer-panel,
.drawer-leave-active .drawer-panel {
    transition: transform 0.22s var(--lg-ease);
}
.drawer-enter-active,
.drawer-leave-active {
    transition: opacity 0.22s var(--lg-ease);
}
.drawer-enter-from,
.drawer-leave-to {
    opacity: 0;
}
.drawer-enter-from .drawer-panel,
.drawer-leave-to .drawer-panel {
    transform: translateX(100%);
}
.details-subtitle {
    margin: 3px 0 0;
    font-size: 12.5px;
    color: var(--lg-slate-600, #475569);
}
.details-subtitle-dot {
    margin: 0 4px;
    opacity: 0.6;
}
.details-body {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.details-body::-webkit-scrollbar {
    display: none;
}

.details-skeleton {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.details-skeleton-row {
    display: flex;
    align-items: center;
    gap: 12px;
}
.details-skeleton-thumb {
    flex: none;
    width: 48px;
    height: 48px;
    border-radius: 8px;
}
.details-skeleton-line {
    flex: 1;
    height: 14px;
    border-radius: 4px;
}

.details-panel {
    display: flex;
    flex-direction: column;
}
.details-item-list,
.details-history-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.details-item-row {
    display: flex;
    align-items: flex-start;
    gap: 18px;
    padding: 16px 4px;
}
.details-item-row + .details-item-row {
    border-top: 1px solid var(--lg-border, #e2e8f0);
}
.details-item-thumb {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 96px;
    height: 96px;
    border-radius: 12px;
    background: var(--lg-bg, #f1f5f9);
    color: var(--lg-slate-600, #475569);
    overflow: hidden;
}
.details-item-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.details-item-copy {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    padding-top: 2px;
}
.details-item-copy > strong {
    font-size: 15px;
}
.details-item-variant {
    font-size: 12px;
    color: var(--lg-slate-600, #475569);
}
.details-item-copy .details-info-list {
    gap: 6px;
}

.details-seller-panel {
    flex-direction: row;
    align-items: flex-start;
    gap: 18px;
}
.details-seller-icon {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 96px;
    height: 96px;
    border-radius: 12px;
    background: var(--lg-primary-100, #ccfbf1);
    color: var(--lg-primary-dark, #0f766e);
}
.details-seller-copy {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
    padding-top: 2px;
}
.details-seller-copy > div:first-child {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.details-seller-copy > div:first-child strong {
    font-size: 15px;
}
.details-info-list {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.details-info-list-inline {
    flex-direction: row;
    align-items: baseline;
    gap: 6px;
    padding-bottom: 12px;
    margin-bottom: 12px;
    border-bottom: 1px solid var(--lg-border, #e2e8f0);
}
.details-info-list-inline .details-info-row {
    flex-direction: row;
    gap: 6px;
}
.details-info-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.details-info-row dt {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--lg-slate-600, #475569);
}
.details-info-row dd {
    margin: 0;
    font-size: 13.5px;
    color: var(--lg-ink, #0f172a);
}

.details-current-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    margin-bottom: 16px;
    border-radius: 8px;
    background: var(--lg-bg, #f1f5f9);
    color: var(--lg-slate-600, #475569);
    font-size: 12.5px;
}
.details-current-status span {
    flex: 1;
}

.details-history-row {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding-bottom: 16px;
}
.details-history-row:last-child {
    padding-bottom: 0;
}
.details-history-rail {
    flex: none;
    position: relative;
    display: flex;
    justify-content: center;
    width: 8px;
    align-self: stretch;
}
.details-history-row:not(:last-child) .details-history-rail::before {
    content: '';
    position: absolute;
    top: 12px;
    bottom: -4px;
    width: 2px;
    background: var(--lg-border, #e2e8f0);
}
.details-history-dot {
    flex: none;
    width: 8px;
    height: 8px;
    margin-top: 5px;
    border-radius: 999px;
    background: var(--lg-border, #e2e8f0);
    box-shadow: 0 0 0 3px var(--lg-surface, #fff);
}
.details-history-row.is-current .details-history-dot {
    background: var(--lg-primary, #0d9488);
}
.details-history-copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.details-history-copy strong {
    text-transform: capitalize;
}
.details-history-by {
    font-size: 11px;
    color: var(--lg-slate-600, #475569);
}

/* ===================== Transfer requests inbox ===================== */
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

/* ===================== Responsive ===================== */
@media (max-width: 900px) {
    .stage-tile {
        flex: 1 0 130px;
        min-width: 130px;
    }
    .drawer-panel {
        width: 100%;
    }
}

@media (max-width: 760px) {
    .sort-toolbar {
        padding: 12px 12px 8px;
    }
    /* Rows become stacked cards instead of a horizontally-scrolled
       table — each cell's own label (data-label) stands in for the
       header row, which is visually hidden here. */
    .sort-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
    }
    .sort-table,
    .sort-table tbody,
    .sort-table tr {
        display: block;
        width: 100%;
        min-width: 0;
    }
    .sort-table tr {
        margin-bottom: 12px;
        border: 1.5px solid var(--lg-border);
        border-radius: var(--lg-radius-md);
        overflow: hidden;
    }
    .sort-table td {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 14px;
        border: none;
        border-radius: 0 !important;
        border-bottom: 1px solid var(--lg-border);
        text-align: right;
    }
    .sort-table tr td:last-child {
        border-bottom: none;
    }
    .sort-table td::before {
        content: attr(data-label);
        flex-shrink: 0;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--lg-slate);
        text-align: left;
    }
    .sort-table td[data-label='Parcel'],
    .sort-table td[data-label='Destination'] {
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
    }
    .sort-table .row-actions {
        justify-content: flex-end;
        width: 100%;
    }
}
</style>
