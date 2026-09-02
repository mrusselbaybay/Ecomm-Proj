<template>
    <div class="logistics-page">
        <div class="toast-stack" role="status" aria-live="polite">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="toast"
                :class="toast.type"
            >
                {{ toast.message }}
            </div>
        </div>

        <div class="page-header">
            <div>
                <h2 class="page-title">Riders & delivery areas</h2>
                <p class="page-subtitle">
                    Define destination coverage and appoint an accepted rider to
                    each area.
                </p>
            </div>
            <button class="btn-primary" @click="openAreaModal()">
                Add delivery area
            </button>
        </div>

        <div class="area-summary-grid">
            <div class="stat-card accent-total">
                <p class="field-label">Delivery areas</p>
                <p class="stat-total text-2xl font-bold">
                    {{ deliveryAreas.length }}
                </p>
            </div>
            <div class="stat-card accent-active">
                <p class="field-label">Assigned areas</p>
                <p class="stat-active text-2xl font-bold">
                    {{ assignedAreaCount }}
                </p>
            </div>
            <div class="stat-card accent-pending">
                <p class="field-label">Unassigned areas</p>
                <p class="stat-pending text-2xl font-bold">
                    {{ unassignedAreaCount }}
                </p>
            </div>
        </div>

        <section class="management-section">
            <div class="section-heading">
                <div>
                    <h3>Delivery coverage</h3>
                    <p>Area rules will be used to route scanned parcels.</p>
                </div>
            </div>
            <div v-if="loading" class="card empty-state">
                <div class="loading-spinner"></div>
            </div>
            <div
                v-else-if="deliveryAreas.length === 0"
                class="card empty-state"
            >
                <div class="empty-box">A</div>
                <strong>No delivery areas configured</strong>
                <p>
                    Create Area A, Area B, or use names that match your actual
                    sorting zones.
                </p>
                <button
                    class="btn-primary empty-action"
                    @click="openAreaModal()"
                >
                    Create first area
                </button>
            </div>
            <div v-else class="area-card-grid">
                <article
                    v-for="area in deliveryAreas"
                    :key="area.id"
                    class="card area-card"
                >
                    <div class="area-card-head">
                        <div>
                            <span
                                class="badge"
                                :class="
                                    area.is_active
                                        ? 'badge-teal'
                                        : 'badge-slate'
                                "
                                >{{
                                    area.is_active ? 'Active' : 'Inactive'
                                }}</span
                            >
                            <h4>{{ area.name }}</h4>
                        </div>
                        <button
                            class="area-menu-button"
                            title="Edit area"
                            @click="openAreaModal(area)"
                        >
                            Edit
                        </button>
                    </div>
                    <p class="area-address">{{ formatCoverage(area) }}</p>
                    <div class="assigned-rider">
                        <div class="avatar">{{ area.riders?.length || 0 }}</div>
                        <div v-if="area.riders?.length">
                            <strong
                                >{{ area.riders.length }}
                                {{
                                    area.riders.length === 1
                                        ? 'driver'
                                        : 'drivers'
                                }}
                                assigned</strong
                            ><span>Open Edit to manage</span>
                        </div>
                        <div v-else>
                            <strong>No drivers assigned</strong
                            ><span>Parcels will require manual assignment</span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section class="management-section">
            <div class="section-heading">
                <div>
                    <h3>Accepted rider roster</h3>
                    <p>
                        Only approved riders can be appointed to delivery areas.
                    </p>
                </div>
                <span class="badge badge-slate"
                    >{{ areaRiders.length }} riders</span
                >
            </div>
            <div class="card overflow-hidden">
                <div class="table-scroll">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Rider</th>
                                <th>Vehicle</th>
                                <th>Contact</th>
                                <th>Assigned areas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="areaRiders.length === 0">
                                <td colspan="4">
                                    <div class="empty-state">
                                        No accepted riders yet.
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="rider in areaRiders" :key="rider.id">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar">
                                            {{ riderInitials(rider) }}
                                        </div>
                                        <div>
                                            <p
                                                class="font-medium text-slate-800"
                                            >
                                                {{ riderName(rider) }}
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                {{ rider.email }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    {{ rider.vehicle || 'Details not provided'
                                    }}<span
                                        v-if="rider.plate_number"
                                        class="rider-plate"
                                        >{{ rider.plate_number }}</span
                                    >
                                </td>
                                <td>
                                    {{ rider.contact_no || 'Not provided' }}
                                </td>
                                <td>
                                    <span class="badge badge-indigo">{{
                                        assignedAreasFor(rider.id)
                                    }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div
            v-if="showAreaModal"
            class="modal-overlay"
            @click.self="closeAreaModal"
        >
            <div class="area-modal-row">
            <form
                class="modal-panel area-form-modal"
                @submit.prevent="submitArea"
            >
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Routing rule</p>
                        <h3>
                            {{
                                editingAreaId
                                    ? 'Edit delivery area'
                                    : 'New delivery area'
                            }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        class="modal-close"
                        @click="closeAreaModal"
                    >
                        &times;
                    </button>
                </div>

                <div class="modal-tabs">
                    <button
                        type="button"
                        class="modal-tab-button"
                        :class="{ active: activeTab === 'location' }"
                        @click="switchTab('location')"
                    >
                        Location
                    </button>
                    <button
                        type="button"
                        class="modal-tab-button"
                        :class="{ active: activeTab === 'drivers' }"
                        :disabled="!editingAreaId"
                        :title="
                            editingAreaId
                                ? ''
                                : 'Save the area first to assign drivers'
                        "
                        @click="switchTab('drivers')"
                    >
                        Assigned drivers
                    </button>
                </div>

                <template v-if="activeTab === 'location'">
                    <p class="modal-desc">
                        Use the parcel's province and municipality to
                        determine its destination area.
                    </p>
                    <div class="area-form-grid">
                        <label class="form-field full-field"
                            ><span>Area name</span
                            ><input
                                v-model.trim="form.name"
                                class="field-input"
                                placeholder="Area A"
                                required
                                maxlength="100"
                        /></label>
                        <label class="form-field"
                            ><span>Province</span
                            ><select
                                v-model="form.province_code"
                                class="field-input"
                                required
                                :disabled="loadingProvinces"
                                @change="onProvinceChange"
                            >
                                <option value="" disabled>
                                    {{
                                        loadingProvinces
                                            ? 'Loading provinces…'
                                            : 'Select a province'
                                    }}
                                </option>
                                <option
                                    v-for="province in provinceOptions"
                                    :key="province.code"
                                    :value="province.code"
                                >
                                    {{ province.name }}
                                </option>
                            </select></label
                        >
                        <label class="form-field"
                            ><span>Municipality / City</span
                            ><select
                                v-model="form.municipality_code"
                                class="field-input"
                                required
                                :disabled="
                                    !form.province_code || loadingMunicipalities
                                "
                                @change="onMunicipalityChange"
                            >
                                <option value="" disabled>
                                    {{
                                        loadingMunicipalities
                                            ? 'Loading municipalities…'
                                            : form.province_code
                                              ? 'Select a municipality'
                                              : 'Select a province first'
                                    }}
                                </option>
                                <option
                                    v-for="municipality in municipalityOptions"
                                    :key="municipality.code"
                                    :value="municipality.code"
                                >
                                    {{ municipality.name }}
                                </option>
                            </select></label
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
                                ><strong>Area is active</strong
                                ><small
                                    >Active areas can receive automatic
                                    parcel matches.</small
                                ></span
                            ></label
                        >
                    </div>
                    <p v-if="formError" class="callout-red">{{ formError }}</p>
                    <div class="modal-actions">
                        <button
                            v-if="editingAreaId"
                            type="button"
                            class="btn-danger-outline delete-area-button"
                            @click="removeArea"
                        >
                            Delete area
                        </button>
                        <button
                            type="button"
                            class="btn-outline"
                            @click="closeAreaModal"
                        >
                            {{ editingAreaId ? 'Close' : 'Cancel' }}
                        </button>
                        <button class="btn-primary" :disabled="saving">
                            {{ saving ? 'Saving...' : 'Save area' }}
                        </button>
                    </div>
                </template>

                <template v-else>
                    <div class="driver-panel-heading">
                        <h4>Assigned drivers ({{ assignedDrivers.length }})</h4>
                        <button
                            type="button"
                            class="btn-sm-outline"
                            @click="openAddDriverPanel"
                        >
                            + Add courier
                        </button>
                    </div>
                    <div class="driver-list">
                        <p
                            v-if="assignedDrivers.length === 0"
                            class="driver-empty-hint"
                        >
                            No drivers assigned yet. Click "Add courier" to
                            appoint one.
                        </p>
                        <div
                            v-for="rider in assignedDrivers"
                            :key="rider.id"
                            class="driver-row"
                        >
                            <div class="avatar">
                                {{ riderInitials(rider) }}
                            </div>
                            <div class="driver-row-info">
                                <strong>{{ riderName(rider) }}</strong>
                                <span class="driver-row-address">{{
                                    rider.address || 'No address on file'
                                }}</span>
                            </div>
                            <div class="driver-row-actions">
                                <button
                                    type="button"
                                    class="driver-view-button"
                                    title="View rider details"
                                    @click="openRiderView(rider)"
                                >
                                    View
                                </button>
                                <button
                                    type="button"
                                    class="btn-sm-outline"
                                    :disabled="driverActionRiderId === rider.id"
                                    @click="handleRemoveDriver(rider.id)"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-if="driverError" class="callout-red">
                        {{ driverError }}
                    </p>
                    <p v-if="formError" class="callout-red">{{ formError }}</p>
                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-danger-outline delete-area-button"
                            @click="removeArea"
                        >
                            Delete area
                        </button>
                        <button
                            type="button"
                            class="btn-primary"
                            @click="closeAreaModal"
                        >
                            Done
                        </button>
                    </div>
                </template>
            </form>

            <div
                v-if="showAddDriverPanel"
                class="modal-panel add-driver-panel"
            >
                <div class="modal-header">
                    <div>
                        <p class="eyebrow">Add courier</p>
                        <h3>Unassigned riders</h3>
                    </div>
                    <button
                        type="button"
                        class="modal-close"
                        @click="closeAddDriverPanel"
                    >
                        &times;
                    </button>
                </div>

                <div class="search-input rounded driver-search">
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
                                    : 'All accepted riders are already assigned to this area.'
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
                                        @click="toggleRiderFields(rider.id)"
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
                                        :disabled="
                                            driverActionRiderId === rider.id
                                        "
                                        @click="handleAddDriver(rider.id)"
                                    >
                                        Add
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
                                        rider.contact_no || 'Not provided'
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
                                        rider.plate_number || 'Not provided'
                                    }}</span>
                                </div>
                                <div class="courier-field courier-field-full">
                                    <span class="courier-field-label"
                                        >Address</span
                                    >
                                    <span class="courier-field-value">{{
                                        rider.address || 'No address on file'
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
                        :disabled="availablePage <= 1 || loadingAvailable"
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
                        <dd>{{
                            viewingRider.contact_no || 'Not provided'
                        }}</dd>
                    </div>
                    <div>
                        <dt>Address</dt>
                        <dd>{{
                            viewingRider.address || 'No address on file'
                        }}</dd>
                    </div>
                    <div v-if="viewingRider.vehicle">
                        <dt>Vehicle</dt>
                        <dd
                            >{{ viewingRider.vehicle
                            }}<span v-if="viewingRider.plate_number">
                                ({{ viewingRider.plate_number }})</span
                            ></dd
                        >
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
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const {
    deliveryAreas,
    areaRiders,
    loadDeliveryAreas,
    saveDeliveryArea,
    deleteDeliveryArea,
    addAreaRider,
    removeAreaRider,
    loadAvailableRiders,
} = useLogistics();
const loading = ref(true);
const saving = ref(false);
const showAreaModal = ref(false);
const editingAreaId = ref(null);
const activeTab = ref('location');
const formError = ref('');
const toasts = ref([]);
const form = reactive({
    name: '',
    province_code: '',
    province_name: '',
    municipality_code: '',
    municipality_name: '',
    is_active: true,
});

// ---- Assigned drivers tab ----
const driverError = ref('');
const driverActionRiderId = ref(null);

// ---- Rider details view popup (used from both the assigned-drivers list
// and the "Add driver" panel's unassigned riders) ----
const viewingRider = ref(null);
function openRiderView(rider) {
    viewingRider.value = rider;
}
function closeRiderView() {
    viewingRider.value = null;
}

// The area object backing the modal, kept fresh off `deliveryAreas` (which
// re-syncs after every add/remove) rather than a stale local copy.
const editingArea = computed(() =>
    deliveryAreas.value.find((area) => area.id === editingAreaId.value) || null,
);
const assignedDrivers = computed(() => editingArea.value?.riders || []);

function switchTab(tab) {
    activeTab.value = tab;
    if (tab !== 'drivers') closeAddDriverPanel();
}

// ---- "Add driver" side panel: paginated (5/page), searched server-side.
// Only fetched when the panel is actually opened — the assigned list
// above never pulls in the full company roster just to render itself.
const showAddDriverPanel = ref(false);
const availableRiders = ref([]);
const availableMeta = reactive({ lastPage: 1, total: 0 });
const availablePage = ref(1);
const availableSearch = ref('');
const loadingAvailable = ref(false);
const availableError = ref('');
let availableSearchTimer = null;

// Which unassigned-rider card has its contact/vehicle/plate/address fields
// expanded — collapsed by default, revealed only via that card's "View"
// button, so the panel stays scannable instead of showing every field for
// every rider at once.
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
    if (!editingAreaId.value) return;

    loadingAvailable.value = true;
    availableError.value = '';
    expandedRiderId.value = null;

    try {
        const payload = await loadAvailableRiders(editingAreaId.value, {
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
    if (page < 1 || page > availableMeta.lastPage || loadingAvailable.value) return;
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

async function handleAddDriver(riderProfileId) {
    driverActionRiderId.value = riderProfileId;
    driverError.value = '';
    try {
        await addAreaRider(editingAreaId.value, riderProfileId);

        // The rider just added no longer belongs in the "available" pool
        // — refetch this page, stepping back one if that was the last
        // rider on the last page.
        await fetchAvailableRiders();
        if (availableRiders.value.length === 0 && availablePage.value > 1) {
            availablePage.value -= 1;
            await fetchAvailableRiders();
        }
    } catch (error) {
        driverError.value = error.message;
    } finally {
        driverActionRiderId.value = null;
    }
}
async function handleRemoveDriver(riderProfileId) {
    driverActionRiderId.value = riderProfileId;
    driverError.value = '';
    try {
        await removeAreaRider(editingAreaId.value, riderProfileId);

        // The removed rider is available again — only worth a refetch if
        // that panel is actually open right now.
        if (showAddDriverPanel.value) {
            await fetchAvailableRiders();
        }
    } catch (error) {
        driverError.value = error.message;
    } finally {
        driverActionRiderId.value = null;
    }
}

// ---- Province / municipality dropdowns ----
// Same PSGC proxy + fetch pattern the signup wizard uses
// (resources/js/app.js fetchProvinces/fetchMunicipalities): one cached
// request for every province (PsgcProxyController::allProvinces does the
// regions -> provinces fan-out server-side, once, for everyone) instead
// of the browser making ~18 round-trips itself, plus a per-request abort
// timeout so a slow upstream response fails fast instead of hanging the
// form. Only province + municipality are fetched — no barangay step.
const PSGC_BASE = '/api/psgc';
const provinceOptions = ref([]);
const municipalityOptions = ref([]);
const loadingProvinces = ref(false);
const loadingMunicipalities = ref(false);
const addressApiError = ref('');
let provinceCache = [];

function dedupeByCodeOrName(items = []) {
    const seen = new Map();

    for (const item of items) {
        if (!item || typeof item !== 'object') continue;

        const code = String(item.code ?? '').trim();
        const name = String(item.name ?? '').trim();

        if (!code && !name) continue;

        const key = code || name.toLowerCase().replace(/\s+/g, ' ');

        if (!seen.has(key)) seen.set(key, item);
    }

    return Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name));
}

async function fetchProvinces() {
    if (provinceCache.length > 0) {
        provinceOptions.value = provinceCache;
        return;
    }

    loadingProvinces.value = true;
    addressApiError.value = '';

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 15000);

        const res = await fetch(`${PSGC_BASE}/provinces/all`, {
            signal: controller.signal,
        });
        clearTimeout(timeoutId);

        if (!res.ok) throw new Error('Request failed: ' + res.status);

        const json = await res.json();
        const provinces = dedupeByCodeOrName(json.data || []);

        if (provinces.length === 0) throw new Error('No provinces returned');

        provinceCache = provinces;
        provinceOptions.value = provinces;
    } catch (err) {
        addressApiError.value =
            err.name === 'AbortError'
                ? 'Loading provinces timed out. Please retry.'
                : 'Could not load provinces from the PSGC API. Check your connection and retry.';
    } finally {
        loadingProvinces.value = false;
    }
}

async function fetchMunicipalities(provinceCode) {
    municipalityOptions.value = [];

    if (!provinceCode) return;

    loadingMunicipalities.value = true;
    addressApiError.value = '';

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);

        const res = await fetch(
            `${PSGC_BASE}/cities-municipalities?province_code=${provinceCode}`,
            { signal: controller.signal },
        );
        clearTimeout(timeoutId);

        if (!res.ok) throw new Error('Request failed: ' + res.status);

        const json = await res.json();
        municipalityOptions.value = (json.data || [])
            .slice()
            .sort((a, b) => a.name.localeCompare(b.name));
    } catch (err) {
        addressApiError.value =
            err.name === 'AbortError'
                ? 'Loading municipalities timed out. Please retry.'
                : 'Could not load cities/municipalities. Please try again.';
    } finally {
        loadingMunicipalities.value = false;
    }
}

