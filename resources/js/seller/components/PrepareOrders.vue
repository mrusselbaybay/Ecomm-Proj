<!-- resources/js/seller/components/PrepareOrders.vue -->
<template>
    <!-- ================================================================
     QUEUE — always the backdrop page now (see the action modal below).
     Landing here without a specific order shows this list; picking one
     opens the modal on top of it instead of replacing it, so Cancel/
     Mark Ready for Pickup always return to a real, current list instead
     of a full-page dead end.
     ================================================================ -->
    <div class="prep-picker" :aria-hidden="modalOpen">
        <div class="topbar">
            <div>
                <h1 class="page-title">Prepare Orders</h1>
                <p class="page-sub">Pack and dispatch every order you have accepted.</p>
            </div>
        </div>

        <div v-if="isLoadingOrders" class="placeholder-page">
            <div class="loading-spinner"></div>
            <p style="margin-top: 1rem">Loading your orders…</p>
        </div>

        <div v-else-if="prepQueueOrders.length === 0" class="placeholder-page">
            <div class="icon-wrap">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                    <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                </svg>
            </div>
            <h3>Nothing to prepare right now</h3>
            <p>
                Orders show up here once you've accepted them and they're
                waiting to be packed and shipped.
            </p>
            <button class="btn-outline" style="margin-top: 1.25rem" @click="goTo('orders')">
                Go to Orders
            </button>
        </div>

        <template v-else>
            <div class="controls-row">
                <div class="po-tabs">
                    <button
                        v-for="tab in STAGE_TABS"
                        :key="tab.key"
                        type="button"
                        class="po-tab"
                        :class="{ active: activeStage === tab.key }"
                        @click="activeStage = tab.key"
                    >
                        {{ tab.label }}
                    </button>
                </div>
                <div class="ctrl-right">
                    <div class="search">
                        <span class="ic">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.35-4.35" /></svg>
                        </span>
                        <input type="text" v-model="pickerSearch" placeholder="Search orders…" />
                    </div>
                </div>
            </div>

            <p v-if="!filteredPickerOrders.length" class="prep-picker-empty">
                No orders in this view.
            </p>

            <div v-else class="po-grid">
                <div v-for="o in pagedPickerOrders" :key="o.id" class="po-card">
                    <div class="po-card-top">
                        <div class="po-cust">
                            <span class="po-cust-avatar">{{ customerInitials(o.customer) }}</span>
                            <div>
                                <p class="po-cust-name">{{ o.customer || 'Unknown buyer' }}</p>
                                <p class="po-cust-meta">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 9 5-9 5-9-5 9-5Z" /><path d="m3 12 9 5 9-5" /></svg>
                                    Placed {{ placedAgo(o.placedAt) }}
                                </p>
                            </div>
                        </div>
                        <span class="po-status-pill" :class="STAGE_PILL[o._stage].cls">
                            <span class="dot"></span>{{ STAGE_PILL[o._stage].label }}
                        </span>
                    </div>

                    <div class="po-meta-row">
                        <span>Order <span class="val">{{ o.id }}</span></span>
                        <span v-if="o.shippingService">
                            Service <span class="val tag" :class="o.shippingService === 'Standard' ? 'neutral' : o.shippingService === 'Express' ? 'blue' : 'bad'">{{ o.shippingService }}</span>
                        </span>
                    </div>

                    <div class="po-items">
                        <div v-for="(item, idx) in o.items" :key="idx" class="po-item-line">
                            <span class="po-item-qty">{{ item.qty }}</span>
                            <span class="nm">{{ item.name }}</span>
                            <span class="pr num">{{ formatCurrency(item.subtotal ?? item.price * item.qty) }}</span>
                        </div>
                    </div>

                    <div class="po-total-row">
                        Total <span class="amt num">{{ formatCurrency(o.total) }}</span>
                    </div>

                    <div v-if="o._actionable" class="po-card-actions">
                        <button type="button" class="po-btn-primary" @click="selectOrder(o.id)">
                            {{ pickerCta(o) }}
                        </button>
                    </div>
                    <p v-else class="po-card-note">
                        {{ nonActionableNote(o) }}
                    </p>
                </div>
            </div>

            <div v-if="pickerLastPage > 1" class="po-pagination">
                <span>Showing {{ pickerRangeLabel }} of {{ filteredPickerOrders.length }} orders</span>
                <div class="po-page-nav">
                    <button
                        type="button"
                        class="po-page-btn"
                        :disabled="pickerPage <= 1"
                        aria-label="Previous page"
                        @click="pickerPage--"
                    >
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 5 8 12l7 7" /></svg>
                    </button>
                    <button
                        v-for="p in pickerLastPage"
                        :key="p"
                        type="button"
                        class="po-page-btn"
                        :class="{ active: p === pickerPage }"
                        @click="pickerPage = p"
                    >
                        {{ p }}
                    </button>
                    <button
                        type="button"
                        class="po-page-btn"
                        :disabled="pickerPage >= pickerLastPage"
                        aria-label="Next page"
                        @click="pickerPage++"
                    >
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 5l7 7-7 7" /></svg>
                    </button>
                </div>
            </div>
        </template>
    </div>

    <!-- ================================================================
     PREPARE SHIPMENT — action modal, adapted from the reference's
     .psm-overlay/.psm-modal: a 3-step flow (Shipping Details -> Pack
     Items -> Ready for Pickup) with a live shipment summary panel.
     Reuses the app's existing .modal-overlay/.modal-panel primitives
     (same ones Inventory/Feedback/Messages already use) instead of a
     one-off overlay, and every field either writes to a real order
     field (courier, service tier) or is clearly a device-only draft
     (weight/size, packing checklist) — see the draftKey comment below
     for why the latter has no backend column.

     This modal only ever takes the order as far as "Ready for Pickup"
     (Processing -> Packed -> Ready for Pickup, the two real hops
     Order::ALLOWED_TRANSITIONS actually allows) — it does NOT jump
     straight to "In Transit". A normal fulfilment flow doesn't let a
     seller declare an order shipped from their own packing desk; the
     order becomes In Transit only once Courier Handover's own Confirm
     Pickup fires, which is also where the real tracking number gets
     attached (the seller packing a box doesn't know the AWB number
     until the courier actually scans it).
     ================================================================ -->
    <Transition name="modal-fade">
        <div v-if="modalOpen" class="modal-overlay" @click.self="closeModal">
            <div
                ref="panelRef"
                class="modal-panel psm-panel"
                :class="{ 'psm-panel-simple': isLoading || !order || !canPrepare }"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="psmTitleId"
                @keydown.esc.stop.prevent="closeModal"
            >
                <!-- Loading the targeted order -->
                <template v-if="isLoading">
                    <div class="modal-header">
                        <h3 :id="psmTitleId">Loading order…</h3>
                        <button type="button" class="modal-close" aria-label="Close" @click="closeModal">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                        </button>
                    </div>
                    <div class="placeholder-page" style="padding: 1.5rem 0 0.5rem">
                        <div class="loading-spinner"></div>
                    </div>
                </template>

                <!-- Order couldn't be loaded -->
                <template v-else-if="!order">
                    <div class="modal-header">
                        <h3 :id="psmTitleId">Order Not Found</h3>
                        <button type="button" class="modal-close" aria-label="Close" @click="closeModal">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                        </button>
                    </div>
                    <div class="placeholder-page">
                        <div class="icon-wrap">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M9.5 9.5 14.5 14.5M14.5 9.5 9.5 14.5" />
                            </svg>
                        </div>
                        <p>
                            That order couldn't be loaded. It may have been
                            removed, or the link may be out of date.
                        </p>
                        <button class="btn-outline" style="margin-top: 1.25rem" @click="closeModal">
                            Close
                        </button>
                    </div>
                </template>

                <!-- Found, but not in a packable status yet -->
                <template v-else-if="!canPrepare">
                    <div class="modal-header">
                        <h3 :id="psmTitleId">{{ order.id }} Isn't Ready to Prepare</h3>
                        <button type="button" class="modal-close" aria-label="Close" @click="closeModal">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                        </button>
                    </div>
                    <div class="placeholder-page">
                        <div class="icon-wrap" style="background: #fffbeb; color: #d97706">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 9v4M12 17h.01" />
                                <circle cx="12" cy="12" r="9" />
                            </svg>
                        </div>
                        <template v-if="order.status === 'Confirmed'">
                            <p>
                                This order is
                                <span class="badge" :class="statusBadgeClass(order.status)">{{ order.status }}</span>
                                — start processing it to begin packing.
                            </p>
                            <p v-if="updateError" class="save-msg error">{{ updateError }}</p>
                            <button
                                class="btn-primary"
                                style="margin-top: 1.25rem"
                                :disabled="isStartingProcessing"
                                @click="startProcessingThisOrder"
                            >
                                {{ isStartingProcessing ? 'Starting…' : 'Start Processing' }}
                            </button>
                        </template>
                        <template v-else>
                            <p>
                                This order is currently
                                <span class="badge" :class="statusBadgeClass(order.status)">{{ order.status }}</span>
                                — shipments can only be prepared for orders
                                you've already accepted ("Processing").
                            </p>
                            <button class="btn-outline" style="margin-top: 1.25rem" @click="goToOrderDetails">
                                View Order Details
                            </button>
                        </template>
                    </div>
                </template>

                <!-- Real 3-step prepare-shipment flow -->
                <template v-else>
                    <div class="psm-head">
                        <div class="psm-head-icon">
                            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                                <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                                <path d="M10 10v7.5" />
                            </svg>
                        </div>
                        <div class="psm-head-text">
                            <h3 :id="psmTitleId">Prepare Shipment</h3>
                            <p>{{ order.id }} · {{ order.customer || 'Unknown buyer' }}</p>
                        </div>
                        <span class="badge" :class="statusBadgeClass(order.status)">{{ order.statusLabel || order.status }}</span>
                        <button type="button" class="modal-close" aria-label="Close" @click="closeModal">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                        </button>
                    </div>

                    <div class="psm-steps-wrap">
                        <button
                            v-for="step in PSM_STEPS"
                            :key="step.n"
                            type="button"
                            class="psm-step"
                            :class="{ active: activePsmStep === step.n, done: activePsmStep > step.n }"
                            :disabled="step.n > maxPsmStep"
                            @click="goToPsmStep(step.n)"
                        >
                            <span class="psm-step-dot">{{ activePsmStep > step.n ? '✓' : step.n }}</span>
                            <span class="psm-step-label">{{ step.label }}</span>
                        </button>
                    </div>

                    <p v-if="updateError" class="save-msg error" style="margin: 0.9rem 1.5rem 0">{{ updateError }}</p>

                    <div class="psm-body">
                        <div class="psm-form-col">
                            <!-- Step 1: Shipping Details -->
                            <div v-show="activePsmStep === 1" class="card">
                                <div class="prep-card-head">
                                    <div>
                                        <h3>Shipping Details</h3>
                                        <p class="prep-card-sub">Enter the package and courier details for this shipment.</p>
                                    </div>
                                </div>
                                <div class="prep-form">
                                    <div class="sheet-field-row">
                                        <div>
                                            <label class="field-label">Courier / Carrier</label>
                                            <select class="field-input" v-model="shippingCarrier" :disabled="isLoadingCouriers">
                                                <option value="">{{ courierSelectPlaceholder }}</option>
                                                <option v-for="c in couriers" :key="c.id" :value="c.name">{{ c.name }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="field-label">Service Tier</label>
                                            <select class="field-input" v-model="shippingService">
                                                <option value="Standard">Standard</option>
                                                <option value="Express">Express</option>
                                                <option value="Same-Day">Same-Day</option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="field-hint">
                                        Saved to this order once you mark it ready for
                                        pickup — Courier Handover confirms the actual
                                        pickup and hand-off from there.
                                    </p>

                                    <div class="sheet-field-row">
                                        <div>
                                            <label class="field-label">Package Weight (kg)</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                class="field-input"
                                                v-model.number="packageWeight"
                                                placeholder="0.00"
                                            />
                                        </div>
                                        <div>
                                            <label class="field-label">Package Size</label>
                                            <select class="field-input" v-model="packageSize">
                                                <option value="">Select a size…</option>
                                                <option value="Small">Small</option>
                                                <option value="Medium">Medium</option>
                                                <option value="Large">Large</option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="field-hint">
                                        Kept on this device only as a packing note —
                                        there's no order field for package weight/size yet,
                                        so it isn't sent anywhere when you dispatch.
                                    </p>
                                </div>
                            </div>

                            <!-- Step 2: Pack Items -->
                            <template v-if="activePsmStep === 2">
                                <div class="card">
                                    <div class="prep-card-head">
                                        <div class="flex items-center gap-3">
                                            <div class="prep-icon-badge">
                                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                                                    <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                                                    <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                                                    <path d="M10 10v7.5" />
                                                </svg>
                                            </div>
                                            <h3>Items to Pack</h3>
                                        </div>
                                        <span class="prep-progress-label">{{ packedCount }} / {{ totalItems }} packed</span>
                                    </div>

                                    <div class="prep-progress-track">
                                        <div class="prep-progress-fill" :style="{ width: packProgressPct + '%' }"></div>
                                    </div>

                                    <div class="prep-item-list">
                                        <div
                                            v-for="(item, idx) in order.items"
                                            :key="idx"
                                            class="prep-item-row"
                                            :class="{ packed: packedState[idx] }"
                                        >
                                            <div class="prep-item-icon">
                                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                                                    <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                                                    <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                                                </svg>
                                            </div>
                                            <div class="prep-item-info">
                                                <p v-if="item.sku" class="prep-item-sku">SKU: {{ item.sku }}</p>
                                                <h4 class="prep-item-name">{{ item.name }}</h4>
                                                <p v-if="item.variant" class="prep-item-variant">{{ item.variant }}</p>
                                            </div>
                                            <div class="prep-item-actions">
                                                <span class="prep-item-qty">Qty: {{ item.qty }}</span>
                                                <label class="prep-packed-toggle">
                                                    <input
                                                        type="checkbox"
                                                        :checked="!!packedState[idx]"
                                                        @change="togglePacked(idx)"
                                                    />
                                                    <span>{{ packedState[idx] ? 'Packed' : 'Pending' }}</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="prep-card-head" style="border-bottom: none; padding-bottom: 0">
                                        <h3>Packing Tips</h3>
                                    </div>
                                    <div class="prep-tips-grid">
                                        <div class="prep-tip">
                                            <span class="prep-tip-num">1</span>
                                            <p>Use a box sized close to the items — extra empty space means more movement in transit.</p>
                                        </div>
                                        <div class="prep-tip">
                                            <span class="prep-tip-num">2</span>
                                            <p>Wrap fragile or electronic items individually before boxing them together.</p>
                                        </div>
                                        <div class="prep-tip">
                                            <span class="prep-tip-num">3</span>
                                            <p>Double-check quantities against this checklist before sealing the box.</p>
                                        </div>
                                        <div class="prep-tip">
                                            <span class="prep-tip-num">4</span>
                                            <p>Seal all edges securely and keep the tracking number visible on the label.</p>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- Step 3: Ready for Pickup -->
                            <div v-show="activePsmStep === 3" class="card">
                                <div class="prep-card-head">
                                    <div>
                                        <h3>Ready for Pickup</h3>
                                        <p class="prep-card-sub">Review the shipment before it waits for the courier.</p>
                                    </div>
                                </div>
                                <p class="prep-dispatch-note">
                                    Confirming this moves the order to
                                    <strong>Ready for Pickup</strong> and lists it on
                                    Courier Handover — it only becomes
                                    <strong>In Transit</strong> once you actually confirm
                                    the courier picked it up there (that's also where the
                                    tracking number gets attached).
                                </p>
                                <p v-if="!allPacked" class="prep-dispatch-warning">
                                    {{ totalItems - packedCount }} item(s) still marked as
                                    pending — you can still continue, but double-check
                                    your box first.
                                </p>
                            </div>
                        </div>

                        <div class="psm-summary-col">
                            <div class="psm-summary-head">
                                <span class="psm-summary-avatar">{{ customerInitials(order.customer) }}</span>
                                <div>
                                    <h4>{{ order.customer || 'Unknown buyer' }}</h4>
                                    <p>{{ order.id }}</p>
                                </div>
                            </div>

                            <div>
                                <p class="psm-summary-title">Shipment</p>
                                <div class="psm-summary-row">
                                    <span class="lbl">Courier</span>
                                    <span class="val">{{ shippingCarrier.trim() || 'Not set yet' }}</span>
                                </div>
                                <div class="psm-summary-row">
                                    <span class="lbl">Service</span>
                                    <span class="val">{{ shippingService }}</span>
                                </div>
                                <div class="psm-summary-row">
                                    <span class="lbl">Weight</span>
                                    <span class="val">{{ packageWeight ? `${packageWeight} kg` : '—' }}</span>
                                </div>
                                <div class="psm-summary-row">
                                    <span class="lbl">Size</span>
                                    <span class="val">{{ packageSize || '—' }}</span>
                                </div>
                            </div>

                            <div>
                                <p class="psm-summary-title">Order</p>
                                <div class="psm-summary-row">
                                    <span class="lbl">Payment</span>
                                    <span class="val">{{ order.paymentMethod || '—' }} ({{ order.paymentStatus || '—' }})</span>
                                </div>
                                <div class="psm-summary-row">
                                    <span class="lbl">Ship to</span>
                                    <span class="val">{{ formatAddress(order.address) }}</span>
                                </div>
                            </div>

                            <div>
                                <p class="psm-summary-title">Products ({{ totalItems }})</p>
                                <div class="psm-summary-items">
                                    <div
                                        v-for="(item, idx) in order.items"
                                        :key="idx"
                                        class="psm-summary-item"
                                        :class="{ packed: packedState[idx] }"
                                    >
                                        <span class="chk">✓</span>
                                        <span class="nm">{{ item.qty }}× {{ item.name }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="psm-summary-total">
                                Total <span class="amt num">{{ formatCurrency(order.total) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="psm-foot">
                        <div class="psm-foot-left">
                            <button type="button" class="btn-outline" @click="closeModal">Cancel</button>
                            <button type="button" class="psm-save-draft" @click="saveDraft">
                                {{ draftSavedAt ? 'Draft Saved ✓' : 'Save Draft' }}
                            </button>
                        </div>
                        <div class="psm-foot-right">
                            <button v-if="activePsmStep > 1" type="button" class="btn-outline" @click="prevPsmStep">
                                Back
                            </button>
                            <button v-if="activePsmStep < 3" type="button" class="btn-primary" @click="nextPsmStep">
                                Next
                            </button>
                            <button
                                v-else
                                type="button"
                                class="btn-primary"
                                :disabled="!canConfirmReady"
                                @click="confirmReadyForPickup"
                            >
                                {{ isUpdatingStatus ? 'Marking Ready…' : 'Mark Ready for Pickup' }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </Transition>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { useCouriers } from '../composables/useCouriers';
import { useOrders } from '../composables/useOrders';

const props = defineProps({
    orderId: { type: String, default: null },
});

const {
    orders,
    isLoadingOrders,
    loadOrders,
    getOrderById,
    statusBadgeClass,
    formatCurrency,
    isUpdatingStatus,
    updateError,
    updateOrderStatus,
} = useOrders();

const { couriers, isLoadingCouriers, loadCouriers } = useCouriers();

const courierSelectPlaceholder = computed(() => {
    if (isLoadingCouriers.value) {
return 'Loading couriers…';
}

    if (!couriers.value.length) {
return 'No couriers registered yet';
}

    return 'Select a courier…';
});

const isLoading = ref(true);
const order = ref(null);

const packedState = ref({}); // { [itemIndex]: boolean } — local packing checklist
const shippingCarrier = ref('');
const shippingService = ref('Standard');
const packageWeight = ref(null);
const packageSize = ref(''); // 'Small' | 'Medium' | 'Large' | ''
const draftSavedAt = ref(null);

const modalOpen = computed(() => !!props.orderId);

async function loadOrder() {
    // The queue behind the modal is loaded regardless of whether a
    // specific order was targeted — it's the real page now, not just a
    // fallback for when no order is selected.
    if (!orders.value.length) {
        loadOrders();
    }

    if (!props.orderId) {
        order.value = null;
        isLoading.value = false;

        return;
    }

    isLoading.value = true;
    resetPsmSteps();

    // getOrderById() returns { order, notFound, error } (see its own
    // doc comment in useOrders.js) — assigning the whole thing here
    // instead of unwrapping .order would leave every field the wizard
    // reads (order.value.items, .status, .customer, ...) undefined,
    // since they'd actually sit one level deeper.
    const { order: fetched } = await getOrderById(props.orderId);
    order.value = fetched;

    if (order.value) {
        packedState.value = Object.fromEntries(
            order.value.items.map((_, idx) => [idx, false]),
        );
        draftSavedAt.value = null;
        loadDraft();
    }

    isLoading.value = false;
}

onMounted(() => {
    loadOrder();
    loadCouriers();
});
watch(() => props.orderId, loadOrder);

// Keeps the packing queue current without a manual refresh (same 30s
// rhythm as Orders.vue/Dashboard.vue's poll) — only re-pulls the list
// behind the modal, not the modal's own order (that stays in sync via
// the orderId watch above, so an in-progress packing checklist is never
// clobbered mid-edit).
const ORDERS_POLL_MS = 30 * 1000;
let ordersPollTimer = null;

onMounted(() => {
    ordersPollTimer = setInterval(() => loadOrders(), ORDERS_POLL_MS);
});

onBeforeUnmount(() => {
    clearInterval(ordersPollTimer);
});

// Orders a seller has already accepted and can pack/dispatch. Includes
// 'Confirmed' too — a seller who's accepted an order but hasn't started
// processing it yet still belongs on this queue (matches the Orders
// kanban's Processing column, which groups Confirmed the same way);
// its card just offers "Start Processing" instead of a packing action
// (see stageOf()'s 'accepted' stage below). Plus the already-shipped/
// cancelled statuses so the Dispatched/Cancelled tabs have something
// real to show instead of always being empty.
const prepQueueOrders = computed(() =>
    orders.value.filter((o) =>
        [
            'Confirmed',
            'Processing',
            'Packed',
            'Ready for Pickup',
            'In Transit',
            'Delivered',
            'Cancelled',
            'Rejected',
        ].includes(o.status),
    ),
);

// Real per-order packing progress, read from the same localStorage draft
// loadDraft()/saveDraft() below use — there's no backend field for this,
// but it's real device-local state, not a fabricated status.
function peekDraft(orderId) {
    try {
        const raw = window.localStorage.getItem(`nexmart:prepare-draft:${orderId}`);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function stageOf(o) {
    if (['Cancelled', 'Rejected'].includes(o.status)) {
        return 'cancelled';
    }

    if (['In Transit', 'Delivered'].includes(o.status)) {
        return 'dispatched';
    }

    // Confirmed but not yet Processing — nothing to pack yet, so this
    // is its own real stage rather than lumped in with 'started' (which
    // means "Processing, actively being packed — including a checklist
    // that hasn't had a box ticked yet"). Its card offers "Start
    // Processing" instead of a packing CTA.
    if (o.status === 'Confirmed') {
        return 'accepted';
    }

    // Packed / Ready for Pickup: the order has already moved past
    // whatever Prepare Orders can do for it — its modal only re-opens
    // the packing wizard for 'Processing' (see canPrepare) — and the
    // real remaining action (mark ready for pickup / confirm the
    // courier pickup) lives on Courier Handover now. This has to be
    // checked before the packing-draft peek below: once an order is
    // actually submitted, clearDraft() wipes its local draft, so
    // without this check a Packed/Ready-for-Pickup order would read as
    // packed === 0 and wrongly come back 'started' — offering a
    // "Start Packing" button that reopens the modal only to hit its
    // "isn't ready to prepare" dead end.
    if (['Packed', 'Ready for Pickup'].includes(o.status)) {
        return 'ready';
    }

    // Only 'Processing' reaches here. There's no separate "not started"
    // stage — the moment an order is Processing, the seller has already
    // begun fulfilling it (and the wizard's own Save Draft lets them
    // persist progress from the very first click), so a checklist with
    // zero boxes ticked yet is still 'started', just at 0%. Only fully
    // packed graduates it to 'ready'.
    const draft = peekDraft(o.id);
    const total = o.items?.length || 0;
    const packed = draft?.packedState
        ? Object.values(draft.packedState).filter(Boolean).length
        : 0;

    return packed >= total ? 'ready' : 'started';
}

const STAGE_TABS = [
    { key: 'all', label: 'All' },
    { key: 'ready', label: 'Ready to Ship' },
    { key: 'started', label: 'Started' },
    { key: 'accepted', label: 'Awaiting Processing' },
    { key: 'dispatched', label: 'Dispatched' },
    { key: 'cancelled', label: 'Cancelled' },
];

const STAGE_PILL = {
    ready: { cls: 'good', label: 'Ready to Ship' },
    started: { cls: 'warn', label: 'Started' },
    accepted: { cls: 'blue', label: 'Awaiting Processing' },
    dispatched: { cls: 'blue', label: 'Dispatched' },
    cancelled: { cls: 'bad', label: 'Cancelled' },
};

const STAGE_CTA = {
    accepted: 'Start Processing',
    ready: 'Review & Confirm',
};

// 'started' covers both "just moved to Processing, checklist untouched"
// and "partway through packing" — the CTA copy still tells them apart
// even though the queue no longer splits them into separate tabs.
function pickerCta(o) {
    if (o._stage !== 'started') {
        return STAGE_CTA[o._stage];
    }

    const draft = peekDraft(o.id);
    const packed = draft?.packedState
        ? Object.values(draft.packedState).filter(Boolean).length
        : 0;

    return packed > 0 ? 'Continue Packing' : 'Start Packing';
}

const activeStage = ref('all');
const pickerSearch = ref('');

// Most-recently-touched first, using the same real `updatedAt` field
// CourierHandover already treats as "last status change" — a seller who
// just confirmed an order (or advanced it another step) sees it surface
// to the top of the queue immediately instead of hunting for it wherever
// the API's default order happened to place it.
const stagedOrders = computed(() =>
    prepQueueOrders.value
        .map((o) => ({
            ...o,
            _stage: stageOf(o),
            // A 'ready' stage covers two different real meanings (see
            // stageOf()'s comment) — one still actionable here, one not
            // — so whether the card's button shows is decided from the
            // real status directly, not just the stage bucket.
            _actionable: ['Confirmed', 'Processing'].includes(o.status),
        }))
        .sort(
            (a, b) =>
                new Date(b.updatedAt || b.placedAt || 0) - new Date(a.updatedAt || a.placedAt || 0),
        ),
);

function nonActionableNote(o) {
    if (o._stage === 'cancelled') {
        return 'This order was cancelled before packing began.';
    }

    if (o._stage === 'dispatched') {
        return 'Order has been dispatched.';
    }

    if (o.status === 'Packed') {
        return 'Packed — mark it ready for pickup on Courier Handover.';
    }

    if (o.status === 'Ready for Pickup') {
        return 'Ready for pickup — confirm the hand-off on Courier Handover.';
    }

    return '';
}

const filteredPickerOrders = computed(() => {
    const q = pickerSearch.value.trim().toLowerCase();

    return stagedOrders.value.filter((o) => {
        if (activeStage.value !== 'all' && o._stage !== activeStage.value) {
            return false;
        }

        if (q && !`${o.id} ${o.customer || ''}`.toLowerCase().includes(q)) {
            return false;
        }

        return true;
    });
});

const PICKER_PAGE_SIZE = 9;
const pickerPage = ref(1);

watch(filteredPickerOrders, () => {
    pickerPage.value = 1;
});

const pickerLastPage = computed(() =>
    Math.max(1, Math.ceil(filteredPickerOrders.value.length / PICKER_PAGE_SIZE)),
);

const pagedPickerOrders = computed(() => {
    const start = (pickerPage.value - 1) * PICKER_PAGE_SIZE;

    return filteredPickerOrders.value.slice(start, start + PICKER_PAGE_SIZE);
});

const pickerRangeLabel = computed(() => {
    if (!filteredPickerOrders.value.length) {
        return '0';
    }

    const start = (pickerPage.value - 1) * PICKER_PAGE_SIZE + 1;
    const end = Math.min(pickerPage.value * PICKER_PAGE_SIZE, filteredPickerOrders.value.length);

    return `${start} to ${end}`;
});

function customerInitials(name) {
    if (!name) {
        return '?';
    }

    const parts = name.trim().split(/\s+/);

    return parts.length === 1
        ? parts[0].slice(0, 2).toUpperCase()
        : (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function placedAgo(iso) {
    if (!iso) {
        return 'recently';
    }

    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);

    if (days <= 0) {
        return 'today';
    }

    return days === 1 ? '1 day ago' : `${days} days ago`;
}

// Opens the action modal on top of the queue (sets props.orderId via
// SellerLayout's seller-nav handler — see closeModal() for the reverse).
function selectOrder(id) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'prepareOrders', orderId: id },
        }),
    );
}

function closeModal() {
    // Don't let Esc/backdrop-click/Cancel drop an in-flight request —
    // mirrors ConfirmActionDialog's same guard.
    if (isUpdatingStatus.value || isStartingProcessing.value) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'prepareOrders', orderId: null },
        }),
    );
}

