<!-- resources/js/logistics/components/Riders.vue
     The accepted-rider roster. Same table + modal UI as the Rider
     Applications page, narrowed to riders who are currently accepted.
     "Fire" lives here (moved off the Applications page, which is now just
     the review pipeline). -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div>
                <h2 class="page-title">Riders</h2>
                <p class="page-subtitle">
                    Riders currently accepted by
                    {{ companyName || 'your company' }}. Appoint them to
                    delivery areas from the Delivery Areas page.
                </p>
            </div>
            <div class="page-header-actions">
                <button
                    type="button"
                    class="btn-outline btn-icon"
                    :disabled="refreshing"
                    @click="load(true)"
                >
                    <NavIcon name="refresh" :size="15" />
                    Refresh
                </button>
                <button
                    type="button"
                    class="btn-primary"
                    @click="emit('open-section', 'areas')"
                >
                    Manage delivery areas
                </button>
            </div>
        </header>

        <div class="queue-toolbar">
            <span class="badge badge-slate"
                >{{ acceptedRiders.length }}
                {{ acceptedRiders.length === 1 ? 'rider' : 'riders' }}</span
            >
            <div class="search-input queue-search">
                <NavIcon name="search" :size="15" class="icon" />
                <label for="riders-search" class="sr-only"
                    >Search by rider name or email</label
                >
                <input
                    id="riders-search"
                    v-model="search"
                    type="search"
                    placeholder="Search by rider name or email…"
                    @input="debouncedLoad"
                />
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Rider</th>
                            <th>Status</th>
                            <th>Assigned areas</th>
                            <th>Parcel quota</th>
                            <th>Documents</th>
                            <th class="text-center">Actions</th>
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
                        <tr v-else-if="acceptedRiders.length === 0">
                            <td colspan="6">
                                <div class="empty-state">
                                    <NavIcon name="couriers" :size="30" />
                                    <strong>{{ emptyTitle }}</strong>
                                    <p>{{ emptyHint }}</p>
                                    <button
                                        v-if="search.trim()"
                                        type="button"
                                        class="btn-outline"
                                        @click="clearSearch"
                                    >
                                        Clear search
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <template v-else>
                            <tr v-for="rider in acceptedRiders" :key="rider.id">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar" aria-hidden="true">
                                            {{ initials(rider.courier) }}
                                        </div>
                                        <div>
                                            <p
                                                class="font-medium text-slate-800"
                                            >
                                                {{ rider.courier?.first_name }}
                                                {{ rider.courier?.last_name }}
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                {{ rider.courier?.email }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="
                                            isAvailable(rider)
                                                ? 'badge-teal'
                                                : 'badge-slate'
                                        "
                                    >
                                        <span class="status-dot"></span>
                                        {{
                                            isAvailable(rider)
                                                ? 'Available'
                                                : 'Unavailable'
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <div
                                        v-if="
                                            assignedAreasFor(
                                                rider.courier_profile_id,
                                            ).length
                                        "
                                        class="area-chip-list"
                                    >
                                        <span
                                            v-for="name in assignedAreasFor(
                                                rider.courier_profile_id,
                                            )"
                                            :key="name"
                                            class="badge badge-indigo"
                                            >{{ name }}</span
                                        >
                                    </div>
                                    <span v-else class="text-xs text-slate-500"
                                        >Unassigned</span
                                    >
                                </td>
                                <td>
                                    <span class="rider-quota-badge">{{
                                        quotaLabel(rider)
                                    }}</span>
                                </td>
                                <td>
                                    <button
                                        class="btn-doc"
                                        @click="openDocuments(rider)"
                                    >
                                        View
                                    </button>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button
                                            class="btn-sm-outline"
                                            @click="openDetailsModal(rider)"
                                        >
                                            Details
                                        </button>
                                        <button
                                            class="btn-danger-outline btn-fire"
                                            @click="openFireModal(rider)"
                                        >
                                            Fire
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <Teleport to=".logistics-shell">
            <!-- FIRE MODAL -->
            <transition name="modal">
                <div
                    v-if="fireApp"
                    class="modal-overlay"
                    @click.self="closeFireModal"
                >
                    <div
                        class="modal-panel modal-sm"
                        role="alertdialog"
                        aria-modal="true"
                        aria-labelledby="fire-modal-title"
                    >
                        <div class="modal-header">
                            <h3 id="fire-modal-title">Fire rider</h3>
                            <button
                                class="modal-close"
                                aria-label="Close"
                                @click="closeFireModal"
                            >
                                ✕
                            </button>
                        </div>
                        <p class="modal-desc">
                            <strong>{{ personName(fireApp.courier) }}</strong>
                            will be removed from your delivery areas immediately
                            and freed to join another company. They'll be
                            emailed about this.
                        </p>
                        <label class="field-label" for="fire-reason"
                            >Reason
                            <span class="text-slate-500"
                                >(optional, shared with the rider)</span
                            ></label
                        >
                        <textarea
                            id="fire-reason"
                            v-model="fireReason"
                            class="field-input mt-1"
                            rows="3"
                            maxlength="2000"
                            placeholder="e.g. Repeated missed pickups"
                        ></textarea>
                        <div class="modal-actions">
                            <button class="btn-outline" @click="closeFireModal">
                                Cancel
                            </button>
                            <button
                                class="btn-danger"
                                :disabled="firing"
                                @click="submitFire"
                            >
                                {{ firing ? 'Firing…' : 'Fire rider' }}
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- DETAILS MODAL -->
            <transition name="modal">
                <div
                    v-if="detailsApp"
                    class="modal-overlay rider-view-overlay"
                    @click.self="closeDetailsModal"
                >
                    <div class="modal-panel modal-sm rider-view-panel">
                        <div class="modal-header">
                            <p class="eyebrow">Rider profile</p>
                            <button
                                type="button"
                                class="modal-close"
                                aria-label="Close"
                                @click="closeDetailsModal"
                            >
                                &times;
                            </button>
                        </div>

                        <div class="rider-view-identity">
                            <div class="avatar avatar-lg">
                                {{ initials(detailsApp.courier) }}
                            </div>
                            <h3 class="rider-view-name">
                                {{ personName(detailsApp.courier) }}
                            </h3>
                        </div>

                        <div class="details-field-grid">
                            <div class="full-span">
                                <label class="field-label">Email</label>
                                <input
                                    class="field-input"
                                    disabled
                                    :value="
                                        detailsApp.courier?.email ||
                                        'Not provided'
                                    "
                                />
                            </div>
                            <div class="full-span">
                                <label class="field-label">Contact no.</label>
                                <input
                                    class="field-input"
                                    disabled
                                    :value="
                                        detailsApp.courier?.contact_no ||
                                        'Not provided'
                                    "
                                />
                            </div>
                            <div class="full-span">
                                <label class="field-label">Address</label>
                                <input
                                    class="field-input"
                                    disabled
                                    :value="
                                        detailsApp.courier?.address ||
                                        'No address on file'
                                    "
                                />
                            </div>
                            <div>
                                <label class="field-label">Birth date</label>
                                <input
                                    class="field-input"
                                    disabled
                                    :value="
                                        detailsApp.courier?.birthday
                                            ? formatDate(
                                                  detailsApp.courier.birthday,
                                              )
                                            : 'Not provided'
                                    "
                                />
                            </div>
                            <div>
                                <label class="field-label">Vehicle type</label>
                                <input
                                    class="field-input"
                                    disabled
                                    :value="
                                        detailsApp.courier_details?.vehicle ||
                                        'Not provided'
                                    "
                                />
                            </div>
                            <div class="full-span">
                                <label class="field-label">Plate number</label>
                                <input
                                    class="field-input"
                                    disabled
                                    :value="
                                        detailsApp.courier_details
                                            ?.plate_number || 'Not provided'
                                    "
                                />
                            </div>
                            <div v-if="detailsApp.quota" class="full-span">
                                <label class="field-label">Parcel quota</label>
                                <input
                                    class="field-input"
                                    disabled
                                    :value="`${detailsApp.quota.active}/${detailsApp.quota.max} parcels currently in hand`"
                                />
                            </div>
                        </div>

                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-outline"
                                @click="closeDetailsModal"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- DOCUMENTS MODAL -->
            <transition name="modal">
                <div
                    v-if="showDocsModal"
                    class="modal-overlay"
                    @click.self="closeDocuments"
                >
                    <div
                        class="modal-panel"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="docs-modal-title"
                    >
                        <div class="modal-header">
                            <h3 id="docs-modal-title">
                                Documents — {{ docsApp?.courier?.first_name }}
                                {{ docsApp?.courier?.last_name }}
                            </h3>
                            <button
                                class="modal-close"
                                aria-label="Close"
                                @click="closeDocuments"
                            >
                                ✕
                            </button>
                        </div>
                        <div
                            v-if="docsApp?.resume_original_name"
                            class="doc-row"
                            style="margin-bottom: 12px"
                        >
                            <div class="doc-info">
                                <div>
                                    <p class="doc-type">
                                        Resume —
                                        {{ docsApp.resume_original_name }}
                                    </p>
                                    <p class="doc-date">
                                        {{
                                            formatFileSize(docsApp.resume_size)
                                        }}
                                        · Applied
                                        {{ formatDate(docsApp.applied_at) }}
                                    </p>
                                </div>
                            </div>
                            <div class="doc-actions">
                                <button
                                    class="btn-sm-outline"
                                    :disabled="resumeLoading"
                                    @click="viewResume(docsApp)"
                                >
                                    {{
                                        resumeLoading
                                            ? 'Opening…'
                                            : 'View Resume'
                                    }}
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="docsApp?.license_original_name"
                            class="doc-row"
                            style="margin-bottom: 12px"
                        >
                            <div class="doc-info">
                                <div>
                                    <p class="doc-type">
                                        Driver's License —
                                        {{ docsApp.license_original_name }}
                                    </p>
                                    <p class="doc-date">
                                        {{
                                            formatFileSize(docsApp.license_size)
                                        }}
                                        · Applied
                                        {{ formatDate(docsApp.applied_at) }}
                                    </p>
                                </div>
                            </div>
                            <div class="doc-actions">
                                <button
                                    class="btn-sm-outline"
                                    :disabled="licenseLoading"
                                    @click="viewLicense(docsApp)"
                                >
                                    {{
                                        licenseLoading
                                            ? 'Opening…'
                                            : 'View License'
                                    }}
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="docsApp?.cover_note"
                            class="callout-red"
                            style="
                                background: #f0fdfa;
                                border-color: #99f6e4;
                                color: #0f766e;
                                margin-bottom: 12px;
                            "
                        >
                            <strong>Cover note:</strong>
                            {{ docsApp.cover_note }}
                        </div>
                        <div v-if="docsLoading" class="py-8 text-center">
                            <div
                                class="loading-spinner"
                                role="status"
                                aria-label="Loading documents"
                            ></div>
                        </div>
                        <div
                            v-else-if="userDocuments.length === 0"
                            class="empty-state"
                        >
                            <p>No other documents uploaded yet.</p>
                        </div>
                        <div v-else class="doc-list">
                            <div
                                v-for="doc in userDocuments"
                                :key="doc.id"
                                class="doc-row"
                            >
                                <div class="doc-info">
                                    <div>
                                        <p class="doc-type">
                                            {{ titleCase(doc.doc_type) }}
                                        </p>
                                        <p class="doc-date">
                                            Uploaded
                                            {{ formatDate(doc.created_at) }}
                                        </p>
                                    </div>
                                </div>
                                <div class="doc-actions">
                                    <span
                                        class="badge"
                                        :class="badgeClass(doc.status)"
                                        >{{ doc.status }}</span
                                    >
                                    <button
                                        class="btn-sm-outline"
                                        @click="viewDocument(doc)"
                                    >
                                        View
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="modal-actions">
                            <button @click="closeDocuments" class="btn-outline">
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- DOC PREVIEW -->
            <transition name="modal">
                <div
                    v-if="previewDoc"
                    class="modal-overlay preview-overlay"
                    @click.self="closePreview"
                >
                    <div
                        class="preview-panel"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="preview-modal-title"
                    >
                        <div class="modal-header">
                            <h3 id="preview-modal-title">
                                {{ titleCase(previewDoc.doc_type) }}
                            </h3>
                            <button
                                class="modal-close"
                                aria-label="Close"
                                @click="closePreview"
                            >
                                ✕
                            </button>
                        </div>
                        <div class="preview-body">
                            <div
                                v-if="previewLoading"
                                class="loading-spinner"
                                role="status"
                                aria-label="Loading preview"
                            ></div>
                            <img
                                v-else-if="previewUrl"
                                :src="previewUrl"
                                class="preview-image"
                                alt="Document preview"
                            />
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>
    </div>
</template>

<script setup>
import { computed, onActivated, onMounted, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import NavIcon from './NavIcon.vue';

const emit = defineEmits(['open-section']);

const {
    supabase,
    companyName,
    acceptedRiders,
    deliveryAreas,
    loadAcceptedRiders,
    removeAcceptedRider,
    loadDeliveryAreas,
    logisticsFetch,
} = useLogistics();
const {
    notify,
    notifyError,
    formatDate,
    formatFileSize,
    initials,
    personName,
    badgeClass,
    titleCase,
} = useLogisticsUi();

const search = ref('');
const loading = ref(true); // first-load skeleton gate; never re-armed for refreshes
const refreshing = ref(false);

const emptyTitle = computed(() =>
    search.value.trim()
        ? 'No riders match that search'
        : 'No accepted riders yet',
);
const emptyHint = computed(() =>
    search.value.trim()
        ? 'Try a different name or email.'
        : 'Accept a rider from the Rider Applications page and they show up here.',
);

let searchDebounce = null;
function debouncedLoad() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => load(true), 350);
}

function clearSearch() {
    search.value = '';
    load(true);
}

// courier_profile_id -> [area name, …] the rider currently handles, built
// once per roster/area change instead of re-filtering every area for every
// table row on each render.
const areaNamesByRider = computed(() => {
    const map = new Map();

    for (const area of deliveryAreas.value) {
        for (const rider of area.riders || []) {
            const list = map.get(rider.id);
            list ? list.push(area.name) : map.set(rider.id, [area.name]);
        }
    }

    return map;
});

function assignedAreasFor(courierId) {
    return areaNamesByRider.value.get(courierId) || [];
}

// Backed by courier_details.delivery_status in Supabase — the flag behind
// the driver app's "Go online" toggle. Anything other than 'available'
// (including a missing detail row) reads as off-shift.
function isAvailable(rider) {
    return rider.courier_details?.delivery_status === 'available';
}

function quotaLabel(rider) {
    const active = rider.quota?.active ?? 0;
    const max = rider.quota?.max ?? 20;

    return `${active}/${max} parcels`;
}

// ---- Fire ----
const fireApp = ref(null);
const fireReason = ref('');
const firing = ref(false);

function openFireModal(rider) {
    fireApp.value = rider;
    fireReason.value = '';
}
function closeFireModal() {
    if (firing.value) {
        return;
    }

    fireApp.value = null;
    fireReason.value = '';
}

async function submitFire() {
    const app = fireApp.value;

    if (!app || firing.value) {
        return;
    }

    firing.value = true;

    try {
        const response = await logisticsFetch(
            `/api/logistics/applications/${app.id}/terminate`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason: fireReason.value.trim() }),
            },
        );
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || 'Failed to fire the rider.');
        }

        removeAcceptedRider(app.id);
        firing.value = false;
        fireApp.value = null;
        fireReason.value = '';
        notify(`${app.courier?.first_name || 'Rider'} has been let go.`);
    } catch (e) {
        firing.value = false;
        notifyError(e, 'Failed to fire the rider.');
    }
}

