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
                <h2 class="page-title">Parcel sorting & rider assignment</h2>
                <p class="page-subtitle">
                    Receive parcels from sellers, match the delivery area, and
                    hand each parcel to the correct rider.
                </p>
            </div>
            <button class="btn-primary" @click="focusScanner">
                Scan parcel
            </button>
        </div>
        <section class="workflow-strip" aria-label="Parcel workflow">
            <div
                v-for="(step, index) in steps"
                :key="step"
                class="workflow-step"
            >
                <span>{{ index + 1 }}</span
                ><strong>{{ step }}</strong>
            </div>
        </section>
        <section class="operations-grid">
            <div class="card operation-panel">
                <p class="eyebrow">Parcel intake</p>
                <h3>Scan tracking or order number</h3>
                <p class="panel-copy">
                    The parcel must already be marked In Transit by the seller.
                </p>
                <form class="scan-form" @submit.prevent="receive">
                    <input
                        ref="scanInput"
                        v-model.trim="trackingNumber"
                        class="field-input"
                        placeholder="Tracking number or SN-12345"
                        aria-label="Tracking or order number"
                    />
                    <button
                        class="btn-primary"
                        :disabled="!trackingNumber || receiving"
                    >
                        {{ receiving ? 'Receiving...' : 'Receive parcel' }}
                    </button>
                </form>
                <p v-if="lookupMessage" class="lookup-message">
                    {{ lookupMessage }}
                </p>
            </div>
            <div class="card operation-panel area-panel">
                <p class="eyebrow">Routing readiness</p>
                <h3>{{ activeAreaCount }} active delivery areas</h3>
                <p class="panel-copy">
                    {{ assignedAreaCount }} areas have an appointed rider.
                    Barangay-specific rules take priority over municipality-wide
                    rules.
                </p>
                <button
                    class="btn-outline"
                    @click="emit('open-section', 'couriers')"
                >
                    Manage delivery areas
                </button>
            </div>
        </section>
        <section class="card parcel-table-card">
            <div class="table-heading">
                <div>
                    <h3>Sorting queue</h3>
                    <p>Receive, route, assign, and hand parcels to riders.</p>
                </div>
                <span class="badge badge-amber"
                    >{{ waitingCount }} waiting</span
                >
            </div>
            <div class="table-scroll">
                <table class="admin-table parcel-queue-table">
                    <thead>
                        <tr>
                            <th>Parcel</th>
                            <th>Delivery address</th>
                            <th>Area</th>
                            <th>Assigned rider</th>
                            <th>Status</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="6" class="py-8">
                                <div class="loading-spinner"></div>
                            </td>
                        </tr>
                        <tr v-else-if="parcelAssignments.length === 0">
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-box">P</div>
                                    <strong
                                        >No parcels in the sorting queue</strong
                                    >
                                    <p>
                                        Scan the first In Transit parcel to
                                        begin sorting.
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="parcel in parcelAssignments"
                            :key="parcel.id"
                        >
                            <td>
                                <strong class="parcel-number">{{
                                    parcel.order.tracking_number ||
                                    parcel.order.order_number
                                }}</strong
                                ><span class="parcel-recipient">{{
                                    parcel.order.recipient_name
                                }}</span>
                            </td>
                            <td>
                                <span class="address-cell">{{
                                    parcel.order.address || 'Address incomplete'
                                }}</span>
                            </td>
                            <td>
                                {{
                                    parcel.delivery_area?.name ||
                                    'Needs sorting'
                                }}
                            </td>
                            <td>
                                {{ riderName(parcel.rider) || 'Unassigned' }}
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="statusClass(parcel.status)"
                                    >{{ statusLabel(parcel.status) }}</span
                                >
                            </td>
                            <td class="text-right">
                                <button
                                    v-if="parcel.status !== 'handed_off'"
                                    class="btn-sm-primary"
                                    @click="openAssignment(parcel)"
                                >
                                    {{
                                        parcel.status === 'assigned'
                                            ? 'Review'
                                            : 'Assign'
                                    }}
                                </button>
                                <span v-else class="handoff-time"
                                    >Completed
                                    {{ formatDate(parcel.handed_off_at) }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-if="showAssignmentModal"
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
                        @click="closeAssignment"
                    >
                        &times;
                    </button>
                </div>
                <div class="parcel-address-card">
                    <span>Delivery address</span
                    ><strong>{{
                        selectedParcel.order.address || 'Address incomplete'
                    }}</strong>
                </div>
                <div class="area-form-grid">
                    <label class="form-field full-field"
                        ><span>Delivery area</span
                        ><select
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
                        </select></label
                    >
                    <label class="form-field full-field"
                        ><span>Assigned rider</span
                        ><select
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
                                {{ riderName(rider) }}
                            </option>
                        </select></label
                    >
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
                        class="btn-primary handoff-button"
                        :disabled="saving"
                        @click="handoff"
                    >
                        Confirm rider handoff
                    </button>
                    <button v-else class="btn-primary" :disabled="saving">
                        {{ saving ? 'Assigning...' : 'Assign parcel' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const emit = defineEmits(['open-section']);
const {
    parcelAssignments,
    deliveryAreas,
    areaRiders,
    loadParcelAssignments,
    loadDeliveryAreas,
    receiveParcel,
    assignParcel,
    handoffParcel,
} = useLogistics();
const steps = [
    'Receive',
    'Scan',
    'Read address',
    'Determine area',
    'Assign rider',
    'Hand off',
];
const trackingNumber = ref('');
const lookupMessage = ref('');
const scanInput = ref(null);
const loading = ref(true);
const receiving = ref(false);
const saving = ref(false);
const showAssignmentModal = ref(false);
const selectedParcel = ref(null);
const assignmentError = ref('');
const toasts = ref([]);
const assignmentForm = reactive({ delivery_area_id: '', rider_profile_id: '' });
const activeAreas = computed(() =>
    deliveryAreas.value.filter((area) => area.is_active),
);
const activeAreaCount = computed(() => activeAreas.value.length);
const assignedAreaCount = computed(
    () => activeAreas.value.filter((area) => area.rider).length,
);
const waitingCount = computed(
    () =>
        parcelAssignments.value.filter(
            (parcel) => parcel.status !== 'handed_off',
        ).length,
);

function focusScanner() {
    scanInput.value?.focus();
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
        ? `${rider.first_name || ''} ${rider.last_name || ''}`.trim()
        : '';
}
function formatCoverage(area) {
    return [area.barangay, area.municipality_name, area.province_name]
        .filter(Boolean)
        .join(', ');
}
function statusLabel(status) {
    return (
        {
            received: 'Needs sorting',
            sorted: 'Area matched',
            assigned: 'Ready for handoff',
            handed_off: 'Handed off',
        }[status] || status
    );
}
function statusClass(status) {
    return {
        received: 'badge-amber',
        sorted: 'badge-indigo',
        assigned: 'badge-teal',
        handed_off: 'badge-slate',
    }[status];
}
function formatDate(value) {
    return value
        ? new Date(value).toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
          })
        : '';
}
function openAssignment(parcel) {
    selectedParcel.value = parcel;
    assignmentForm.delivery_area_id = parcel.delivery_area?.id || '';
    assignmentForm.rider_profile_id = parcel.rider?.id || '';
    assignmentError.value = '';
    showAssignmentModal.value = true;
}
function closeAssignment() {
    showAssignmentModal.value = false;
    selectedParcel.value = null;
    assignmentError.value = '';
}
function selectAreaRider() {
    const area = deliveryAreas.value.find(
        (item) => item.id === assignmentForm.delivery_area_id,
    );
    if (area?.rider?.id) assignmentForm.rider_profile_id = area.rider.id;
}
async function receive() {
    receiving.value = true;
    lookupMessage.value = '';
    try {
        const parcel = await receiveParcel(trackingNumber.value);
        lookupMessage.value = parcel.delivery_area
            ? `${parcel.delivery_area.name} matched. Review and confirm the rider.`
            : 'Parcel received. No delivery area matched; assign it manually.';
        trackingNumber.value = '';
        openAssignment(parcel);
    } catch (error) {
        lookupMessage.value = error.message;
        notify(error.message, 'error');
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
onMounted(async () => {
    try {
        await Promise.all([loadParcelAssignments(), loadDeliveryAreas()]);
    } catch (error) {
        notify(error.message, 'error');
    } finally {
        loading.value = false;
    }
});
</script>