const canPrepare = computed(() => order.value && order.value.status === 'Processing');

// A seller lands here right after confirming an order on Order Details
// (which now only ever offers "Confirm Order" — see OrderDetails.vue).
// Rather than sending them back to Order Details to start processing
// and then back here again, the "not ready yet" panel above offers the
// real next step directly when the order is 'Confirmed'.
const isStartingProcessing = ref(false);

async function startProcessingThisOrder() {
    if (!order.value || isStartingProcessing.value) {
        return;
    }

    isStartingProcessing.value = true;

    try {
        const updated = await updateOrderStatus(order.value.id, 'Processing');

        if (updated) {
            order.value = updated;
        }
    } catch {
        // updateError already holds a message for the template to show.
    } finally {
        isStartingProcessing.value = false;
    }
}

// ---- Prepare Shipment modal: 3-step flow ----
const PSM_STEPS = [
    { n: 1, label: 'Shipping Details' },
    { n: 2, label: 'Pack Items' },
    { n: 3, label: 'Ready for Pickup' },
];
const activePsmStep = ref(1);
const maxPsmStep = ref(1); // furthest step reached — the indicator only lets you jump back, not skip ahead

function resetPsmSteps() {
    activePsmStep.value = 1;
    maxPsmStep.value = 1;
}

