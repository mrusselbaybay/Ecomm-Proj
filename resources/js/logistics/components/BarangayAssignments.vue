<!-- resources/js/logistics/components/BarangayAssignments.vue
     Barangay-level courier coverage: one row per barangay, with at most one
     assigned rider. Parcels auto-route straight to that rider; a barangay
     with nobody assigned falls back to the company-wide pool (see
     App\Services\ParcelAutoAssignService). Replaces the old province +
     several-municipalities "delivery area" with a rider roster. -->
<template>
    <div class="logistics-page">
        <!-- Toasts render once, in LogisticsLayout, for the whole portal. -->
        <header class="page-header">
            <div>
                <h2 class="page-title">Barangay assignments</h2>
                <p class="page-subtitle">
                    Appoint one courier per barangay. Parcels route straight
                    to them; unassigned barangays fall back to the
                    company-wide rotation.
                </p>
            </div>
            <div class="page-header-actions">
                <button
                    type="button"
                    class="btn-outline btn-icon"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <NavIcon name="refresh" :size="15" />
                    Refresh
                </button>
                <button class="btn-outline" @click="openBulkModal">
                    Auto-create delivery areas
                </button>
                <button class="btn-primary" @click="openAssignmentModal()">
                    Add delivery area
                </button>
            </div>
        </header>

        <div class="area-summary-grid">
            <div class="stat-card accent-total">
                <p class="field-label">Barangays covered</p>
                <p class="stat-total text-2xl font-bold">
                    {{ barangayAssignments.length }}
                </p>
            </div>
            <div class="stat-card accent-active">
                <p class="field-label">Staffed</p>
                <p class="stat-active text-2xl font-bold">
                    {{ staffedCount }}
                </p>
            </div>
            <div class="stat-card accent-pending">
                <p class="field-label">Unstaffed</p>
                <p class="stat-pending text-2xl font-bold">
                    {{ unstaffedCount }}
                </p>
            </div>
        </div>

        <section class="management-section">
            <div class="section-heading">
                <div>
                    <h3>Barangay coverage</h3>
                    <p>Used to route scanned parcels straight to a rider.</p>
                </div>
                <div class="coverage-filter-bar">
                    <div class="search-input rounded">
                        <svg
                            class="icon"
                            viewBox="0 0 24 24"
                            fill="none"
                            aria-hidden="true"
                        >
                            <circle
                                cx="11"
                                cy="11"
                                r="7"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="m20 20-3.5-3.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>
                        <label for="coverage-search" class="sr-only"
                            >Search barangays</label
                        >
                        <input
                            id="coverage-search"
                            v-model.trim="barangaySearch"
                            type="text"
                            placeholder="Search barangays…"
                        />
                    </div>
                    <div class="filter-toggle-group">
                        <button
                            type="button"
                            class="filter-toggle-btn"
                            :class="{ 'is-active': statusFilter === 'staffed' }"
                            @click="toggleStatusFilter('staffed')"
                        >
                            Staffed
                        </button>
                        <button
                            type="button"
                            class="filter-toggle-btn"
                            :class="{
                                'is-active': statusFilter === 'unstaffed',
                            }"
                            @click="toggleStatusFilter('unstaffed')"
                        >
                            Unstaffed
                        </button>
                    </div>
                </div>
            </div>
            <div v-if="loading" class="card empty-state">
                <div class="loading-spinner"></div>
            </div>
            <div
                v-else-if="barangayAssignments.length === 0"
                class="card empty-state"
            >
                <div class="empty-box">B</div>
                <strong>No barangays assigned yet</strong>
                <p>
                    Add the barangays your riders cover so parcels route to
                    them automatically.
                </p>
                <button
                    class="btn-primary empty-action"
                    @click="openAssignmentModal()"
                >
                    Add first barangay
                </button>
            </div>
            <div
                v-else-if="filteredAssignments.length === 0"
                class="card empty-state"
            >
                <div class="empty-box">B</div>
                <strong>No matching barangays</strong>
                <p>
                    Nothing matches your search{{
                        statusFilter !== 'all' ? ' and filter' : ''
                    }}.
                </p>
                <button
                    class="btn-outline empty-action"
                    @click="clearCoverageFilters"
                >
                    Clear filters
                </button>
            </div>
            <div v-else class="area-card-grid">
                <article
                    v-for="assignment in paginatedAssignments"
                    :key="assignment.id"
                    class="card area-card"
                >
                    <div class="area-card-head">
                        <div>
                            <span
                                class="badge"
                                :class="
                                    assignment.is_active
                                        ? 'badge-teal'
                                        : 'badge-slate'
                                "
                                >{{
                                    assignment.is_active
                                        ? 'Active'
                                        : 'Inactive'
                                }}</span
                            >
                            <h4>{{ assignment.barangay }}</h4>
                        </div>
                        <button
                            class="area-menu-button"
                            title="Edit barangay assignment"
                            @click="openAssignmentModal(assignment)"
                        >
                            Edit
                        </button>
                    </div>
                    <p class="area-address">{{ formatCoverage(assignment) }}</p>
                    <p class="area-delivered-count">
                        <NavIcon name="parcels" :size="13" />
                        Packages delivered:
                        <strong>{{ assignment.delivered_count ?? 0 }}</strong>
                    </p>
                    <div class="assigned-rider">
                        <div class="avatar">
                            {{ assignment.rider ? initials(assignment.rider) : 0 }}
                        </div>
                        <div v-if="assignment.rider">
                            <strong>{{ riderName(assignment.rider) }}</strong
                            ><span>{{
                                riderQuotaLabel(assignment.rider)
                            }}</span>
                        </div>
                        <div v-else>
                            <strong>No driver assigned</strong
                            ><span
                                >Falls back to the company-wide
                                rotation</span
                            >
                        </div>
                    </div>
                </article>
            </div>
            <div
                v-if="filteredAssignments.length > COVERAGE_PAGE_SIZE"
                class="driver-pagination"
            >
                <button
                    type="button"
                    class="btn-sm-outline"
                    :disabled="clampedCoveragePage <= 1"
                    @click="changeCoveragePage(clampedCoveragePage - 1)"
                >
                    Prev
                </button>
                <span
                    >Page {{ clampedCoveragePage }} of
                    {{ coverageTotalPages }}</span
                >
                <button
                    type="button"
                    class="btn-sm-outline"
                    :disabled="clampedCoveragePage >= coverageTotalPages"
                    @click="changeCoveragePage(clampedCoveragePage + 1)"
                >
                    Next
                </button>
            </div>
        </section>

        <Teleport to=".logistics-shell">
            <div
                v-if="showAssignmentModal"
                class="modal-overlay"
                @click.self="closeAssignmentModal"
            >
                <div class="area-modal-row">
                    <form
                        class="modal-panel area-form-modal"
                        @submit.prevent="submitAssignment"
                    >
                        <div class="modal-header">
                            <div>
                                <p class="eyebrow">Routing rule</p>
                                <h3>
                                    {{
                                        editingAssignmentId
                                            ? 'Edit barangay assignment'
                                            : 'New barangay assignment'
                                    }}
                                </h3>
                            </div>
                            <button
                                type="button"
                                class="modal-close"
                                @click="closeAssignmentModal"
                            >
                                &times;
                            </button>
                        </div>

                        <div class="area-modal-body">
                            <p class="modal-desc">
                                A scanned parcel bound for this barangay
                                routes straight to the assigned driver.
                            </p>
                            <div class="area-form-grid">
                                <label class="form-field"
                                    ><span
                                        >Province
                                        <span
                                            v-if="loadingCompanyAddress"
                                            class="form-field-autofill-hint"
                                            >Auto-filling from your company
                                            address…<span
                                                class="loading-spinner-sm"
                                            ></span></span></span
                                    ><SearchableSelect
                                        v-model="form.province_code"
                                        :options="provinceSelectOptions"
                                        :disabled="loadingProvinces"
                                        :loading="loadingProvinces"
                                        loading-text="Loading provinces…"
                                        placeholder="Type to search a province…"
                                        empty-text="No provinces found"
                                        @select="onProvinceChange"
                                    /></label
                                >
                                <label class="form-field"
                                    ><span
                                        >Municipality / city
                                        <span
                                            v-if="loadingCompanyAddress"
                                            class="form-field-autofill-hint"
                                            ><span
                                                class="loading-spinner-sm"
                                            ></span></span></span
                                    ><SearchableSelect
                                        v-model="form.municipality_code"
                                        :options="municipalitySelectOptions"
                                        :disabled="
                                            !form.province_code ||
                                            loadingMunicipalities
                                        "
                                        :loading="loadingMunicipalities"
                                        loading-text="Loading municipalities…"
                                        :placeholder="
                                            form.province_code
                                                ? 'Type to search a municipality…'
                                                : 'Select a province first'
                                        "
                                        empty-text="No municipalities found"
                                        @select="onMunicipalityChange"
                                    /></label
                                >
                                <label class="form-field full-field"
                                    ><span>Barangay</span
                                    ><SearchableSelect
                                        v-model="form.barangay"
                                        :options="barangaySelectOptions"
                                        :disabled="
                                            !form.municipality_code ||
                                            loadingBarangays
                                        "
                                        :loading="loadingBarangays"
                                        loading-text="Loading barangays…"
                                        :placeholder="
                                            form.municipality_code
                                                ? 'Type to search a barangay…'
                                                : 'Select a municipality first'
                                        "
                                        empty-text="No barangays found"
                                    /></label
                                >
                                <p
                                    v-if="addressApiError"
                                    class="callout-red full-field"
                                >
                                    {{ addressApiError }}
                                </p>
                                <label class="status-toggle full-field"
                                    ><input
                                        v-model="form.is_active"
                                        type="checkbox"
                                    /><span
                                        ><strong>Assignment is active</strong
                                        ><small
                                            >Active barangays can receive
                                            automatic parcel matches.</small
                                        ></span
                                    ></label
                                >
                            </div>

                            <div class="driver-panel-heading">
                                <h4>Assigned driver</h4>
                                <button
                                    v-if="!assignedRider"
                                    type="button"
                                    class="btn-sm-outline"
                                    @click="openAddDriverPanel"
                                >
                                    + Assign courier
                                </button>
                            </div>
                            <div class="driver-list">
                                <p
                                    v-if="!assignedRider"
                                    class="driver-empty-hint"
                                >
                                    No driver assigned yet. Parcels here fall
                                    back to the company-wide rotation until
                                    one is set.
                                </p>
                                <div v-else class="driver-row">
                                    <div class="avatar">
                                        {{ riderInitials(assignedRider) }}
                                    </div>
                                    <div class="driver-row-info">
                                        <strong>{{
                                            riderName(assignedRider)
                                        }}</strong>
                                        <span class="driver-row-address">{{
                                            assignedRider.address ||
                                            'No address on file'
                                        }}</span>
                                        <span class="rider-quota-badge">{{
                                            riderQuotaLabel(assignedRider)
                                        }}</span>
                                    </div>
                                    <div class="driver-row-actions">
                                        <button
                                            type="button"
                                            class="driver-view-button"
                                            title="View rider details"
                                            @click="openRiderView(assignedRider)"
                                        >
                                            View
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-sm-outline"
                                            :disabled="driverActionBusy"
                                            @click="handleClearDriver"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <p v-if="driverError" class="callout-red">
                                {{ driverError }}
                            </p>
                            <p v-if="formError" class="callout-red">
                                {{ formError }}
                            </p>
                        </div>
                        <div class="modal-actions">
                            <button
                                v-if="editingAssignmentId"
                                type="button"
                                class="btn-danger-outline delete-area-button"
                                @click="removeAssignment"
                            >
                                Delete assignment
                            </button>
                            <button
                                type="button"
                                class="btn-outline"
                                @click="closeAssignmentModal"
                            >
                                {{ editingAssignmentId ? 'Close' : 'Cancel' }}
                            </button>
                            <button class="btn-primary" :disabled="saving">
                                {{ saving ? 'Saving...' : 'Save assignment' }}
                            </button>
                        </div>
                    </form>

                    <div
                        v-if="showAddDriverPanel"
                        class="modal-panel add-driver-panel"
                    >
                        <div class="modal-header">
                            <div>
                                <p class="eyebrow">Assign courier</p>
                                <h3>Accepted riders</h3>
                            </div>
                            <button
                                type="button"
                                class="modal-close"
                                @click="closeAddDriverPanel"
                            >
                                &times;
                            </button>
                        </div>

                        <div class="search-input driver-search rounded">
                            <svg
                                class="icon"
                                viewBox="0 0 24 24"
                                fill="none"
                                aria-hidden="true"
                            >
                                <circle
                                    cx="11"
                                    cy="11"
                                    r="7"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                />
                                <path
                                    d="m20 20-3.5-3.5"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />
                            </svg>
                            <label for="available-driver-search" class="sr-only"
                                >Search accepted riders</label
                            >
                            <input
                                id="available-driver-search"
                                v-model.trim="availableSearch"
                                type="text"
                                placeholder="Search riders by name…"
                            />
                        </div>

                        <div class="driver-list courier-card-list">
                            <template v-if="loadingAvailable">
                                <div
                                    v-for="n in 3"
                                    :key="n"
                                    class="courier-card"
                                    aria-hidden="true"
                                >
                                    <div class="courier-card-head">
                                        <div
                                            class="skeleton skeleton-circle"
                                            style="
                                                width: 38px;
                                                height: 38px;
                                                flex-shrink: 0;
                                            "
                                        ></div>
                                        <div class="driver-row-info">
                                            <div
                                                class="skeleton skeleton-text"
                                                style="width: 70%"
                                            ></div>
                                            <div
                                                class="skeleton skeleton-text"
                                                style="width: 45%"
                                            ></div>
                                        </div>
                                    </div>
                                    <div class="courier-field-grid">
                                        <div
                                            v-for="f in 4"
                                            :key="f"
                                            class="skeleton skeleton-text"
                                            style="height: 40px"
                                        ></div>
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <p
                                    v-if="availableRiders.length === 0"
                                    class="driver-empty-hint"
                                >
                                    {{
                                        availableSearch
                                            ? 'No matching riders.'
                                            : 'No accepted riders yet.'
                                    }}
                                </p>
                                <div
                                    v-for="rider in availableRiders"
                                    :key="rider.id"
                                    class="courier-card"
                                >
                                    <div class="courier-card-head">
                                        <div class="avatar">
                                            {{ riderInitials(rider) }}
                                        </div>
                                        <strong class="courier-card-name">{{
                                            riderName(rider)
                                        }}</strong>
                                        <div class="driver-row-actions">
                                            <button
                                                type="button"
                                                class="driver-view-button"
                                                :title="
                                                    expandedRiderId === rider.id
                                                        ? 'Hide rider details'
                                                        : 'View rider details'
                                                "
                                                @click="
                                                    toggleRiderFields(rider.id)
                                                "
                                            >
                                                {{
                                                    expandedRiderId === rider.id
                                                        ? 'Hide'
                                                        : 'View'
                                                }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn-sm-primary"
                                                :disabled="driverActionBusy"
                                                @click="
                                                    handleSetDriver(rider.id)
                                                "
                                            >
                                                Assign
                                            </button>
                                        </div>
                                    </div>
                                    <div
                                        v-if="expandedRiderId === rider.id"
                                        class="courier-field-grid"
                                    >
                                        <div class="courier-field">
                                            <span class="courier-field-label"
                                                >Contact no.</span
                                            >
                                            <span class="courier-field-value">{{
                                                rider.contact_no ||
                                                'Not provided'
                                            }}</span>
                                        </div>
                                        <div class="courier-field">
                                            <span class="courier-field-label"
                                                >Vehicle</span
                                            >
                                            <span class="courier-field-value">{{
                                                rider.vehicle || 'Not provided'
                                            }}</span>
                                        </div>
                                        <div class="courier-field">
                                            <span class="courier-field-label"
                                                >Plate number</span
                                            >
                                            <span class="courier-field-value">{{
                                                rider.plate_number ||
                                                'Not provided'
                                            }}</span>
                                        </div>
                                        <div class="courier-field">
                                            <span class="courier-field-label"
                                                >Parcel quota</span
                                            >
                                            <span class="courier-field-value">{{
                                                riderQuotaLabel(rider)
                                            }}</span>
                                        </div>
                                        <div
                                            class="courier-field courier-field-full"
                                        >
                                            <span class="courier-field-label"
                                                >Address</span
                                            >
                                            <span class="courier-field-value">{{
                                                rider.address ||
                                                'No address on file'
                                            }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div
                            v-if="availableMeta.lastPage > 1"
                            class="driver-pagination"
                        >
                            <button
                                type="button"
                                class="btn-sm-outline"
                                :disabled="
                                    availablePage <= 1 || loadingAvailable
                                "
                                @click="changeAvailablePage(availablePage - 1)"
                            >
                                Prev
                            </button>
                            <span
                                >Page {{ availablePage }} of
                                {{ availableMeta.lastPage }}</span
                            >
                            <button
                                type="button"
                                class="btn-sm-outline"
                                :disabled="
                                    availablePage >= availableMeta.lastPage ||
                                    loadingAvailable
                                "
                                @click="changeAvailablePage(availablePage + 1)"
                            >
                                Next
                            </button>
                        </div>

                        <p v-if="availableError" class="callout-red">
                            {{ availableError }}
                        </p>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to=".logistics-shell">
            <div
                v-if="viewingRider"
                class="modal-overlay rider-view-overlay"
                @click.self="closeRiderView"
            >
                <div class="modal-panel modal-sm rider-view-panel">
                    <div class="modal-header">
                        <p class="eyebrow">Rider profile</p>
                        <button
                            type="button"
                            class="modal-close"
                            @click="closeRiderView"
                        >
                            &times;
                        </button>
                    </div>

                    <div class="rider-view-identity">
                        <div class="avatar avatar-lg">
                            {{ riderInitials(viewingRider) }}
                        </div>
                        <h3 class="rider-view-name">
                            {{ riderName(viewingRider) }}
                        </h3>
                    </div>

                    <dl class="rider-view-details">
                        <div>
                            <dt>Email</dt>
                            <dd>{{ viewingRider.email || 'Not provided' }}</dd>
                        </div>
                        <div>
                            <dt>Contact no.</dt>
                            <dd>
                                {{ viewingRider.contact_no || 'Not provided' }}
                            </dd>
                        </div>
                        <div>
                            <dt>Address</dt>
                            <dd>
                                {{
                                    viewingRider.address || 'No address on file'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt>Parcel quota</dt>
                            <dd>{{ riderQuotaLabel(viewingRider) }}</dd>
                        </div>
                        <div v-if="viewingRider.vehicle">
                            <dt>Vehicle</dt>
                            <dd>
                                {{ viewingRider.vehicle
                                }}<span v-if="viewingRider.plate_number">
                                    ({{ viewingRider.plate_number }})</span
                                >
                            </dd>
                        </div>
                    </dl>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-outline"
                            @click="closeRiderView"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to=".logistics-shell">
            <div
                v-if="showBulkModal"
                class="modal-overlay"
                @click.self="closeBulkModal"
            >
                <div class="modal-panel bulk-create-modal">
                    <div class="modal-header">
                        <div>
                            <p class="eyebrow">Bulk routing rule</p>
                            <h3>Auto-create delivery areas</h3>
                        </div>
                        <button
                            type="button"
                            class="modal-close"
                            @click="closeBulkModal"
                        >
                            &times;
                        </button>
                    </div>

                    <div class="area-form-grid">
                        <label class="form-field"
                            ><span
                                >Province
                                <span
                                    v-if="bulkLoadingCompanyAddress"
                                    class="form-field-autofill-hint"
                                    >Auto-filling from your company
                                    address…<span
                                        class="loading-spinner-sm"
                                    ></span></span></span
                            ><SearchableSelect
                                v-model="bulkProvinceCode"
                                :options="bulkProvinceSelectOptions"
                                :disabled="bulkLoadingProvinces"
                                :loading="bulkLoadingProvinces"
                                loading-text="Loading provinces…"
                                placeholder="Type to search a province…"
                                empty-text="No provinces found"
                                @select="onBulkProvinceSelect"
                            /></label
                        >
                        <label class="form-field"
                            ><span
                                >Municipality / city
                                <span
                                    v-if="bulkLoadingCompanyAddress"
                                    class="form-field-autofill-hint"
                                    ><span
                                        class="loading-spinner-sm"
                                    ></span></span></span
                            ><SearchableSelect
                                v-model="bulkMunicipalityCode"
                                :options="bulkMunicipalitySelectOptions"
                                :disabled="
                                    !bulkProvinceCode ||
                                    bulkLoadingMunicipalities
                                "
                                :loading="bulkLoadingMunicipalities"
                                loading-text="Loading municipalities…"
                                :placeholder="
                                    bulkProvinceCode
                                        ? 'Type to search a municipality…'
                                        : 'Select a province first'
                                "
                                empty-text="No municipalities found"
                                @select="onBulkMunicipalitySelect"
                            /></label
                        >
                    </div>

                    <p class="modal-desc">
                        Creates one unassigned delivery area for every
                        barangay in the municipality/city you pick, instead
                        of adding them one at a time. Barangays already
                        covered by your company are skipped automatically.
                    </p>

                    <p v-if="bulkLoadingBarangays" class="modal-desc">
                        Loading barangays…
                        <span class="loading-spinner-sm"></span>
                    </p>
                    <p
                        v-else-if="bulkMunicipalityCode && !bulkAllAlreadyCovered"
                        class="modal-desc"
                    >
                        <strong>{{ bulkBarangayList.length }}</strong>
                        barangay{{ bulkBarangayList.length === 1 ? '' : 's' }}
                        found in {{ bulkMunicipalityName }}.
                        <template v-if="bulkAlreadyCoveredCount">
                            {{ bulkAlreadyCoveredCount }} already
                            {{ bulkAlreadyCoveredCount === 1 ? 'has' : 'have' }}
                            a delivery area —
                            <strong>{{ bulkNewBarangayCount }}</strong> new
                            {{
                                bulkNewBarangayCount === 1 ? 'one' : 'ones'
                            }}
                            will be created.
                        </template>
                    </p>
                    <p
                        v-else-if="bulkAllAlreadyCovered"
                        class="callout-red"
                    >
                        Every barangay in {{ bulkMunicipalityName }} already
                        has a delivery area for your company. There's
                        nothing new to create here.
                    </p>

                    <p v-if="bulkResult" class="callout-teal">
                        Created {{ bulkResult.created }} new delivery
                        area{{ bulkResult.created === 1 ? '' : 's' }}.
                        <template v-if="bulkResult.skipped">
                            {{ bulkResult.skipped }} already existed and
                            {{ bulkResult.skipped === 1 ? 'was' : 'were' }}
                            skipped.
                        </template>
                    </p>
                    <p v-if="bulkError" class="callout-red">
                        {{ bulkError }}
                    </p>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-outline"
                            @click="closeBulkModal"
                        >
                            Close
                        </button>
                        <button
                            type="button"
                            class="btn-primary"
                            :disabled="
                                !bulkMunicipalityCode ||
                                bulkLoadingBarangays ||
                                bulkCreating ||
                                bulkBarangayList.length === 0 ||
                                bulkAllAlreadyCovered
                            "
                            @click="submitBulkCreate"
                        >
                            {{
                                bulkCreating
                                    ? 'Creating…'
                                    : 'Create all barangays'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { computed, onActivated, onMounted, reactive, ref, watch } from 'vue';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import { usePsgc } from '../composables/usePsgc';
import NavIcon from './NavIcon.vue';
import SearchableSelect from './SearchableSelect.vue';

const {
    supabase,
    companyId,
    resolveCompany,
    barangayAssignments,
    loadBarangayAssignments,
    saveBarangayAssignment,
    bulkCreateBarangayAssignments,
    deleteBarangayAssignment,
    setAssignmentRider,
    loadAvailableRiders,
} = useLogistics();
const { notify, notifyError, askConfirm, personName, initials } =
    useLogisticsUi();
const {
    fetchProvinces: psgcProvinces,
    fetchMunicipalities: psgcMunicipalities,
    fetchBarangays: psgcBarangays,
} = usePsgc();
const loading = ref(true); // first-load skeleton gate; never re-armed for refreshes
const refreshing = ref(false);
const saving = ref(false);
const showAssignmentModal = ref(false);
const editingAssignmentId = ref(null);
const formError = ref('');
const form = reactive({
    province_code: '',
    province_name: '',
    municipality_code: '',
    municipality_name: '',
    barangay: '',
    is_active: true,
});

// ---- Assigned driver ----
const driverError = ref('');
const driverActionBusy = ref(false);

// ---- Rider details view popup (used from both the assigned-driver row
// and the "Assign courier" panel's rider list) ----
const viewingRider = ref(null);
function openRiderView(rider) {
    viewingRider.value = rider;
}
function closeRiderView() {
    viewingRider.value = null;
}

// The assignment object backing the modal, kept fresh off
// `barangayAssignments` (which re-syncs after every save) rather than a
// stale local copy.
const editingAssignment = computed(
    () =>
        barangayAssignments.value.find(
            (a) => a.id === editingAssignmentId.value,
        ) || null,
);
const assignedRider = computed(() => editingAssignment.value?.rider || null);

// ---- "Assign courier" panel: paginated (5/page), searched server-side.
// Only fetched when the panel is actually opened.
const showAddDriverPanel = ref(false);
const availableRiders = ref([]);
const availableMeta = reactive({ lastPage: 1, total: 0 });
const availablePage = ref(1);
const availableSearch = ref('');
const loadingAvailable = ref(false);
const availableError = ref('');
let availableSearchTimer = null;

// Which rider card has its contact/vehicle/plate/address fields expanded —
// collapsed by default, revealed only via that card's "View" button.
const expandedRiderId = ref(null);
function toggleRiderFields(riderId) {
    expandedRiderId.value = expandedRiderId.value === riderId ? null : riderId;
}

function openAddDriverPanel() {
    showAddDriverPanel.value = true;
    availableSearch.value = '';
    availablePage.value = 1;
    expandedRiderId.value = null;
    fetchAvailableRiders();
}
function closeAddDriverPanel() {
    showAddDriverPanel.value = false;
    availableRiders.value = [];
    availableError.value = '';
    expandedRiderId.value = null;
    clearTimeout(availableSearchTimer);
}

async function fetchAvailableRiders() {
    if (!editingAssignmentId.value) {
        return;
    }

    loadingAvailable.value = true;
    availableError.value = '';
    expandedRiderId.value = null;

    try {
        const payload = await loadAvailableRiders(editingAssignmentId.value, {
            search: availableSearch.value.trim(),
            page: availablePage.value,
        });
        availableRiders.value = payload.data || [];
        availableMeta.lastPage = payload.meta?.last_page || 1;
        availableMeta.total = payload.meta?.total || 0;
    } catch (error) {
        availableError.value = error.message;
        availableRiders.value = [];
    } finally {
        loadingAvailable.value = false;
    }
}

function changeAvailablePage(page) {
    if (page < 1 || page > availableMeta.lastPage || loadingAvailable.value) {
        return;
    }

    availablePage.value = page;
    fetchAvailableRiders();
}

// Debounced so typing doesn't fire a request per keystroke.
watch(availableSearch, () => {
    clearTimeout(availableSearchTimer);
    availableSearchTimer = setTimeout(() => {
        availablePage.value = 1;
        fetchAvailableRiders();
    }, 350);
});

async function handleSetDriver(riderProfileId) {
    driverActionBusy.value = true;
    driverError.value = '';

    try {
        await setAssignmentRider(editingAssignmentId.value, riderProfileId);
        closeAddDriverPanel();
    } catch (error) {
        driverError.value = error.message;
    } finally {
        driverActionBusy.value = false;
    }
}
async function handleClearDriver() {
    driverActionBusy.value = true;
    driverError.value = '';

    try {
        await setAssignmentRider(editingAssignmentId.value, null);
    } catch (error) {
        driverError.value = error.message;
    } finally {
        driverActionBusy.value = false;
    }
}

// ---- Province / municipality / barangay dropdowns ----
// Backed by usePsgc(), which memoises every list at module scope and in
// sessionStorage and collapses concurrent callers onto one request.
const provinceOptions = ref([]);
const municipalityOptions = ref([]);
const barangayOptions = ref([]);
const loadingProvinces = ref(false);
const loadingMunicipalities = ref(false);
const loadingBarangays = ref(false);
const addressApiError = ref('');

const provinceSelectOptions = computed(() =>
    provinceOptions.value.map((p) => ({ value: p.code, label: p.name })),
);
const municipalitySelectOptions = computed(() =>
    municipalityOptions.value.map((m) => ({ value: m.code, label: m.name })),
);
const barangaySelectOptions = computed(() =>
    barangayOptions.value.map((b) => ({ value: b.name, label: b.name })),
);

async function fetchProvinces() {
    loadingProvinces.value = true;
    addressApiError.value = '';

    try {
        provinceOptions.value = await psgcProvinces();
    } catch (err) {
        addressApiError.value =
            err.message ||
            'Could not load provinces from the PSGC API. Check your connection and retry.';
    } finally {
        loadingProvinces.value = false;
    }
}

async function fetchMunicipalities(provinceCode) {
    municipalityOptions.value = [];

    if (!provinceCode) {
        return;
    }

    loadingMunicipalities.value = true;
    addressApiError.value = '';

    try {
        municipalityOptions.value = await psgcMunicipalities(provinceCode);
    } catch (err) {
        addressApiError.value =
            err.message ||
            'Could not load cities/municipalities. Please try again.';
    } finally {
        loadingMunicipalities.value = false;
    }
}

async function fetchBarangays(municipalityCode) {
    barangayOptions.value = [];

    if (!municipalityCode) {
        return;
    }

    loadingBarangays.value = true;
    addressApiError.value = '';

    try {
        barangayOptions.value = await psgcBarangays(municipalityCode);
    } catch (err) {
        addressApiError.value =
            err.message ||
            'Could not load barangays. Please try again.';
    } finally {
        loadingBarangays.value = false;
    }
}

function onProvinceChange() {
    const selected = provinceOptions.value.find(
        (p) => p.code === form.province_code,
    );
    form.province_name = selected ? selected.name : '';
    // Changing province invalidates whatever municipality/barangay was
    // picked for the old one.
    form.municipality_code = '';
    form.municipality_name = '';
    form.barangay = '';
    barangayOptions.value = [];
    fetchMunicipalities(form.province_code);
}

function onMunicipalityChange() {
    const selected = municipalityOptions.value.find(
        (m) => m.code === form.municipality_code,
    );
    form.municipality_name = selected ? selected.name : '';
    form.barangay = '';
    fetchBarangays(form.municipality_code);
}

// ---- Autofill province/municipality from the company's own on-file
// address (new assignments only — editing loads the assignment's own
// saved values instead). Fetched once per page visit and cached, since
// the company's address doesn't change while this page is open.
const loadingCompanyAddress = ref(false);
let companyAddressCache; // undefined = not fetched yet, null = fetched, none on file
async function fetchCompanyAddressForAutofill() {
    if (companyAddressCache !== undefined) {
        return companyAddressCache;
    }

    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        companyAddressCache = null;

        return null;
    }

    const { data } = await supabase
        .from('addresses')
        .select(
            'province_code, province_name, municipality_code, municipality_name',
        )
        .eq('logistics_company_id', companyId.value)
        .eq('owner_kind', 'logistics_company')
        .maybeSingle();

    companyAddressCache = data || null;

    return companyAddressCache;
}

async function autofillFromCompanyAddress() {
    loadingCompanyAddress.value = true;

    try {
        const addr = await fetchCompanyAddressForAutofill();

        if (!addr) {
            return;
        }

        const matchedProvince =
            provinceOptions.value.find(
                (p) => p.code === addr.province_code,
            ) ||
            provinceOptions.value.find(
                (p) =>
                    p.name.toLowerCase() ===
                    (addr.province_name || '').toLowerCase(),
            );

        if (!matchedProvince) {
            return;
        }

        form.province_code = matchedProvince.code;
        form.province_name = matchedProvince.name;
        await fetchMunicipalities(matchedProvince.code);

        const matchedMunicipality =
            municipalityOptions.value.find(
                (m) => m.code === addr.municipality_code,
            ) ||
            municipalityOptions.value.find(
                (m) =>
                    m.name.toLowerCase() ===
                    (addr.municipality_name || '').toLowerCase(),
            );

        if (!matchedMunicipality) {
            return;
        }

        form.municipality_code = matchedMunicipality.code;
        form.municipality_name = matchedMunicipality.name;
        await fetchBarangays(matchedMunicipality.code);
        // Barangay is deliberately left blank — that's the field being
        // assigned, not something to inherit from the company's address.
    } finally {
        loadingCompanyAddress.value = false;
    }
}

// ---- Auto-create delivery areas: one row per barangay of a chosen
// municipality, in one request, instead of adding barangays one by one.
const showBulkModal = ref(false);
const bulkProvinceOptions = ref([]);
const bulkProvinceCode = ref('');
const bulkProvinceName = ref('');
const bulkMunicipalityOptions = ref([]);
const bulkMunicipalityCode = ref('');
const bulkMunicipalityName = ref('');
const bulkBarangayList = ref([]);
const bulkLoadingProvinces = ref(false);
const bulkLoadingMunicipalities = ref(false);
const bulkLoadingBarangays = ref(false);
const bulkCreating = ref(false);
const bulkError = ref('');
const bulkResult = ref(null);

const bulkProvinceSelectOptions = computed(() =>
    bulkProvinceOptions.value.map((p) => ({ value: p.code, label: p.name })),
);
const bulkMunicipalitySelectOptions = computed(() =>
    bulkMunicipalityOptions.value.map((m) => ({
        value: m.code,
        label: m.name,
    })),
);

// Barangays this company already has a delivery area for, within the
// chosen municipality — computed from the assignments already loaded on
// the page, so the warning shows instantly without an extra round trip.
const bulkAlreadyCoveredSet = computed(() => {
    if (!bulkMunicipalityName.value) {
        return new Set();
    }

    const needle = bulkMunicipalityName.value.trim().toLowerCase();

    return new Set(
        barangayAssignments.value
            .filter(
                (a) => (a.municipality_name || '').trim().toLowerCase() === needle,
            )
            .map((a) => (a.barangay || '').trim().toLowerCase()),
    );
});
const bulkAlreadyCoveredCount = computed(
    () =>
        bulkBarangayList.value.filter((b) =>
            bulkAlreadyCoveredSet.value.has((b.name || '').trim().toLowerCase()),
        ).length,
);
const bulkNewBarangayCount = computed(
    () => bulkBarangayList.value.length - bulkAlreadyCoveredCount.value,
);
const bulkAllAlreadyCovered = computed(
    () => bulkBarangayList.value.length > 0 && bulkNewBarangayCount.value === 0,
);

async function openBulkModal() {
    showBulkModal.value = true;
    bulkError.value = '';
    bulkResult.value = null;
    bulkProvinceCode.value = '';
    bulkProvinceName.value = '';
    bulkMunicipalityOptions.value = [];
    bulkMunicipalityCode.value = '';
    bulkMunicipalityName.value = '';
    bulkBarangayList.value = [];
    bulkLoadingProvinces.value = true;

    try {
        bulkProvinceOptions.value = await psgcProvinces();
    } catch (err) {
        bulkError.value = err.message || 'Could not load provinces.';
    } finally {
        bulkLoadingProvinces.value = false;
    }

    await autofillBulkFromCompanyAddress();
}

// Same company-address autofill as the single-assignment form (shares its
// cached fetch), just applied to the bulk modal's own province/municipality
// state and chained through to loading that municipality's barangay list.
const bulkLoadingCompanyAddress = ref(false);
async function autofillBulkFromCompanyAddress() {
    bulkLoadingCompanyAddress.value = true;

    try {
        const addr = await fetchCompanyAddressForAutofill();

        if (!addr) {
            return;
        }

        const matchedProvince =
            bulkProvinceOptions.value.find(
                (p) => p.code === addr.province_code,
            ) ||
            bulkProvinceOptions.value.find(
                (p) =>
                    p.name.toLowerCase() ===
                    (addr.province_name || '').toLowerCase(),
            );

        if (!matchedProvince) {
            return;
        }

        bulkProvinceCode.value = matchedProvince.code;
        bulkProvinceName.value = matchedProvince.name;
        bulkLoadingMunicipalities.value = true;

        try {
            bulkMunicipalityOptions.value = await psgcMunicipalities(
                matchedProvince.code,
            );
        } finally {
            bulkLoadingMunicipalities.value = false;
        }

        const matchedMunicipality =
            bulkMunicipalityOptions.value.find(
                (m) => m.code === addr.municipality_code,
            ) ||
            bulkMunicipalityOptions.value.find(
                (m) =>
                    m.name.toLowerCase() ===
                    (addr.municipality_name || '').toLowerCase(),
            );

        if (!matchedMunicipality) {
            return;
        }

        bulkMunicipalityCode.value = matchedMunicipality.code;
        bulkMunicipalityName.value = matchedMunicipality.name;
        bulkLoadingBarangays.value = true;

        try {
            bulkBarangayList.value = await psgcBarangays(
                matchedMunicipality.code,
            );
        } finally {
            bulkLoadingBarangays.value = false;
        }
    } finally {
        bulkLoadingCompanyAddress.value = false;
    }
}

function closeBulkModal() {
    showBulkModal.value = false;
}

async function onBulkProvinceSelect(opt) {
    bulkProvinceName.value = opt.label;
    bulkMunicipalityCode.value = '';
    bulkMunicipalityName.value = '';
    bulkBarangayList.value = [];
    bulkResult.value = null;
    bulkError.value = '';
    bulkLoadingMunicipalities.value = true;

    try {
        bulkMunicipalityOptions.value = await psgcMunicipalities(
            bulkProvinceCode.value,
        );
    } catch (err) {
        bulkError.value = err.message || 'Could not load municipalities.';
    } finally {
        bulkLoadingMunicipalities.value = false;
    }
}

async function onBulkMunicipalitySelect(opt) {
    bulkMunicipalityName.value = opt.label;
    bulkResult.value = null;
    bulkError.value = '';
    bulkLoadingBarangays.value = true;

    try {
        bulkBarangayList.value = await psgcBarangays(
            bulkMunicipalityCode.value,
        );
    } catch (err) {
        bulkError.value = err.message || 'Could not load barangays.';
    } finally {
        bulkLoadingBarangays.value = false;
    }
}

async function submitBulkCreate() {
    if (!bulkMunicipalityCode.value || bulkBarangayList.value.length === 0) {
        bulkError.value = 'Select a municipality first.';

        return;
    }

    if (bulkAllAlreadyCovered.value) {
        bulkError.value = `Every barangay in ${bulkMunicipalityName.value} already has a delivery area.`;

        return;
    }

    bulkCreating.value = true;
    bulkError.value = '';

    try {
        const payload = await bulkCreateBarangayAssignments({
            province_name: bulkProvinceName.value,
            municipality_code: bulkMunicipalityCode.value,
            municipality_name: bulkMunicipalityName.value,
            barangays: bulkBarangayList.value.map((b) => b.name),
        });

        bulkResult.value = {
            created: payload.created_count ?? 0,
            skipped: payload.skipped_count ?? 0,
        };
        notify(
            `Created ${bulkResult.value.created} delivery area${bulkResult.value.created === 1 ? '' : 's'} in ${bulkMunicipalityName.value}.`,
        );
    } catch (error) {
        bulkError.value = error.message;
    } finally {
        bulkCreating.value = false;
    }
}

const staffedCount = computed(
    () => barangayAssignments.value.filter((a) => a.rider).length,
);
const unstaffedCount = computed(
    () => barangayAssignments.value.length - staffedCount.value,
);

// ---- Barangay coverage search + staffed/unstaffed filter ----
const barangaySearch = ref('');
const statusFilter = ref('all'); // 'all' | 'staffed' | 'unstaffed'

function toggleStatusFilter(value) {
    statusFilter.value = statusFilter.value === value ? 'all' : value;
}
function clearCoverageFilters() {
    barangaySearch.value = '';
    statusFilter.value = 'all';
}

const filteredAssignments = computed(() => {
    const needle = barangaySearch.value.trim().toLowerCase();

    return barangayAssignments.value.filter((assignment) => {
        if (statusFilter.value === 'staffed' && !assignment.rider) {
            return false;
        }

        if (statusFilter.value === 'unstaffed' && assignment.rider) {
            return false;
        }

        if (needle && !assignment.barangay.toLowerCase().includes(needle)) {
            return false;
        }

        return true;
    });
});

// ---- Barangay coverage pagination — 6 cards per page ----
const COVERAGE_PAGE_SIZE = 6;
const coveragePage = ref(1);
const coverageTotalPages = computed(() =>
    Math.max(1, Math.ceil(filteredAssignments.value.length / COVERAGE_PAGE_SIZE)),
);
// Clamped rather than reset outright — if a bulk delete/filter shrinks the
// list out from under the current page, this settles on the new last page
// instead of needing a watcher to catch every shrink path.
const clampedCoveragePage = computed(() =>
    Math.min(coveragePage.value, coverageTotalPages.value),
);
const paginatedAssignments = computed(() => {
    const start = (clampedCoveragePage.value - 1) * COVERAGE_PAGE_SIZE;

    return filteredAssignments.value.slice(start, start + COVERAGE_PAGE_SIZE);
});

function changeCoveragePage(page) {
    if (page < 1 || page > coverageTotalPages.value) {
        return;
    }

    coveragePage.value = page;
}

watch([barangaySearch, statusFilter], () => {
    coveragePage.value = 1;
});

function resetForm() {
    Object.assign(form, {
        province_code: '',
        province_name: '',
        municipality_code: '',
        municipality_name: '',
        barangay: '',
        is_active: true,
    });
    municipalityOptions.value = [];
    barangayOptions.value = [];
    addressApiError.value = '';
    driverError.value = '';
}
async function openAssignmentModal(assignment = null) {
    resetForm();
    editingAssignmentId.value = assignment?.id || null;
    closeAddDriverPanel();
    formError.value = '';
    showAssignmentModal.value = true;

    await fetchProvinces();

    if (!assignment) {
        await autofillFromCompanyAddress();

        return;
    }

    Object.assign(form, { is_active: assignment.is_active });

    // Existing assignments only ever stored plain names (no PSGC code for
    // province), so match by name to preselect the right dropdown option.
    const matchedProvince = provinceOptions.value.find(
        (p) =>
            p.name.toLowerCase() ===
            (assignment.province_name || '').toLowerCase(),
    );

    if (!matchedProvince) {
        form.province_name = assignment.province_name || '';
        form.municipality_name = assignment.municipality_name || '';
        form.barangay = assignment.barangay || '';

        return;
    }

    form.province_code = matchedProvince.code;
    form.province_name = matchedProvince.name;
    await fetchMunicipalities(matchedProvince.code);

    const matchedMunicipality = municipalityOptions.value.find(
        (m) =>
            m.name.toLowerCase() ===
            (assignment.municipality_name || '').toLowerCase(),
    );

    form.municipality_code =
        matchedMunicipality?.code || assignment.municipality_code || '';
    form.municipality_name =
        matchedMunicipality?.name || assignment.municipality_name || '';

    if (form.municipality_code) {
        await fetchBarangays(form.municipality_code);
    }

    form.barangay = assignment.barangay || '';
}
function closeAssignmentModal() {
    showAssignmentModal.value = false;
    editingAssignmentId.value = null;
    formError.value = '';
    closeAddDriverPanel();
}
// notify / riderName / riderInitials now come from useLogisticsUi, which
// the whole portal shares.
const riderName = (rider) => personName(rider, 'Unnamed rider');
const riderInitials = initials;

function formatCoverage(assignment) {
    return [assignment.municipality_name, assignment.province_name]
        .filter(Boolean)
        .join(', ');
}
// Display-only "how loaded is this rider" counter (never enforced — see
// ParcelAssignment::COURIER_QUOTA). Falls back to the fixed 20 quota if a
// caller hasn't loaded active_parcels/parcel_quota onto this rider yet.
function riderQuotaLabel(rider) {
    const active = rider.active_parcels ?? 0;
    const quota = rider.parcel_quota ?? 20;

    return `${active}/${quota} parcels`;
}
async function submitAssignment() {
    if (!form.barangay) {
        formError.value = 'Select a barangay.';

        return;
    }

    saving.value = true;
    formError.value = '';
    const wasNew = !editingAssignmentId.value;

    try {
        const saved = await saveBarangayAssignment(
            {
                province_name: form.province_name,
                municipality_code: form.municipality_code || undefined,
                municipality_name: form.municipality_name,
                barangay: form.barangay,
                is_active: form.is_active,
            },
            editingAssignmentId.value,
        );
        notify(wasNew ? 'Barangay assignment created.' : 'Barangay assignment updated.');

        // Stay open on a create instead of closing — the assignment now
        // has an id, so "Assign courier" unlocks.
        if (wasNew) {
            editingAssignmentId.value = saved.id;
        }
    } catch (error) {
        formError.value = error.message;
    } finally {
        saving.value = false;
    }
}
async function removeAssignment() {
    // Capture the id now — the confirm dialog is async, and anything that
    // closes the modal while it's open (an overlay click, say) clears
    // editingAssignmentId, which would otherwise send DELETE .../null.
    const assignmentId = editingAssignmentId.value;

    if (!assignmentId) {
        return;
    }

    const ok = await askConfirm({
        title: 'Delete barangay assignment',
        message: `Delete this assignment for ${form.barangay}? This routing rule cannot be recovered, and any parcels routed to it will need re-sorting.`,
        confirmLabel: 'Delete assignment',
        tone: 'danger',
    });

    if (!ok) {
        return;
    }

    saving.value = true;

    try {
        await deleteBarangayAssignment(assignmentId);
        notify('Barangay assignment deleted.');
        closeAssignmentModal();
    } catch (error) {
        formError.value = error.message;
    } finally {
        saving.value = false;
    }
}

async function load(force = false) {
    refreshing.value = true;

    try {
        await loadBarangayAssignments({ force });
    } catch (error) {
        notifyError(error, 'Could not load barangay assignments.');
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

const refresh = () => load(true);

onActivated(() => load());

onMounted(() => {
    // Kick the province list off now, in the background, so the "Add
    // barangay assignment" modal opens instantly when it's needed. usePsgc
    // memoises it, so this is free on every subsequent visit.
    fetchProvinces();
    load();
});
</script>
