<script>
import { ref } from 'vue';

// The chosen tab outlives this component, so leaving for Wishlist or an
// order's details and coming back lands on the same tab.
const selectedTab = ref('all');
</script>

<script setup>
/*
|--------------------------------------------------------------------------
| Orders.vue — My Orders
|--------------------------------------------------------------------------
|
| The list of the buyer's orders, rendered in AccountLayout's content
| column (the sidebar never moves). Opening one swaps in OrderDetails or
| OrderTracking in the same place; their own logic is unchanged.
|
| Tabs follow the order's `stage` from the orders API (App\Support\
| OrderStage): To Pay, To Ship, To Receive, Completed, Cancelled and
| Return/Refund, plus All. The stored status stays the order's real status
| and is shown on the card when it says more than the tab ("Confirmed",
| "Ready for Pickup"). Counts come from the full order list the API
| returns, so they're always the tab's real size.
|
| Item photos come with the order (variant photo, else product photo),
| shown by OrderItemThumb.
|
| A card's header and items open the order's details: the order number is
| a real button whose click area is stretched over that part of the card
| (keyboard: Tab to it, Enter). The footer's buttons sit outside it, so
| they never open details too. Completed orders offer Buy again (see
| useBuyAgain.js) in place of View details.
|
*/
import { computed, nextTick, onMounted, reactive } from 'vue';

import OrderDetails from './OrderDetails.vue';
import OrderItemThumb from './OrderItemThumb.vue';
import OrderTracking from './OrderTracking.vue';
import { useBuyer } from '../composables/useBuyer';
import { buyAgain } from '../composables/useBuyAgain';
import { formatPrice } from '../composables/useCategoryMeta';
import { useToasts } from '../composables/useToasts';

const emit = defineEmits([
    'back',
    'go-home',
    'search',
    'select-category',
    'open-cart',
    'view-profile',
    'view-wishlist',
    'view-reviews',
    'view-addresses',
    'view-payments'
]);

const {
    orders,
    isLoadingOrders,
    ordersLoadError,
    loadOrders
} = useBuyer();

onMounted(() => {
    loadOrders();
    revealActiveTab();
});

/*
|--------------------------------------------------------------------------
| Selected Order
|--------------------------------------------------------------------------
*/

const selectedOrder = ref(null);

// Which view to show for the selected order — OrderDetails or
// OrderTracking (OrderDetails' "Track Package" emits 'track-order').
const isTrackingView = ref(false);

function viewOrderDetails(order) {
    selectedOrder.value = order;
    isTrackingView.value = false;
}

function trackOrder(order) {
    selectedOrder.value = order;
    isTrackingView.value = true;
}

function backToOrders() {
    selectedOrder.value = null;
    isTrackingView.value = false;
}

/*
|--------------------------------------------------------------------------
| Tabs
|--------------------------------------------------------------------------
*/

const TABS = [
    { id: 'all', label: 'All' },
    { id: 'to_pay', label: 'To Pay' },
    { id: 'to_ship', label: 'To Ship' },
    { id: 'to_receive', label: 'To Receive' },
    { id: 'completed', label: 'Completed' },
    { id: 'cancelled', label: 'Cancelled' },
    { id: 'return_refund', label: 'Return/Refund' }
];

const STAGE_LABELS = Object.fromEntries(TABS.map(tab => [tab.id, tab.label]));

// What an empty tab says — including why To Pay stays empty for cash on
// delivery, the only payment method checkout offers.
const EMPTY = {
    all: { title: 'No orders yet', text: 'When you buy something, it shows up here so you can follow it to your door.' },
    to_pay: { title: 'Nothing to pay', text: 'Orders paid online wait here until payment goes through. Cash on delivery orders are paid when they arrive, so they go straight to To Ship.' },
    to_ship: { title: 'Nothing waiting to ship', text: 'Orders the seller is still preparing show up here.' },
    to_receive: { title: 'Nothing on the way', text: 'Orders handed to the courier show up here until they’re delivered.' },
    completed: { title: 'No completed orders yet', text: 'Delivered orders show up here, where you can review them or request a return.' },
    cancelled: { title: 'No cancelled orders', text: 'Orders you or a seller cancel show up here.' },
    return_refund: { title: 'No returns or refunds', text: 'Return and refund requests you open on delivered items show up here with their progress.' }
};