function goToPsmStep(n) {
    if (n <= maxPsmStep.value) {
        activePsmStep.value = n;
    }
}

function nextPsmStep() {
    if (activePsmStep.value >= 3) {
        return;
    }

    activePsmStep.value += 1;
    maxPsmStep.value = Math.max(maxPsmStep.value, activePsmStep.value);
}

function prevPsmStep() {
    if (activePsmStep.value > 1) {
        activePsmStep.value -= 1;
    }
}

const totalItems = computed(() => order.value?.items.length || 0);
const packedCount = computed(
    () => Object.values(packedState.value).filter(Boolean).length,
);
const allPacked = computed(
    () => totalItems.value > 0 && packedCount.value === totalItems.value,
);
const packProgressPct = computed(() =>
    totalItems.value > 0 ? Math.round((packedCount.value / totalItems.value) * 100) : 0,
);

function togglePacked(idx) {
    packedState.value = { ...packedState.value, [idx]: !packedState.value[idx] };
}

function formatAddress(addr) {
    if (!addr) {
        return '—';
    }

    return [
        addr.street,
        [addr.barangay, addr.municipality].filter(Boolean).join(', '),
        addr.province,
        addr.country,
    ]
        .filter(Boolean)
        .join(', ');
}

const canConfirmReady = computed(() => canPrepare.value && !isUpdatingStatus.value);

