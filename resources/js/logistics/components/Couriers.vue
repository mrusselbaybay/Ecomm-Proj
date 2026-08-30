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
                        <div class="avatar">
                            {{ riderInitials(area.rider) }}
                        </div>
                        <div v-if="area.rider">
                            <strong>{{ riderName(area.rider) }}</strong
                            ><span>Assigned rider</span>
                        </div>
                        <div v-else>
                            <strong>No rider assigned</strong
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
                <p class="modal-desc">
                    Use the parcel's province, municipality, and optional
                    barangay to determine its destination area.
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
                        ><input
                            v-model.trim="form.province_name"
                            class="field-input"
                            placeholder="Laguna"
                            required
                            maxlength="150"
                    /></label>
                    <label class="form-field"
                        ><span>Municipality / City</span
                        ><input
                            v-model.trim="form.municipality_name"
                            class="field-input"
                            placeholder="Santa Cruz"
                            required
                            maxlength="150"
                    /></label>
                    <label class="form-field full-field"
                        ><span>Barangay <small>Optional</small></span
                        ><input
                            v-model.trim="form.barangay"
                            class="field-input"
                            placeholder="Leave blank to cover the whole municipality"
                            maxlength="150"
                    /></label>
                    <label class="form-field full-field"
                        ><span>Assigned rider <small>Optional</small></span
                        ><select
                            v-model="form.rider_profile_id"
                            class="field-input"
                        >
                            <option :value="null">Unassigned</option>
                            <option
                                v-for="rider in areaRiders"
                                :key="rider.id"
                                :value="rider.id"
                            >
                                {{ riderName(rider) }}
                            </option>
                        </select></label
                    >
                    <label class="status-toggle full-field"
                        ><input v-model="form.is_active" type="checkbox" /><span
                            ><strong>Area is active</strong
                            ><small
                                >Active areas can receive automatic parcel
                                matches.</small
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
                        Cancel
                    </button>
                    <button class="btn-primary" :disabled="saving">
                        {{ saving ? 'Saving...' : 'Save area' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const {
    deliveryAreas,
    areaRiders,
    loadDeliveryAreas,
    saveDeliveryArea,
    deleteDeliveryArea,
} = useLogistics();
const loading = ref(true);
const saving = ref(false);
const showAreaModal = ref(false);
const editingAreaId = ref(null);
const formError = ref('');
const toasts = ref([]);
const form = reactive({
    name: '',
    province_name: '',
    municipality_name: '',
    barangay: '',
    rider_profile_id: null,
    is_active: true,
});
const assignedAreaCount = computed(
    () => deliveryAreas.value.filter((area) => area.rider).length,
);
const unassignedAreaCount = computed(
    () => deliveryAreas.value.length - assignedAreaCount.value,
);

function resetForm() {
    Object.assign(form, {
        name: '',
        province_name: '',
        municipality_name: '',
        barangay: '',
        rider_profile_id: null,
        is_active: true,
    });
}
function openAreaModal(area = null) {
    resetForm();
    editingAreaId.value = area?.id || null;
    if (area)
        Object.assign(form, {
            name: area.name,
            province_name: area.province_name,
            municipality_name: area.municipality_name,
            barangay: area.barangay || '',
            rider_profile_id: area.rider?.id || null,
            is_active: area.is_active,
        });
    formError.value = '';
    showAreaModal.value = true;
}
function closeAreaModal() {
    showAreaModal.value = false;
    editingAreaId.value = null;
    formError.value = '';
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
    return [area.barangay, area.municipality_name, area.province_name]
        .filter(Boolean)
        .join(', ');
}
function assignedAreasFor(riderId) {
    const count = deliveryAreas.value.filter(
        (area) => area.rider?.id === riderId,
    ).length;
    return `${count} ${count === 1 ? 'area' : 'areas'}`;
}
async function submitArea() {
    saving.value = true;
    formError.value = '';
    try {
        await saveDeliveryArea(
            { ...form, barangay: form.barangay || null },
            editingAreaId.value,
        );
        notify(
            editingAreaId.value
                ? 'Delivery area updated.'
                : 'Delivery area created.',
        );
        closeAreaModal();
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
    try {
        await loadDeliveryAreas();
    } catch (error) {
        notify(error.message, 'error');
    } finally {
        loading.value = false;
    }
});
</script>
