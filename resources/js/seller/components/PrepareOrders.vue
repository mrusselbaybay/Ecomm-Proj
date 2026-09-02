<!-- resources/js/seller/components/PrepareOrders.vue -->
<template>
    <div v-if="isLoading" class="card">
        <div class="placeholder-page">
            <div class="loading-spinner"></div>
            <p style="margin-top: 1rem">Loading order…</p>
        </div>
    </div>

    <div v-else-if="!props.orderId" class="card">
        <div class="prep-card-head" style="border-bottom: none">
            <div>
                <h3>Prepare Shipment</h3>
                <p class="prep-card-sub">
                    Choose an order to pack and dispatch.
                </p>
            </div>
        </div>

        <div v-if="isLoadingOrders" class="placeholder-page">
            <div class="loading-spinner"></div>
            <p style="margin-top: 1rem">Loading your orders…</p>
        </div>

        <div v-else-if="preparableOrders.length === 0" class="placeholder-page">
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

        <div v-else class="prep-picker-list">
            <button
                v-for="o in preparableOrders"
                :key="o.id"
                class="prep-picker-row"
                @click="selectOrder(o.id)"
            >
                <div>
                    <p class="prep-picker-id">{{ o.id }}</p>
                    <p class="prep-picker-meta">{{ o.customer }} — {{ o.items.length }} item(s) — {{ formatCurrency(o.total) }}</p>
                </div>
                <span class="badge" :class="statusBadgeClass(o.status)">{{ o.status }}</span>
            </button>
        </div>
    </div>

    <div v-else-if="!order" class="card">
        <div class="placeholder-page">
            <div class="icon-wrap">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M9.5 9.5 14.5 14.5M14.5 9.5 9.5 14.5" />
                </svg>
            </div>
            <h3>Order not found</h3>
            <p>
                That order couldn't be loaded. It may have been removed, or
                the link may be out of date.
            </p>
            <button class="btn-outline" style="margin-top: 1.25rem" @click="goTo('orders')">
                Go to Orders
            </button>
        </div>
    </div>

    <div v-else-if="!canPrepare" class="card">
        <div class="placeholder-page">
            <div class="icon-wrap" style="background: #fffbeb; color: #d97706">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 9v4M12 17h.01" />
                    <circle cx="12" cy="12" r="9" />
                </svg>
            </div>
            <h3>{{ order.id }} isn't ready to prepare</h3>
            <p>
                This order is currently
                <span class="badge" :class="statusBadgeClass(order.status)">{{ order.status }}</span>
                — shipments can only be prepared for orders you've already
                accepted.
            </p>
            <button class="btn-outline" style="margin-top: 1.25rem" @click="goToOrderDetails">
                View Order Details
            </button>
        </div>
    </div>

    <div v-else>
        <!-- ================================================================
         HEADER
         ================================================================ -->
        <header class="prep-header">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="prep-title">Prepare Shipment</h2>
                    <span class="badge" :class="statusBadgeClass(order.status)">{{ statusLabel(order.status) }}</span>
                </div>
                <nav class="prep-breadcrumb">
                    <a href="#" @click.prevent="goTo('orders')">Orders</a>
                    <span>/</span>
                    <a href="#" @click.prevent="goToOrderDetails">{{ order.id }}</a>
                    <span>/</span>
                    <span>Prepare Shipment</span>
                </nav>
            </div>
            <div class="flex items-center gap-3">
                <button class="btn-outline" @click="saveDraft">
                    {{ draftSavedAt ? 'Draft Saved ✓' : 'Save Draft' }}
                </button>
                <button
                    class="btn-primary"
                    :disabled="!canDispatch"
                    :title="dispatchDisabledReason"
                    @click="confirmDispatch"
                >
                    {{ isUpdatingStatus ? 'Dispatching…' : 'Confirm Dispatch' }}
                </button>
            </div>
        </header>

        <p v-if="updateError" class="save-msg error" style="margin-bottom: 1rem">
            {{ updateError }}
        </p>

        <!-- ================================================================
         WORKFLOW GRID
         ================================================================ -->
        <div class="prep-grid">
            <!-- LEFT: items + packing tips -->
            <div class="prep-col">
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
            </div>

            <!-- RIGHT: shipping configuration -->
            <div class="prep-col">
                <div class="card">
                    <div class="prep-card-head">
                        <div>
                            <h3>Shipping Configuration</h3>
                            <p class="prep-card-sub">Enter the package and courier details for this shipment.</p>
                        </div>
                    </div>

                    <div class="prep-form">
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
                                <label class="field-label">Dimensions L×W×H (cm)</label>
                                <div class="prep-dims-row">
                                    <input type="number" min="0" class="field-input" v-model.number="packageDims.l" placeholder="L" />
                                    <input type="number" min="0" class="field-input" v-model.number="packageDims.w" placeholder="W" />
                                    <input type="number" min="0" class="field-input" v-model.number="packageDims.h" placeholder="H" />
                                </div>
                            </div>
                        </div>

                        <div class="sheet-field-row">
                            <div>
                                <label class="field-label">Courier / Carrier</label>
                                <select class="field-input" v-model="shippingCarrier">
                                    <option value="" disabled>
                                        {{ isLoadingLogisticsCompanies ? 'Loading couriers…' : 'Select a courier' }}
                                    </option>
                                    <option v-for="company in logisticsCompanies" :key="company.id" :value="company.name">
                                        {{ company.name }}
                                    </option>
                                    <!-- Keeps a draft's/order's previously saved carrier selectable even if
                                         it's since dropped off the active-companies list. -->
                                    <option
                                        v-if="shippingCarrier && !logisticsCompanies.some((c) => c.name === shippingCarrier)"
                                        :value="shippingCarrier"
                                    >
                                        {{ shippingCarrier }}
                                    </option>
                                </select>
                                <p v-if="!isLoadingLogisticsCompanies && logisticsCompanies.length === 0" class="field-hint">
                                    No active logistics partners on file yet.
                                </p>
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

                        <div>
                            <label class="field-label"
                                >Tracking Number
                                <span style="color: #dc2626">*</span></label
                            >
                            <input
                                type="text"
                                class="field-input"
                                v-model="trackingNumber"
                                placeholder="Enter the courier's tracking number"
                            />
                            <p class="field-hint">
                                Required before dispatch — this is what buyers
                                will see to track their order.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="prep-card-head" style="border-bottom: none">
                        <h3>Ready to Dispatch?</h3>
                    </div>
                    <p class="prep-dispatch-note">
                        Confirming dispatch moves this order to
                        <strong>In Transit</strong> and shares the tracking
                        details above with the buyer.
                    </p>
                    <p v-if="!allPacked" class="prep-dispatch-warning">
                        {{ totalItems - packedCount }} item(s) still marked as
                        pending — you can still dispatch, but double-check
                        your box first.
                    </p>
                    <button
                        class="btn-primary prep-dispatch-btn"
                        :disabled="!canDispatch"
                        :title="dispatchDisabledReason"
                        @click="confirmDispatch"
                    >
                        {{ isUpdatingStatus ? 'Dispatching…' : 'Confirm Dispatch' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
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
    statusLabel,
    formatCurrency,
    isUpdatingStatus,
    updateError,
    shipOrder,
    logisticsCompanies,
    isLoadingLogisticsCompanies,
    loadLogisticsCompanies,
} = useOrders();

loadLogisticsCompanies();

// Any status between "accepted" and "handed to the courier" — a seller can
// jump straight from Confirmed to packing/dispatch (see Order::
// ALLOWED_TRANSITIONS) rather than being forced through Processing/Packed/
// Ready for Pickup one click at a time first.
const PREPARABLE_STATUSES = ['Confirmed', 'Processing', 'Packed', 'Ready for Pickup'];

const isLoading = ref(true);
const order = ref(null);

const packedState = ref({}); // { [itemIndex]: boolean } — local packing checklist
const trackingNumber = ref('');
const shippingCarrier = ref('');
const shippingService = ref('Standard');
const packageWeight = ref(null);
const packageDims = ref({ l: null, w: null, h: null });
const draftSavedAt = ref(null);

async function loadOrder() {
    isLoading.value = true;

    if (props.orderId) {
        order.value = await getOrderById(props.orderId);

        if (order.value) {
            packedState.value = Object.fromEntries(
                order.value.items.map((_, idx) => [idx, false]),
            );
            draftSavedAt.value = null;
            loadDraft();
        }
    } else {
        // No specific order — e.g. the seller clicked "Prepare Orders" in
        // the sidebar directly, rather than a specific order's "Prepare
        // Shipment" button. Load the order list so we can offer a picker
        // instead of a dead end.
        order.value = null;

        if (!orders.value.length) {
            await loadOrders();
        }
    }

    isLoading.value = false;
}

onMounted(loadOrder);

// Orders a seller has already accepted and can pack/dispatch.
const preparableOrders = computed(() =>
    orders.value.filter((o) => PREPARABLE_STATUSES.includes(o.status)),
);

function selectOrder(id) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'prepareOrders', orderId: id },
        }),
    );
}