// ---- local draft (this device only) ----
// There's no backend field for "packing progress" or a shipment draft —
// this is a convenience so a seller can leave mid-pack and come back
// without losing their checklist. Nothing here is sent to the server
// until Mark Ready for Pickup actually calls updateOrderStatus().
const draftKey = computed(() => (order.value ? `nexmart:prepare-draft:${order.value.id}` : null));

function loadDraft() {
    if (!draftKey.value) {
return;
}

    try {
        const raw = window.localStorage.getItem(draftKey.value);

        if (!raw) {
return;
}

        const draft = JSON.parse(raw);
        packedState.value = { ...packedState.value, ...(draft.packedState || {}) };
        shippingCarrier.value = draft.shippingCarrier || '';
        shippingService.value = draft.shippingService || 'Standard';
        packageWeight.value = draft.packageWeight ?? null;
        packageSize.value = draft.packageSize || '';
    } catch {
        // Corrupt/old draft — ignore and start fresh.
    }
}

function saveDraft() {
    if (!draftKey.value) {
return;
}

    window.localStorage.setItem(
        draftKey.value,
        JSON.stringify({
            packedState: packedState.value,
            shippingCarrier: shippingCarrier.value,
            shippingService: shippingService.value,
            packageWeight: packageWeight.value,
            packageSize: packageSize.value,
        }),
    );
    draftSavedAt.value = new Date();
}