// ---- Details ----
const detailsApp = ref(null);
function openDetailsModal(rider) {
    detailsApp.value = rider;
}
function closeDetailsModal() {
    detailsApp.value = null;
}

// ---- Documents ----
const showDocsModal = ref(false);
const docsApp = ref(null);
const userDocuments = ref([]);
const docsLoading = ref(false);

async function openDocuments(rider) {
    docsApp.value = rider;
    showDocsModal.value = true;
    docsLoading.value = true;

    try {
        const { data, error } = await supabase
            .from('documents')
            .select('*')
            .eq('owner_kind', 'profile')
            .eq('profile_id', rider.courier.id)
            .order('created_at', { ascending: false });

        if (error) {
            throw error;
        }

        userDocuments.value = data || [];
    } catch (e) {
        notifyError(e, 'Failed to load documents.');
    } finally {
        docsLoading.value = false;
    }
}
function closeDocuments() {
    showDocsModal.value = false;
    docsApp.value = null;
    userDocuments.value = [];
}

const resumeLoading = ref(false);
async function viewResume(app) {
    resumeLoading.value = true;

    try {
        const response = await logisticsFetch(
            `/api/logistics/applications/${app.id}/resume`,
        );
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Failed to load resume.');
        }

        window.open(payload.url, '_blank', 'noopener');
    } catch (e) {
        notifyError(e, 'Failed to open the resume.');
    } finally {
        resumeLoading.value = false;
    }
}