const stageOf = order => order.stage || 'to_ship';

const counts = computed(() => {
    const result = Object.fromEntries(TABS.map(tab => [tab.id, 0]));

    for (const order of orders.value) {
        result.all += 1;
        result[stageOf(order)] = (result[stageOf(order)] || 0) + 1;
    }

    return result;
});

const filteredOrders = computed(() => (selectedTab.value === 'all'
    ? orders.value
    : orders.value.filter(order => stageOf(order) === selectedTab.value)));

const tabButtons = ref([]);

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// On phones the tab row scrolls sideways: keep the chosen tab in view.
function revealActiveTab() {
    nextTick(() => {
        tabButtons.value[TABS.findIndex(tab => tab.id === selectedTab.value)]?.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
            behavior: prefersReducedMotion ? 'auto' : 'smooth'
        });
    });
}

function selectTab(id) {
    selectedTab.value = id;
    revealActiveTab();
}

// Arrow keys move between tabs (and select), Home / End jump to the ends.
function handleTabKeydown(event, index) {
    const last = TABS.length - 1;
    const next = {
        ArrowRight: index === last ? 0 : index + 1,
        ArrowLeft: index === 0 ? last : index - 1,
        Home: 0,
        End: last
    }[event.key];

    if (next === undefined) {
        return;
    }

    event.preventDefault();
    selectTab(TABS[next].id);
    nextTick(() => tabButtons.value[next]?.focus());
}

/*
|--------------------------------------------------------------------------
| Card Helpers
|--------------------------------------------------------------------------
*/