function clearDraft() {
    if (draftKey.value) {
        window.localStorage.removeItem(draftKey.value);
    }
}

async function confirmReadyForPickup() {
    if (!canConfirmReady.value) {
return;
}

    if (
        !allPacked.value &&
        !window.confirm(
            `${totalItems.value - packedCount.value} item(s) are still marked as pending. Mark this order ready for pickup anyway?`,
        )
    ) {
        return;
    }

    const extra = {
        shipping_carrier: shippingCarrier.value.trim() || null,
        shipping_service: shippingService.value || null,
    };

    try {
        // Processing -> Packed -> Ready for Pickup: two real, distinct
        // hops (Order::ALLOWED_TRANSITIONS doesn't allow skipping
        // Packed), each its own recorded status-history row. The
        // courier/service picked in Step 1 rides along on both so the
        // order already shows who it's earmarked for while it waits —
        // it isn't actually handed off (In Transit) until Courier
        // Handover's own Confirm Pickup, which is also where the real
        // tracking number gets attached.
        order.value = await updateOrderStatus(order.value.id, 'Packed', extra);
        order.value = await updateOrderStatus(order.value.id, 'Ready for Pickup', extra);
        clearDraft();
        // Back to the queue — it already re-rendered (updateOrderStatus
        // updates the composable's shared `orders` list in place), so
        // this order now shows as awaiting pickup instead of leaving a
        // stale page open for an order that's already moved on.
        closeModal();
    } catch {
        // updateError already holds a message for the template; order.value
        // reflects whichever hop actually completed, so canPrepare and the
        // "not ready" placeholder stay honest even on a partial failure.
    }
}