watch(() => props.orderId, loadOrder);

const canPrepare = computed(() => order.value && PREPARABLE_STATUSES.includes(order.value.status));

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

const canDispatch = computed(
    () => canPrepare.value && trackingNumber.value.trim().length > 0 && !isUpdatingStatus.value,
);
const dispatchDisabledReason = computed(() => {
    if (isUpdatingStatus.value) return '';
    if (!trackingNumber.value.trim()) return 'Enter a tracking number before dispatching.';

    return '';
});

// ---- local draft (this device only) ----
// There's no backend field for "packing progress" or a shipment draft —
// this is a convenience so a seller can leave mid-pack and come back
// without losing their checklist. Nothing here is sent to the server
// until Confirm Dispatch actually calls shipOrder().
const draftKey = computed(() => (order.value ? `nexmart:prepare-draft:${order.value.id}` : null));

function loadDraft() {
    if (!draftKey.value) return;

    try {
        const raw = window.localStorage.getItem(draftKey.value);

        if (!raw) return;

        const draft = JSON.parse(raw);
        packedState.value = { ...packedState.value, ...(draft.packedState || {}) };
        trackingNumber.value = draft.trackingNumber || '';
        shippingCarrier.value = draft.shippingCarrier || '';
        shippingService.value = draft.shippingService || 'Standard';
        packageWeight.value = draft.packageWeight ?? null;
        packageDims.value = draft.packageDims || { l: null, w: null, h: null };
    } catch {
        // Corrupt/old draft — ignore and start fresh.
    }
}

function saveDraft() {
    if (!draftKey.value) return;

    window.localStorage.setItem(
        draftKey.value,
        JSON.stringify({
            packedState: packedState.value,
            trackingNumber: trackingNumber.value,
            shippingCarrier: shippingCarrier.value,
            shippingService: shippingService.value,
            packageWeight: packageWeight.value,
            packageDims: packageDims.value,
        }),
    );
    draftSavedAt.value = new Date();
}

function clearDraft() {
    if (draftKey.value) {
        window.localStorage.removeItem(draftKey.value);
    }
}

async function confirmDispatch() {
    if (!canDispatch.value) return;

    if (
        !allPacked.value &&
        !window.confirm(
            `${totalItems.value - packedCount.value} item(s) are still marked as pending. Dispatch this shipment anyway?`,
        )
    ) {
        return;
    }

    const updated = await shipOrder(order.value.id, {
        tracking_number: trackingNumber.value.trim(),
        shipping_carrier: shippingCarrier.value.trim() || null,
        shipping_service: shippingService.value || null,
    });

    if (updated) {
        clearDraft();
        goToOrderDetails();
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
</script>