const licenseLoading = ref(false);
async function viewLicense(app) {
    licenseLoading.value = true;

    try {
        const response = await logisticsFetch(
            `/api/logistics/applications/${app.id}/license`,
        );
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'Failed to load license.');
        }

        window.open(payload.url, '_blank', 'noopener');
    } catch (e) {
        notifyError(e, "Failed to open the driver's license.");
    } finally {
        licenseLoading.value = false;
    }
}

const previewDoc = ref(null);
const previewUrl = ref('');
const previewLoading = ref(false);

async function viewDocument(doc) {
    previewDoc.value = doc;
    previewUrl.value = '';
    previewLoading.value = true;

    try {
        const { data, error } = await supabase.storage
            .from('documents')
            .createSignedUrl(doc.storage_path, 300);

        if (error) {
            throw error;
        }

        previewUrl.value = data.signedUrl;
    } catch (e) {
        notifyError(e, 'Failed to open the document.');
        previewDoc.value = null;
    } finally {
        previewLoading.value = false;
    }
}
function closePreview() {
    previewDoc.value = null;
    previewUrl.value = '';
}

async function load(force = false) {
    refreshing.value = true;

    try {
        await Promise.all([
            loadAcceptedRiders({ search: search.value.trim() }, { force }),
            // Cached app-wide — only powers the "Assigned areas" column.
            loadDeliveryAreas({ force }).catch(() => {}),
        ]);
    } catch (e) {
        notifyError(e, 'Could not load the rider roster.');
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
}

onMounted(() => load());
onActivated(() => load());
</script>

<style scoped>
.details-field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.details-field-grid .full-span {
    grid-column: 1 / -1;
}
.details-field-grid .field-input:disabled {
    cursor: default;
}
.area-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    max-width: 260px;
}
</style>