function goTo(section) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section } }));
}

function goToOrderDetails() {
    if (!order.value) {
        goTo('orders');

        return;
    }

    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'orderDetails', orderId: order.value.id },
        }),
    );
}

// ---- modal accessibility: focus trap + Esc + restore focus on close ----
// Same pattern as orders/ConfirmActionDialog.vue's onTabTrap, just local
// to this component since this modal isn't a separate reusable dialog.
const uid = Math.random().toString(36).slice(2, 8);
const psmTitleId = `psm-title-${uid}`;
const panelRef = ref(null);
let lastFocused = null;

watch(modalOpen, async (isOpen) => {
    if (isOpen) {
        lastFocused = document.activeElement;
        document.addEventListener('keydown', onTabTrap, true);
        await nextTick();
        panelRef.value
            ?.querySelector('button:not(:disabled), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
            ?.focus();
    } else {
        document.removeEventListener('keydown', onTabTrap, true);
        lastFocused?.focus?.();
    }
});

function onTabTrap(e) {
    if (e.key !== 'Tab' || !panelRef.value) {
        return;
    }

    const focusable = panelRef.value.querySelectorAll(
        'button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
    );

    if (!focusable.length) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

onBeforeUnmount(() => document.removeEventListener('keydown', onTabTrap, true));
</script>

<style scoped>
/* ============================================================
   Dark theme + reference spacing/typography for the queue page
   (.prep-picker). The action modal itself (.psm-*, .modal-panel) is
   NOT dark-themed — it follows the app's existing modal convention
   (Inventory/Feedback/Messages/ConfirmActionDialog all render as a
   plain white panel over a blurred backdrop regardless of the page
   underneath), and its base look lives in the shared
   resources/css/seller/layout.css stylesheet, not here.
   ============================================================ */
.prep-picker {
    --pp-surface: #161b17;
    --pp-surface-2: #1d231e;
    --pp-border: rgba(255, 255, 255, 0.08);
    --pp-border-soft: rgba(255, 255, 255, 0.06);
    --pp-ink-900: #f2f4f1;
    --pp-ink-700: #c6cbc5;
    --pp-ink-500: #97a099;
    --pp-ink-400: #6d766e;
    color: var(--pp-ink-900);
}

/* ---- topbar (matches the reference's .topbar/.page-title/.page-sub) ---- */
.prep-picker .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
    flex-wrap: wrap;
    margin-bottom: 1.35rem;
}
.prep-picker .page-title {
    font-size: 1.3rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--pp-ink-900);
    margin: 0;
}
.prep-picker .page-sub {
    font-size: 0.79rem;
    color: var(--pp-ink-500);
    margin: 0.2rem 0 0;
}

