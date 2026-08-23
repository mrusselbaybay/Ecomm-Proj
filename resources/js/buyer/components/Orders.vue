<script setup>
import { computed, onMounted, ref } from 'vue';

import OrderDetails from './OrderDetails.vue';

import { useBuyer } from '../composables/useBuyer';

const emit = defineEmits([
    'back'
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
| Order Details
|--------------------------------------------------------------------------
*/

function viewOrderDetails(order) {
    selectedOrder.value = order;
}

function backToOrders() {
    selectedOrder.value = null;
}

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

    return new Date(date).toLocaleString();
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

function getStatusClass(status) {
    // OUT_FOR_DELIVERY/RETURNED are aliases of IN_TRANSIT/CANCELLED in
    // ORDER_STATUSES (see useBuyer.js) — checked here only via their
    // canonical key so a given status maps to exactly one class.
    return {
        'status-to-ship':
            status === ORDER_STATUSES.TO_SHIP,

        'status-out-for-delivery':
            status === ORDER_STATUSES.PROCESSING,

        'status-in-transit':
            status === ORDER_STATUSES.IN_TRANSIT,

        'status-delivered':
            status === ORDER_STATUSES.DELIVERED,

        'status-cancelled':
            status === ORDER_STATUSES.CANCELLED
    };
}

function getItemPrice(item) {
    return Number(
        item.unit_price ??
        item.price ??
        0
    );
}
</script>

<template>

    <!-- ================================================================ -->
    <!-- ORDER DETAILS -->
    <!-- ================================================================ -->

    <OrderDetails
        v-if="selectedOrder"
        :order="selectedOrder"
        @back="backToOrders"
    />

    <!-- ================================================================ -->
    <!-- ORDERS LIST -->
    <!-- ================================================================ -->

    <div
        v-else
        class="buyer-orders-page"
    >

        <!-- ============================================================ -->
        <!-- HEADER -->
        <!-- ============================================================ -->

        <header class="orders-page-header">

            <button
                type="button"
                class="orders-back-button"
                @click="emit('back')"
            >
                Back to Shopping
            </button>

            <div>

                <h1>
                    My Orders
                </h1>

                <p>
                    View and track your purchases.
                </p>

            </div>

        </header>

        <main class="orders-content">

            <!-- ======================================================== -->
            <!-- STATUS TABS -->
            <!-- ======================================================== -->

            <section class="orders-tabs">

                <button
                    v-for="tab in tabs"
                    :key="tab"
                    type="button"
                    class="orders-tab"
                    :class="{
                        active:
                            selectedStatus === tab
                    }"
                    @click="selectedStatus = tab"
                >
                    {{ tab }}
                </button>

            </section>

            <!-- ======================================================== -->
            <!-- LOADING / ERROR -->
            <!-- ======================================================== -->

            <section
                v-if="isLoadingOrders"
                class="orders-empty"
            >
                <h2>Loading your orders&hellip;</h2>
            </section>

            <section
                v-else-if="ordersLoadError"
                class="orders-empty"
            >
                <h2>Couldn&rsquo;t load your orders</h2>
                <p>{{ ordersLoadError }}</p>
            </section>

            <!-- ======================================================== -->
            <!-- EMPTY ORDERS -->
            <!-- ======================================================== -->

            <section
                v-else-if="filteredOrders.length === 0"
                class="orders-empty"
            >

                <h2>
                    No orders found
                </h2>

                <p>
                    You currently have no orders under
                    "{{ selectedStatus }}".
                </p>

            </section>

            <!-- ======================================================== -->
            <!-- ORDER LIST -->
            <!-- ======================================================== -->

            <section
                v-else
                class="orders-list"
            >

                <article
                    v-for="order in filteredOrders"
                    :key="order.orderId"
                    class="order-card"
                >

                    <!-- ================================================= -->
                    <!-- ORDER HEADER -->
                    <!-- ================================================= -->

                    <div class="order-card-header">

                        <div>

                            <span class="order-id-label">
                                Order ID
                            </span>

                            <strong class="order-id">
                                {{ order.orderId }}
                            </strong>

                            <p class="order-date">
                                {{ formatDate(order.createdAt) }}
                            </p>

                        </div>

                        <span
                            class="order-status"
                            :class="
                                getStatusClass(order.status)
                            "
                        >
                            {{ order.status }}
                        </span>

                    </div>

                    <!-- ================================================= -->
                    <!-- PRODUCTS -->
                    <!-- ================================================= -->

                    <div class="order-products">

                        <div
                            v-for="(item, index) in order.items"
                            :key="`${order.orderId}-${index}`"
                            class="order-product"
                        >

                            <div class="order-product-image">
                                Product Image
                            </div>

                            <div class="order-product-info">

                                <h3>
                                    {{
                                        item.name ||
                                        `Product #${item.product_id || item.productId}`
                                    }}
                                </h3>

                                <p>
                                    Seller:
                                    {{
                                        item.seller ||
                                        'NEXMART Seller'
                                    }}
                                </p>

                                <p
                                    v-if="item.category"
                                >
                                    Category:
                                    {{ item.category }}
                                </p>

                                <p>
                                    Variation:
                                    {{
                                        item.variation ||
                                        'Default'
                                    }}
                                </p>

                                <p>
                                    Quantity:
                                    {{ item.quantity }}
                                </p>

                            </div>

                            <div class="order-product-price">

                                {{
                                    formatPrice(
                                        getItemPrice(item)
                                    )
                                }}

                            </div>

                        </div>

                    </div>

                    <!-- ================================================= -->
                    <!-- ORDER INFORMATION -->
                    <!-- ================================================= -->

                    <div class="order-information">

                        <div class="order-info-item">

                            <span>
                                Payment Method
                            </span>

                            <strong>
                                {{
                                    formatPaymentMethod(
                                        order.payment_method
                                    )
                                }}
                            </strong>

                        </div>

                        <div class="order-info-item">

                            <span>
                                Shipping Method
                            </span>

                            <strong>
                                {{
                                    formatShippingMethod(
                                        order.shipping_method
                                    )
                                }}
                            </strong>

                        </div>

                        <div
                            v-if="order.voucher_code"
                            class="order-info-item"
                        >

                            <span>
                                Voucher
                            </span>

                            <strong>
                                {{ order.voucher_code }}
                            </strong>

                        </div>

                    </div>

                    <!-- ================================================= -->
                    <!-- FOOTER -->
                    <!-- ================================================= -->

                    <div class="order-card-footer">

                        <div class="order-total-details">

                            <span>
                                Order Total
                            </span>

                            <strong>
                                {{
                                    formatPrice(
                                        order.total
                                    )
                                }}
                            </strong>

                        </div>

                        <button
                            type="button"
                            class="view-order-details-button"
                            @click="viewOrderDetails(order)"
                        >
                            View Details
                        </button>

                    </div>

                </article>

            </section>

        </main>

    </div>

</template>