<script setup>
/*
|--------------------------------------------------------------------------
| Orders.vue — My Orders (list)
|--------------------------------------------------------------------------
|
| Same Tailwind/Header/Footer/sidebar treatment as Account.vue and
| OrderDetails.vue, extended here since this is what "My Orders" in that
| shared sidebar actually opens — it had zero CSS backing before this
| (no `.orders-*` rules existed anywhere in layout.css), same gap as
| Account.vue and OrderDetails.vue had. No design doc was pasted for this
| specific list page; this reuses the same sidebar, header, card, and
| status-badge language already established for the rest of the account
| area so the whole My Orders flow (list -> detail) feels like one place
| rather than two different apps stitched together.
|
| All the underlying logic (tabs, filtering, formatters) is unchanged
| from before — only the template changed.
|
| "Order Tracking" isn't in this page's sidebar, real or disabled — same
| reasoning as Account.vue: it only means anything once a specific order
| is selected, and this list page is exactly the "no order selected yet"
| state. It appears (real) once you open an order via OrderDetails.vue.
*/
import { computed, onMounted, ref } from 'vue';

import OrderDetails from './OrderDetails.vue';
import OrderTracking from './OrderTracking.vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import { useBuyer } from '../composables/useBuyer';
import { metaFor } from '../composables/useCategoryMeta';

const emit = defineEmits([
    'back',
    'go-home',
    'search',
    'select-category',
    'open-cart',
    'view-profile',
    'view-wishlist',
    'view-reviews'
]);

const {
    orders,
    isLoadingOrders,
    ordersLoadError,
    loadOrders,
    ORDER_STATUSES
} = useBuyer();

onMounted(() => {
    loadOrders();
});

/*
|--------------------------------------------------------------------------
| Selected Order
|--------------------------------------------------------------------------
*/

const selectedOrder = ref(null);

// Which view to show for the selected order — OrderDetails or
// OrderTracking (see OrderDetails.vue's sidebar "Order Tracking" link and
// its "Track Package" header button, both emitting 'track-order').
const isTrackingView = ref(false);

function viewOrderDetails(order) {
    selectedOrder.value = order;
    isTrackingView.value = false;
}

function backToOrders() {
    selectedOrder.value = null;
    isTrackingView.value = false;
}

/*
|--------------------------------------------------------------------------
| Order Tabs
|--------------------------------------------------------------------------
*/

// De-duplicated: ORDER_STATUSES maps a couple of legacy UI labels onto the
// same real order status (see useBuyer.js), so building this from the raw
// values would otherwise show "In Transit" / "Cancelled" twice.
const tabs = [
    'All',
    ORDER_STATUSES.TO_SHIP,
    ORDER_STATUSES.PROCESSING,
    ORDER_STATUSES.IN_TRANSIT,
    ORDER_STATUSES.DELIVERED,
    ORDER_STATUSES.CANCELLED
];

const selectedStatus = ref('All');

/*
|--------------------------------------------------------------------------
| Filtered Orders
|--------------------------------------------------------------------------
*/