/* ---- controls row: stage tabs + search ---- */
.prep-picker .controls-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.1rem;
}
.prep-picker .po-tabs {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    flex-wrap: wrap;
}
.prep-picker .po-tab {
    padding: 0.42rem 0.95rem;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    border: none;
    background: transparent;
    color: var(--pp-ink-500);
    cursor: pointer;
    white-space: nowrap;
}
.prep-picker .po-tab.active {
    background: var(--pp-ink-900);
    color: var(--pp-surface);
}
.prep-picker .ctrl-right {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.prep-picker .search {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 14rem;
}
.prep-picker .search .ic {
    position: absolute;
    left: 0.7rem;
    color: var(--pp-ink-500);
    pointer-events: none;
    display: flex;
}
.prep-picker .search input {
    width: 100%;
    padding: 0.5rem 0.8rem 0.5rem 2.1rem;
    border-radius: 0.6rem;
    border: 1px solid var(--pp-border);
    background: var(--pp-surface-2);
    color: var(--pp-ink-900);
    font-size: 0.82rem;
}
.prep-picker-empty {
    font-size: 0.85rem;
    color: var(--pp-ink-500);
    padding: 2rem 0;
    text-align: center;
}

/* ---- card grid (matches the reference's .po-grid/.po-card exactly) ---- */
.prep-picker .po-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.1rem;
}
@media (max-width: 1180px) {
    .prep-picker .po-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 720px) {
    .prep-picker .po-grid {
        grid-template-columns: 1fr;
    }
}
.prep-picker .po-card {
    background: var(--pp-surface);
    border: 1px solid var(--pp-border);
    border-radius: 1.1rem;
    padding: 1.1rem 1.2rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.prep-picker .po-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.6rem;
}
.prep-picker .po-cust {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    min-width: 0;
}
.prep-picker .po-cust-avatar {
    width: 2.4rem;
    height: 2.4rem;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(15, 118, 110, 0.35), rgba(111, 163, 224, 0.35));
    color: #5eead4;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.75rem;
    flex-shrink: 0;
}
.prep-picker .po-cust-name {
    font-size: 0.87rem;
    font-weight: 700;
    color: var(--pp-ink-900);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.prep-picker .po-cust-meta {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.72rem;
    color: var(--pp-ink-500);
    margin-top: 0.15rem;
    white-space: nowrap;
}
.prep-picker .po-status-pill {
    flex-shrink: 0;
    padding: 0.22rem 0.6rem;
    border-radius: 999px;
    font-size: 0.66rem;
    font-weight: 800;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.prep-picker .po-status-pill .dot {
    width: 0.4rem;
    height: 0.4rem;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}
.prep-picker .po-status-pill.neutral { background: var(--pp-surface-2); color: var(--pp-ink-500); }
.prep-picker .po-status-pill.warn { background: rgba(181, 121, 27, 0.2); color: #fbbf7d; }
.prep-picker .po-status-pill.good { background: rgba(20, 184, 166, 0.2); color: #5eead4; }
.prep-picker .po-status-pill.blue { background: rgba(111, 163, 224, 0.2); color: #9dc2ef; }
.prep-picker .po-status-pill.bad { background: rgba(200, 67, 61, 0.2); color: #f7a49f; }

.prep-picker .po-meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.66rem;
    font-weight: 700;
    color: var(--pp-ink-400);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding-bottom: 0.75rem;
    border-bottom: 1px dashed var(--pp-border);
}
.prep-picker .po-meta-row .val {
    color: var(--pp-ink-900);
    font-weight: 800;
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.78rem;
}
.prep-picker .po-meta-row .val.tag {
    padding: 0.1rem 0.5rem;
    border-radius: 0.4rem;
}
.prep-picker .po-meta-row .val.tag.neutral { background: var(--pp-surface-2); color: var(--pp-ink-500); }
.prep-picker .po-meta-row .val.tag.blue { background: rgba(111, 163, 224, 0.2); color: #9dc2ef; }
.prep-picker .po-meta-row .val.tag.bad { background: rgba(200, 67, 61, 0.2); color: #f7a49f; }

.prep-picker .po-items {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}
.prep-picker .po-item-line {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    font-size: 0.79rem;
}
.prep-picker .po-item-qty {
    font-weight: 800;
    color: var(--pp-ink-400);
    flex-shrink: 0;
    width: 1rem;
}
.prep-picker .po-item-line .nm {
    flex: 1;
    color: var(--pp-ink-700);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.prep-picker .po-item-line .pr {
    font-weight: 700;
    color: var(--pp-ink-900);
    flex-shrink: 0;
}

.prep-picker .po-total-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 0.75rem;
    border-top: 1px solid var(--pp-border-soft);
    font-size: 0.68rem;
    font-weight: 800;
    color: var(--pp-ink-400);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.prep-picker .po-total-row .amt {
    font-size: 1rem;
    color: var(--pp-ink-900);
    text-transform: none;
}

.prep-picker .po-card-actions {
    display: flex;
    gap: 0.6rem;
}
.prep-picker .po-btn-primary {
    flex: 1;
    padding: 0.55rem;
    border-radius: 0.65rem;
    border: none;
    background: var(--pp-ink-900);
    color: var(--pp-surface);
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
}
.prep-picker .po-btn-primary:hover {
    background: #5eead4;
}
.prep-picker .po-card-note {
    font-size: 0.78rem;
    color: var(--pp-ink-500);
    text-align: center;
    padding: 0.4rem 0;
    font-weight: 600;
    margin: 0;
}

.prep-picker .po-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1.3rem;
    font-size: 0.78rem;
    color: var(--pp-ink-500);
}
.prep-picker .po-page-nav {
    display: flex;
    gap: 0.4rem;
}
.prep-picker .po-page-btn {
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 0.5rem;
    border: 1px solid var(--pp-border);
    background: var(--pp-surface-2);
    color: var(--pp-ink-700);
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.prep-picker .po-page-btn:disabled {
    opacity: 0.4;
    cursor: default;
}
.prep-picker .po-page-btn.active {
    background: var(--pp-ink-900);
    border-color: var(--pp-ink-900);
    color: var(--pp-surface);
}
</style>