function onProvinceChange() {
    const selected = provinceOptions.value.find((p) => p.code === form.province_code);
    form.province_name = selected ? selected.name : '';
    form.municipality_code = '';
    form.municipality_name = '';
    fetchMunicipalities(form.province_code);
}

function onMunicipalityChange() {
    const selected = municipalityOptions.value.find((m) => m.code === form.municipality_code);
    form.municipality_name = selected ? selected.name : '';
}

const assignedAreaCount = computed(
    () => deliveryAreas.value.filter((area) => area.riders?.length).length,
);
const unassignedAreaCount = computed(
    () => deliveryAreas.value.length - assignedAreaCount.value,
);

function resetForm() {
    Object.assign(form, {
        name: '',
        province_code: '',
        province_name: '',
        municipality_code: '',
        municipality_name: '',
        is_active: true,
    });
    municipalityOptions.value = [];
    addressApiError.value = '';
    driverError.value = '';
}
async function openAreaModal(area = null) {
    resetForm();
    editingAreaId.value = area?.id || null;
    activeTab.value = 'location';
    closeAddDriverPanel();
    formError.value = '';
    showAreaModal.value = true;

    await fetchProvinces();

    if (!area) return;

    Object.assign(form, {
        name: area.name,
        is_active: area.is_active,
    });

    // Existing areas only ever stored a plain name (no PSGC code), so
    // match by name to preselect the right dropdown option.
    const matchedProvince = provinceOptions.value.find(
        (p) => p.name.toLowerCase() === (area.province_name || '').toLowerCase(),
    );

    if (!matchedProvince) {
        // Unmatched legacy value — keep it visible instead of silently
        // dropping it, even though it won't be a selectable option.
        form.province_name = area.province_name || '';
        form.municipality_name = area.municipality_name || '';
        return;
    }

    form.province_code = matchedProvince.code;
    form.province_name = matchedProvince.name;
    await fetchMunicipalities(matchedProvince.code);

    const matchedMunicipality = municipalityOptions.value.find(
        (m) => m.name.toLowerCase() === (area.municipality_name || '').toLowerCase(),
    );

    if (matchedMunicipality) {
        form.municipality_code = matchedMunicipality.code;
        form.municipality_name = matchedMunicipality.name;
    } else {
        form.municipality_name = area.municipality_name || '';
    }
}
function closeAreaModal() {
    showAreaModal.value = false;
    editingAreaId.value = null;
    formError.value = '';
    closeAddDriverPanel();
}
function notify(message, type = 'success') {
    const id = Date.now();
    toasts.value.push({ id, message, type });
    setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, 3500);
}
function riderName(rider) {
    return rider
        ? `${rider.first_name || ''} ${rider.last_name || ''}`.trim() ||
              'Unnamed rider'
        : '';
}
function riderInitials(rider) {
    return (
        riderName(rider)
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase() || '?'
    );
}
function formatCoverage(area) {
    return [area.municipality_name, area.province_name]
        .filter(Boolean)
        .join(', ');
}
function assignedAreasFor(riderId) {
    const count = deliveryAreas.value.filter((area) =>
        area.riders?.some((rider) => rider.id === riderId),
    ).length;
    return `${count} ${count === 1 ? 'area' : 'areas'}`;
}
async function submitArea() {
    saving.value = true;
    formError.value = '';
    const wasNew = !editingAreaId.value;
    try {
        const saved = await saveDeliveryArea(
            {
                name: form.name,
                province_name: form.province_name,
                municipality_name: form.municipality_name,
                is_active: form.is_active,
            },
            editingAreaId.value,
        );
        notify(wasNew ? 'Delivery area created.' : 'Delivery area updated.');

        // Stay open on a create instead of closing — the area now has an
        // id, so "Assigned drivers" unlocks and the user can go straight
        // to appointing drivers without reopening it from the list.
        if (wasNew) {
            editingAreaId.value = saved.id;
        }
    } catch (error) {
        formError.value = error.message;
    } finally {
        saving.value = false;
    }
}
async function removeArea() {
    if (!confirm(`Delete ${form.name}? This routing rule cannot be recovered.`))
        return;
    saving.value = true;
    try {
        await deleteDeliveryArea(editingAreaId.value);
        notify('Delivery area deleted.');
        closeAreaModal();
    } catch (error) {
        formError.value = error.message;
    } finally {
        saving.value = false;
    }
}
onMounted(async () => {
    // Kick the province list off now, in the background, so it's already
    // cached (and the "Add delivery area" modal opens instantly) by the
    // time anyone actually needs it — same reasoning as the signup
    // wizard's early fetchProvinces() call.
    fetchProvinces();

    try {
        await loadDeliveryAreas();
    } catch (error) {
        notify(error.message, 'error');
    } finally {
        loading.value = false;
    }
});
</script>
