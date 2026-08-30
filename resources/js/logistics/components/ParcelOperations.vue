<template>
    <div class="logistics-page">
        <div class="page-header">
            <div>
                <h2 class="page-title">Parcel sorting & rider assignment</h2>
                <p class="page-subtitle">
                    Receive each parcel, confirm its destination area, then hand
                    it to the correct assigned rider.
                </p>
            </div>
            <button class="btn-primary" @click="focusScanner">
                Receive parcel
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
                <h3>Scan tracking number</h3>
                <p class="panel-copy">
                    Scan a parcel label or enter its tracking number to retrieve
                    the delivery address.
                </p>
                <form class="scan-form" @submit.prevent="lookupParcel">
                    <input
                        ref="scanInput"
                        v-model.trim="trackingNumber"
                        class="field-input"
                        placeholder="e.g. NXM-1001"
                        aria-label="Tracking number"
                    />
                    <button class="btn-primary" :disabled="!trackingNumber">
                        Find parcel
                    </button>
                </form>
                <p v-if="lookupMessage" class="lookup-message">
                    {{ lookupMessage }}
                </p>
            </div>
            <div class="card operation-panel area-panel">
                <p class="eyebrow">Routing rule</p>
                <h3>Destination determines the rider</h3>
                <p class="panel-copy">
                    Each delivery area should have one active rider assignment
                    so matching parcels can be routed consistently.
                </p>
                <button class="btn-outline">Manage delivery areas</button>
            </div>
        </section>
        <section class="card parcel-table-card">
            <div class="table-heading">
                <div>
                    <h3>Sorting queue</h3>
                    <p>Parcels waiting for an area or rider assignment.</p>
                </div>
                <span class="badge badge-amber">0 waiting</span>
            </div>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Parcel</th>
                            <th>Delivery address</th>
                            <th>Area</th>
                            <th>Assigned rider</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="empty-box">▣</div>
                                    <strong
                                        >No parcels in the sorting queue</strong
                                    >
                                    <p>
                                        Scan the first parcel to begin sorting.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<script setup>
import { ref } from 'vue';
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
function focusScanner() {
    scanInput.value?.focus();
}
function lookupParcel() {
    lookupMessage.value = `Parcel ${trackingNumber.value} is not yet available in the logistics queue.`;
}
</script>