const filteredOrders = computed(() => {
    if (selectedStatus.value === 'All') {
        return orders.value;
    }

    return orders.value.filter(
        order =>
            order.status === selectedStatus.value
    );
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function formatPrice(price) {
    return `₱${Number(price || 0).toFixed(2)}`;
}

function formatDate(date) {
    if (!date) {
        return 'No date';
    }

    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatPaymentMethod(method) {
    if (!method) {
        return 'Not specified';
    }

    if (method === 'cod') {
        return 'Cash on Delivery';
    }

    if (method === 'gcash') {
        return 'GCash';
    }

    if (method === 'card') {
        return 'Credit / Debit Card';
    }

    return method;
}

function formatShippingMethod(method) {
    if (!method) {
        return 'Not specified';
    }

    if (method === 'standard') {
        return 'Standard Delivery';
    }

    if (method === 'express') {
        return 'Express Delivery';
    }

    return method;
}

// Same four-color scheme as OrderDetails.vue's Order Summary Bar status
// badge, so a status reads the same color wherever it shows up.
function statusBadgeClass(status) {
    if (status === ORDER_STATUSES.IN_TRANSIT) {
        return 'bg-blue-50 text-blue-600';
    }

    if (status === ORDER_STATUSES.TO_SHIP || status === ORDER_STATUSES.PROCESSING) {
        return 'bg-amber-50 text-amber-600';
    }

    if (status === ORDER_STATUSES.DELIVERED) {
        return 'bg-emerald-50 text-emerald-600';
    }

    if (status === ORDER_STATUSES.CANCELLED) {
        return 'bg-red-50 text-red-600';
    }

    return 'bg-slate-100 text-slate-500';
}

function getItemPrice(item) {
    return Number(
        item.unit_price ??
        item.price ??
        0
    );
}

/*
|--------------------------------------------------------------------------
| Header Relay
|--------------------------------------------------------------------------
*/

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}
</script>

<template>

    <!-- ================================================================ -->
    <!-- ORDER TRACKING -->
    <!-- ================================================================ -->

    <OrderTracking
        v-if="selectedOrder && isTrackingView"
        :order="selectedOrder"
        @back="isTrackingView = false"
        @go-home="emit('go-home')"
        @search="emit('search', $event)"
        @select-category="emit('select-category', $event)"
        @open-cart="emit('open-cart')"
        @view-profile="emit('view-profile')"
        @view-wishlist="emit('view-wishlist')"
        @view-reviews="emit('view-reviews')"
    />

    <!-- ================================================================ -->
    <!-- ORDER DETAILS -->
    <!-- ================================================================ -->

    <OrderDetails
        v-else-if="selectedOrder"
        :order="selectedOrder"
        @back="backToOrders"
        @go-home="emit('go-home')"
        @search="emit('search', $event)"
        @select-category="emit('select-category', $event)"
        @open-cart="emit('open-cart')"
        @view-profile="emit('view-profile')"
        @view-wishlist="emit('view-wishlist')"
        @view-reviews="emit('view-reviews')"
        @track-order="isTrackingView = true"
    />

    <!-- ================================================================ -->
    <!-- ORDERS LIST -->
    <!-- ================================================================ -->

    <div
        v-else
        class="buyer-page"
    >

        <Header
            active-category=""
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('go-home')"
            @search="handleHeaderSearch"
        />

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-10">
            <div class="flex flex-col lg:flex-row gap-8">

                <!-- ============================================================ -->
                <!-- SIDEBAR NAV -->
                <!-- ============================================================ -->

                <aside class="w-full lg:w-64 shrink-0">
                    <nav class="bg-white rounded-3xl p-4 border border-slate-100 space-y-1 lg:sticky lg:top-28" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-profile')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />
                            </svg>
                            My Profile
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" />
                                <path d="M12 22V12" /><polyline points="3.29 7 12 12 20.71 7" /><path d="m7.5 4.27 9 5.15" />
                            </svg>
                            My Orders
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-wishlist')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" />
                            </svg>
                            Wishlist
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-reviews')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                            </svg>
                            My Reviews
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-profile')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.268 21a2 2 0 0 0 3.464 0" />
                                <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326" />
                            </svg>
                            Notifications
                        </button>

                        <div class="pt-1 mt-1 border-t border-slate-50 space-y-1">
                            <div
                                v-for="item in ['Saved Addresses', 'Payment Methods']"
                                :key="item"
                                class="flex items-center justify-between gap-2 px-4 py-3 rounded-2xl text-slate-300 cursor-not-allowed select-none"
                                title="Coming soon"
                            >
                                <span>{{ item }}</span>
                                <span class="text-[9px] font-bold uppercase tracking-wide bg-slate-100 text-slate-400 px-2 py-0.5 rounded-full shrink-0">Soon</span>
                            </div>
                        </div>

                    </nav>
                </aside>

                <!-- ============================================================ -->
                <!-- MAIN CONTENT -->
                <!-- ============================================================ -->

                <div class="flex-1 space-y-6 min-w-0">

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 text-sm font-semibold text-[#0d9488] hover:underline mb-2"
                                @click="emit('back')"
                            >
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m12 19-7-7 7-7" /><path d="M19 12H5" />
                                </svg>
                                Back to Shopping
                            </button>
                            <h1 class="text-3xl font-bold text-slate-900 tracking-tight">My Orders</h1>
                            <p class="text-slate-500 mt-1">View and track your purchases.</p>
                        </div>
                    </div>

                    <!-- Status Tabs -->
                    <div class="flex items-center gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="tab in tabs"
                            :key="tab"
                            type="button"
                            class="px-4 py-2 rounded-full text-sm font-semibold whitespace-nowrap transition-colors shrink-0"
                            :class="selectedStatus === tab
                                ? 'bg-[#0d9488] text-white'
                                : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'"
                            @click="selectedStatus = tab"
                        >
                            {{ tab }}
                        </button>
                    </div>

                    <!-- Loading / Error -->
                    <div
                        v-if="isLoadingOrders"
                        class="empty-products"
                    >
                        <p>Loading your orders&hellip;</p>
                    </div>

                    <div
                        v-else-if="ordersLoadError"
                        class="empty-products"
                    >
                        <p>{{ ordersLoadError }}</p>
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="filteredOrders.length === 0"
                        class="empty-products"
                    >
                        <span class="empty-products-icon" aria-hidden="true">🔍</span>
                        <p>You currently have no orders under "{{ selectedStatus }}".</p>
                        <button
                            v-if="selectedStatus !== 'All'"
                            type="button"
                            class="clear-filters-button"
                            @click="selectedStatus = 'All'"
                        >
                            Show All Orders
                        </button>
                    </div>

                    <!-- Order Cards -->
                    <div
                        v-else
                        class="space-y-6"
                    >
                        <article
                            v-for="order in filteredOrders"
                            :key="order.orderId"
                            class="bg-white rounded-3xl border border-slate-100 overflow-hidden"
                            style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                        >

                            <!-- Card Header -->
                            <div class="flex flex-wrap items-center justify-between gap-3 px-8 py-5 border-b border-slate-100">
                                <div>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block mb-1">Order {{ order.orderId }}</span>
                                    <span class="text-sm text-slate-500">{{ formatDate(order.createdAt) }}</span>
                                </div>
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold"
                                    :class="statusBadgeClass(order.status)"
                                >
                                    {{ order.status }}
                                </span>
                            </div>

                            <!-- Items -->
                            <div class="divide-y divide-slate-50">
                                <div
                                    v-for="(item, index) in order.items"
                                    :key="`${order.orderId}-${index}`"
                                    class="flex items-center gap-5 px-8 py-5"
                                >
                                    <div
                                        class="w-16 h-16 rounded-2xl flex items-center justify-center shrink-0"
                                        :class="'accent-' + metaFor(item.category).accent"
                                        style="background: var(--accent-bg, #f1f5f9); color: var(--accent-fg, #64748b);"
                                    >
                                        <span class="w-7 h-7" v-html="metaFor(item.category).icon"></span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-bold text-slate-900 truncate">{{ item.name || `Product #${item.product_id}` }}</h3>
                                        <p class="text-sm text-slate-500 mt-0.5">
                                            {{ item.seller || 'NEXMART Seller' }}
                                            <template v-if="item.variation"> • {{ item.variation }}</template>
                                            • Qty {{ item.quantity }}
                                        </p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="font-bold text-slate-900">{{ formatPrice(getItemPrice(item)) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Footer -->
                            <div class="flex flex-wrap items-center justify-between gap-4 px-8 py-5 bg-slate-50">
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-slate-500">
                                    <span>{{ formatPaymentMethod(order.payment_method) }}</span>
                                    <span>{{ formatShippingMethod(order.shipping_method) }}</span>
                                    <span v-if="order.voucher_code">Voucher: {{ order.voucher_code }}</span>
                                </div>
                                <div class="flex items-center gap-6">
                                    <div class="text-right">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block">Total</span>
                                        <span class="text-lg font-bold text-[#0d9488]">{{ formatPrice(order.total) }}</span>
                                    </div>
                                    <button
                                        type="button"
                                        class="px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-colors"
                                        @click="viewOrderDetails(order)"
                                    >
                                        View Details
                                    </button>
                                </div>
                            </div>

                        </article>
                    </div>

                </div>

            </div>
        </main>

        <Footer
            @browse-all="emit('go-home')"
            @browse-categories="emit('go-home')"
            @cart-click="emit('open-cart')"
        />

    </div>

</template>