function formatDate(date) {
    if (!date) {
        return '';
    }

    return new Date(date).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatPaymentMethod(method) {
    return {
        cod: 'Cash on Delivery',
        gcash: 'GCash',
        card: 'Credit / Debit Card'
    }[method] || method || 'Payment not specified';
}

// The seller's own status, when it adds something to the tab name.
function statusDetail(order) {
    const status = (order.status || '').trim();

    return status && status.toLowerCase() !== (STAGE_LABELS[stageOf(order)] || '').toLowerCase() ? status : '';
}

function itemCount(order) {
    return (order.items || []).reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
}

function returnLabel(item) {
    const request = item.returnRequest;

    if (!request) {
        return '';
    }

    const type = request.requestType === 'refund_only' ? 'Refund' : 'Return';

    return `${type} ${(request.status || '').toLowerCase()}`;
}

/*
|--------------------------------------------------------------------------
| Buy Again
|--------------------------------------------------------------------------
|
| One run per order at a time (the button is disabled while it runs). The
| outcome stays on the card: what couldn't be added and why, plus a way to
| the cart; a toast sums it up.
|
*/

const { success: toastSuccess, warning: toastWarning, error: toastError } = useToasts();

// orderId -> { status: 'loading' | 'done' | 'error', added, problems, message }
const buyAgainRuns = reactive({});

function countLabel(n) {
    return `${n} ${n === 1 ? 'item' : 'items'}`;
}

async function handleBuyAgain(order) {
    if (buyAgainRuns[order.orderId]?.status === 'loading') {
        return;
    }

    buyAgainRuns[order.orderId] = { status: 'loading', added: [], problems: [], message: '' };

    try {
        const { added, problems } = await buyAgain(order);

        buyAgainRuns[order.orderId] = { status: 'done', added, problems, message: '' };

        if (added.length && !problems.length) {
            toastSuccess(`Added ${countLabel(added.length)} to your cart.`);
        } else if (added.length) {
            toastWarning(`Added ${countLabel(added.length)} to your cart. ${countLabel(problems.length)} couldn’t be added.`);
        } else {
            toastError('None of these items can be added to your cart right now.');
        }
    } catch {
        buyAgainRuns[order.orderId] = {
            status: 'error',
            added: [],
            problems: [],
            message: 'We couldn’t check these items right now. Please try again.'
        };
        toastError('We couldn’t add these items. Please try again.');
    }
}

function dismissBuyAgain(order) {
    delete buyAgainRuns[order.orderId];
}

const skeletons = [0, 1];
</script>

<template>

    <Transition
        name="acc-swap"
        mode="out-in"
    >
        <!-- ================================================================ -->
        <!-- ORDER TRACKING -->
        <!-- ================================================================ -->

        <OrderTracking
            v-if="selectedOrder && isTrackingView"
            :order="selectedOrder"
            @back="isTrackingView = false"
            @view-orders="backToOrders"
            @go-home="emit('go-home')"
            @search="emit('search', $event)"
            @select-category="emit('select-category', $event)"
            @open-cart="emit('open-cart')"
            @view-profile="emit('view-profile')"
            @view-wishlist="emit('view-wishlist')"
            @view-reviews="emit('view-reviews')"
            @view-addresses="emit('view-addresses')"
            @view-payments="emit('view-payments')"
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
            @view-addresses="emit('view-addresses')"
            @view-payments="emit('view-payments')"
            @track-order="isTrackingView = true"
        />

        <!-- ================================================================ -->
        <!-- ORDERS LIST -->
        <!-- ================================================================ -->

        <section
            v-else
            class="acc-view ord"
            aria-labelledby="orders-title"
        >
            <header class="acc-head">
                <h1
                    id="orders-title"
                    class="acc-title"
                >
                    My Orders
                </h1>
                <p class="acc-lede">Follow your orders from checkout to your door.</p>
            </header>

            <!-- Tabs -->
            <div
                class="ord-tabs"
                role="tablist"
                aria-label="Orders by status"
            >
                <button
                    v-for="(tab, index) in TABS"
                    :id="`orders-tab-${tab.id}`"
                    :key="tab.id"
                    :ref="el => (tabButtons[index] = el)"
                    type="button"
                    role="tab"
                    class="ord-tab"
                    :class="{ 'is-active': selectedTab === tab.id }"
                    :aria-selected="selectedTab === tab.id"
                    aria-controls="orders-panel"
                    :tabindex="selectedTab === tab.id ? 0 : -1"
                    @click="selectTab(tab.id)"
                    @keydown="handleTabKeydown($event, index)"
                >
                    {{ tab.label }}
                    <span
                        v-if="!isLoadingOrders && !ordersLoadError && counts[tab.id] > 0"
                        class="ord-tab-count"
                    >
                        {{ counts[tab.id] }}<span class="sr-only"> {{ counts[tab.id] === 1 ? 'order' : 'orders' }}</span>
                    </span>
                </button>
            </div>

            <div
                id="orders-panel"
                class="ord-panel"
                role="tabpanel"
                :aria-labelledby="`orders-tab-${selectedTab}`"
                :aria-busy="isLoadingOrders"
            >
                <!-- Loading -->
                <div
                    v-if="isLoadingOrders && orders.length === 0"
                    class="ord-list"
                    aria-hidden="true"
                >
                    <div
                        v-for="n in skeletons"
                        :key="n"
                        class="ord-card is-skeleton"
                    >
                        <div class="ord-card-head">
                            <span class="skeleton is-line"></span>
                        </div>
                        <div class="ord-item">
                            <span class="skeleton ord-thumb"></span>
                            <span class="ord-item-text">
                                <span class="skeleton is-line"></span>
                                <span class="skeleton is-line is-short"></span>
                            </span>
                        </div>
                    </div>
                    <p class="sr-only">Loading your orders…</p>
                </div>

                <!-- Error -->
                <div
                    v-else-if="ordersLoadError"
                    class="ord-state"
                    role="alert"
                >
                    <h2>We couldn&rsquo;t load your orders</h2>
                    <p>{{ ordersLoadError }}</p>
                    <button
                        type="button"
                        class="btn btn-primary"
                        @click="loadOrders"
                    >
                        Try again
                    </button>
                </div>

                <!-- Empty tab -->
                <Transition
                    v-else
                    name="ord-fade"
                    mode="out-in"
                >
                    <div
                        v-if="filteredOrders.length === 0"
                        :key="`empty-${selectedTab}`"
                        class="ord-state"
                    >
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 8 12 3 3 8v8l9 5 9-5Z" />
                            <path d="m3 8 9 5 9-5M12 13v8" />
                        </svg>
                        <h2>{{ EMPTY[selectedTab].title }}</h2>
                        <p>{{ EMPTY[selectedTab].text }}</p>
                        <button
                            v-if="selectedTab !== 'all' && counts.all > 0"
                            type="button"
                            class="btn btn-secondary"
                            @click="selectTab('all')"
                        >
                            Show all orders
                        </button>
                        <button
                            v-else-if="counts.all === 0"
                            type="button"
                            class="btn btn-primary"
                            @click="emit('back')"
                        >
                            Start shopping
                        </button>
                    </div>

                    <!-- Orders -->
                    <ul
                        v-else
                        :key="`list-${selectedTab}`"
                        class="ord-list"
                    >
                        <li
                            v-for="order in filteredOrders"
                            :key="order.orderId"
                        >
                            <article
                                class="ord-card"
                                :aria-labelledby="`order-${order.orderId}`"
                            >
                                <div class="ord-card-main">
                                <header class="ord-card-head">
                                    <div class="ord-card-meta">
                                        <span class="ord-seller">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M3 9h18l-1.5-5h-15Z" /><path d="M4 9v11h16V9" /><path d="M9 20v-6h6v6" />
                                            </svg>
                                            {{ order.seller || 'BuyTheWay Seller' }}
                                        </span>
                                        <!-- The card's open action: its click area covers the
                                             whole header + items region (.ord-open::after). -->
                                        <button
                                            :id="`order-${order.orderId}`"
                                            type="button"
                                            class="ord-number ord-open"
                                            :aria-label="`Order ${order.orderId}, ${STAGE_LABELS[stageOf(order)]} — view details`"
                                            @click="viewOrderDetails(order)"
                                        >Order {{ order.orderId }}<template v-if="order.createdAt"> · {{ formatDate(order.createdAt) }}</template></button>
                                    </div>
                                    <div class="ord-card-status">
                                        <span
                                            v-if="statusDetail(order)"
                                            class="ord-status-detail"
                                        >{{ statusDetail(order) }}</span>
                                        <span
                                            class="ord-stage"
                                            :class="`is-${stageOf(order)}`"
                                        >{{ STAGE_LABELS[stageOf(order)] }}</span>
                                        <svg class="ord-open-chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                                    </div>
                                </header>

                                <ul class="ord-items">
                                    <li
                                        v-for="(item, index) in order.items"
                                        :key="item.id || `${order.orderId}-${index}`"
                                        class="ord-item"
                                    >
                                        <OrderItemThumb
                                            :src="item.image || ''"
                                            :category="item.category || ''"
                                        />
                                        <div class="ord-item-text">
                                            <p class="ord-item-name">{{ item.name || 'Product' }}</p>
                                            <p class="ord-item-meta">
                                                <span v-if="item.variation">{{ item.variation }}</span>
                                                <span>Qty {{ item.quantity }}</span>
                                                <span
                                                    v-if="returnLabel(item)"
                                                    class="ord-item-return"
                                                >{{ returnLabel(item) }}</span>
                                            </p>
                                        </div>
                                        <span class="ord-item-price">{{ formatPrice(item.unit_price ?? item.price ?? 0) }}</span>
                                    </li>
                                </ul>
                                </div>

                                <footer class="ord-card-foot">
                                    <p class="ord-foot-meta">
                                        {{ formatPaymentMethod(order.payment_method) }}
                                        <span aria-hidden="true">·</span>
                                        {{ itemCount(order) }} {{ itemCount(order) === 1 ? 'item' : 'items' }}
                                    </p>
                                    <p class="ord-total">
                                        <span>Order total</span>
                                        <strong>{{ formatPrice(order.total) }}</strong>
                                    </p>
                                    <div class="ord-actions">
                                        <button
                                            v-if="stageOf(order) === 'to_receive'"
                                            type="button"
                                            class="btn btn-secondary"
                                            @click="trackOrder(order)"
                                        >
                                            Track order
                                        </button>
                                        <button
                                            v-if="stageOf(order) === 'completed'"
                                            type="button"
                                            class="btn btn-primary ord-buy-again"
                                            :disabled="buyAgainRuns[order.orderId]?.status === 'loading'"
                                            :aria-busy="buyAgainRuns[order.orderId]?.status === 'loading'"
                                            @click="handleBuyAgain(order)"
                                        >
                                            <span
                                                v-if="buyAgainRuns[order.orderId]?.status === 'loading'"
                                                class="ord-spinner"
                                                aria-hidden="true"
                                            ></span>
                                            <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16" /><path d="M3 21v-5h5" /></svg>
                                            {{ buyAgainRuns[order.orderId]?.status === 'loading' ? 'Adding…' : 'Buy again' }}
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            class="btn btn-primary"
                                            @click="viewOrderDetails(order)"
                                        >
                                            View details
                                        </button>
                                    </div>
                                </footer>

                                <!-- Buy again outcome: what was added, what wasn't and why -->
                                <div
                                    v-if="buyAgainRuns[order.orderId] && buyAgainRuns[order.orderId].status !== 'loading'"
                                    class="ord-buy-result"
                                    :class="{ 'has-problems': buyAgainRuns[order.orderId].problems.length || buyAgainRuns[order.orderId].status === 'error' }"
                                    role="status"
                                >
                                    <div class="ord-buy-result-text">
                                        <p
                                            v-if="buyAgainRuns[order.orderId].status === 'error'"
                                            class="ord-buy-result-title"
                                        >
                                            {{ buyAgainRuns[order.orderId].message }}
                                        </p>
                                        <template v-else>
                                            <p class="ord-buy-result-title">
                                                <template v-if="buyAgainRuns[order.orderId].added.length">
                                                    Added {{ countLabel(buyAgainRuns[order.orderId].added.length) }} to your cart at today’s prices.
                                                </template>
                                                <template v-else>Nothing was added to your cart.</template>
                                            </p>
                                            <ul
                                                v-if="buyAgainRuns[order.orderId].problems.length || buyAgainRuns[order.orderId].added.some(a => a.note)"
                                                class="ord-buy-result-list"
                                            >
                                                <li
                                                    v-for="problem in buyAgainRuns[order.orderId].problems"
                                                    :key="`p-${problem.name}`"
                                                >
                                                    <strong>{{ problem.name }}</strong> {{ problem.message }}
                                                </li>
                                                <template
                                                    v-for="entry in buyAgainRuns[order.orderId].added"
                                                    :key="`a-${entry.name}`"
                                                >
                                                    <li v-if="entry.note">
                                                        <strong>{{ entry.name }}</strong>: {{ entry.note }}.
                                                    </li>
                                                </template>
                                            </ul>
                                        </template>
                                    </div>
                                    <div class="ord-buy-result-actions">
                                        <button
                                            v-if="buyAgainRuns[order.orderId].added.length"
                                            type="button"
                                            class="btn btn-secondary"
                                            @click="emit('open-cart')"
                                        >
                                            Go to cart
                                        </button>
                                        <button
                                            v-else-if="buyAgainRuns[order.orderId].status === 'error'"
                                            type="button"
                                            class="btn btn-secondary"
                                            @click="handleBuyAgain(order)"
                                        >
                                            Try again
                                        </button>
                                        <button
                                            type="button"
                                            class="icon-btn"
                                            aria-label="Dismiss"
                                            @click="dismissBuyAgain(order)"
                                        >
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </li>
                    </ul>
                </Transition>
            </div>
        </section>
    </Transition>

</template>
