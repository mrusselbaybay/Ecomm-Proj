<script setup>
/*
|--------------------------------------------------------------------------
| OrderTracking.vue
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Order Tracking") the
| same way as the rest of the account area — but the reference leans
| heavily on things this app has no data for at all:
|   - A live map with the courier's real-time GPS position and
|     "2.4 miles away" distance — there's no location-ping system, and
|     orders aren't even linked to a specific courier record (orders only
|     store `shipping_carrier` as a free-text company name).
|   - A named courier with a photo, vehicle, plate number, and a
|     "Message Courier" / "Contact Courier" channel — same reason, no
|     order-to-courier assignment exists to look any of that up from.
|   - An estimated arrival date — no ETA column anywhere on `orders`.
|   - "Reschedule Delivery" — no such flow exists.
|
| Rather than invent all of that, this page surfaces what's actually real
| and already flowing through the app: tracking_number, shipping_carrier,
| and the same timestamped status history (order_status_history) that
| OrderDetails.vue's timeline uses — via useOrderTimeline.js, so the two
| pages can't show conflicting versions of "what happened when." Where the
| reference's map would sit, this shows a plain-language status summary
| instead of a fabricated visual.
|
*/
import { computed, ref } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import { useBuyer } from '../composables/useBuyer';
import {
    trackingSteps,
    stepLabels,
    stepDescriptions,
    stepIcons,
    currentStepKey,
    buildTimeline,
    lastUpdated
} from '../composables/useOrderTimeline';

const props = defineProps({
    order: {
        type: Object,
        default: null
    }
});

const emit = defineEmits([
    'back',
    'view-orders',
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

const { ORDER_STATUSES } = useBuyer();

const isCancelled = computed(() => props.order?.status === ORDER_STATUSES.CANCELLED);

const deliveryAddress = computed(() => props.order?.delivery_address || {});

const currentStepLabel = computed(() => stepLabels[currentStepKey(props.order)] || props.order?.status || 'Unknown');
const currentStepDescription = computed(() => stepDescriptions[currentStepKey(props.order)] || '');
const currentStepIcon = computed(() => stepIcons[currentStepKey(props.order)] || stepIcons[trackingSteps[0]]);

// One pass over order.statusHistory for the whole timeline (see
// buildTimeline's docblock) instead of the per-cell function calls the
// template used to make directly — recomputed only when `order` changes.
const timeline = computed(() => buildTimeline(props.order));
const lastUpdatedLabel = computed(() => formatDate(lastUpdated(props.order)));

function formatDate(date) {
    if (!date) {
        return null;
    }

    return new Date(date).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function formatLongDate(date) {
    if (!date) {
        return 'Not available';
    }

    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

/*
|--------------------------------------------------------------------------
| Copy Tracking Number
|--------------------------------------------------------------------------
*/

const trackingCopied = ref(false);

async function copyTrackingNumber() {
    if (!props.order?.tracking_number) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.order.tracking_number);
        trackingCopied.value = true;
        setTimeout(() => { trackingCopied.value = false; }, 2000);
    } catch (err) {
        // Clipboard permission denied/unavailable — number is still
        // visible to copy by hand.
    }
}

/*
|--------------------------------------------------------------------------
| Need Help
|--------------------------------------------------------------------------
|
| Same honest mailto pattern as OrderDetails.vue / Account.vue — no
| support-ticket system exists to file this into.
*/

const needHelpMailtoHref = computed(() => {
    const subject = encodeURIComponent(`Where's my order ${props.order?.orderId || ''}?`);
    const body = encodeURIComponent(
        `Hi NEXMART support,\n\nI'd like an update on my order ${props.order?.orderId || ''}.\n\n`
    );

    return `mailto:support@nexmart.com?subject=${subject}&body=${body}`;
});

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

    <div
        v-if="order"
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
            <div class="flex flex-col lg:flex-row lg:items-start gap-8">

                <!-- ==================================================== -->
                <!-- SIDEBAR NAV (matching Order Details) -->
                <!-- ==================================================== -->

                <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-36">
                    <nav class="bg-white rounded-3xl p-4 border border-slate-100 space-y-1" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

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
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-orders')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" />
                                <path d="M12 22V12" /><polyline points="3.29 7 12 12 20.71 7" /><path d="m7.5 4.27 9 5.15" />
                            </svg>
                            My Orders
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                            Order Tracking
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
                            @click="emit('view-addresses')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                            Saved Addresses
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-payments')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="14" x="2" y="5" rx="2" /><line x1="2" x2="22" y1="10" y2="10" />
                            </svg>
                            Payment Methods
                        </button>

                    </nav>
                </aside>

                <!-- ==================================================== -->
                <!-- MAIN CONTENT -->
                <!-- ==================================================== -->

                <div class="flex-1 space-y-6 min-w-0">

                    <!-- Breadcrumb + Actions -->
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
                                Back to Order {{ order.orderId }}
                            </button>
                            <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Track Your Package</h1>
                        </div>
                        <div class="flex items-center gap-3">
                            <a
                                :href="needHelpMailtoHref"
                                class="px-5 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all"
                                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                            >
                                Need Help
                            </a>
                            <button
                                v-if="order.tracking_number"
                                type="button"
                                class="px-5 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2"
                                :class="trackingCopied ? 'bg-emerald-600 text-white' : 'bg-[#0d9488] text-white hover:bg-[#0f766e]'"
                                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                                @click="copyTrackingNumber"
                            >
                                <svg v-if="trackingCopied" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                                <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                </svg>
                                {{ trackingCopied ? 'Copied!' : 'Copy Tracking No.' }}
                            </button>
                        </div>
                    </div>

                    <!-- Tracking Info Bar -->
                    <div class="grid grid-cols-2 md:grid-cols-4 bg-white rounded-3xl p-6 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                        <div class="px-4 py-2 border-r border-slate-100">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Tracking Number</p>
                            <p class="font-bold text-slate-900">{{ order.tracking_number || 'Not yet available' }}</p>
                        </div>
                        <div class="px-4 py-2 border-r border-slate-100">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Courier</p>
                            <p class="font-bold text-slate-900">{{ order.shipping_carrier || 'Not yet assigned' }}</p>
                        </div>
                        <div class="px-4 py-2 border-r border-slate-100">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Placed On</p>
                            <p class="font-bold text-slate-900">{{ formatLongDate(order.createdAt) }}</p>
                        </div>
                        <div class="px-4 py-2">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Current Status</p>
                            <span
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold"
                                :class="{
                                    'bg-blue-50 text-blue-600': order.status === ORDER_STATUSES.IN_TRANSIT,
                                    'bg-amber-50 text-amber-600': order.status === ORDER_STATUSES.TO_SHIP || order.status === ORDER_STATUSES.PROCESSING,
                                    'bg-emerald-50 text-emerald-600': order.status === ORDER_STATUSES.DELIVERED,
                                    'bg-red-50 text-red-600': isCancelled
                                }"
                            >
                                {{ order.status }}
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">

                        <!-- Left: Status Hero + History -->
                        <div class="xl:col-span-2 space-y-8">

                            <!-- Cancelled -->
                            <section
                                v-if="isCancelled"
                                class="bg-red-50 rounded-3xl border border-red-100 p-8"
                            >
                                <strong class="text-red-600 block mb-1">This order was cancelled</strong>
                                <p class="text-sm text-red-500">There's nothing to track — cancelled orders don't ship.</p>
                            </section>

                            <template v-else>

                                <!-- Status Hero — replaces the reference's live map, which this app
                                     has no location data to actually back. -->
                                <section
                                    class="rounded-3xl border border-slate-100 p-10 text-center"
                                    style="background: linear-gradient(180deg, var(--nx-accent-soft, #f0fdfa) 0%, #ffffff 100%); box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                                >
                                    <div class="w-20 h-20 rounded-full bg-white text-[#0d9488] flex items-center justify-center mx-auto mb-5 shadow-lg shadow-[#0d9488]/10">
                                        <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="currentStepIcon"></svg>
                                    </div>
                                    <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ currentStepLabel }}</h2>
                                    <p class="text-slate-500 max-w-md mx-auto">{{ currentStepDescription }}</p>
                                    <p
                                        v-if="lastUpdatedLabel"
                                        class="text-xs text-slate-400 mt-4"
                                    >
                                        Last updated {{ lastUpdatedLabel }}
                                    </p>
                                    <p class="text-xs text-slate-400 mt-6 max-w-sm mx-auto border-t border-slate-100 pt-4">
                                        Live courier location isn't available yet — once your order ships, you can follow it directly with {{ order.shipping_carrier || 'the courier' }} using the tracking number above.
                                    </p>
                                </section>

                                <!-- Detailed Tracking History -->
                                <section
                                    class="bg-white rounded-3xl border border-slate-100 overflow-hidden"
                                    style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                                >
                                    <div class="px-8 py-5 border-b border-slate-100">
                                        <h2 class="text-lg font-bold text-slate-900">Detailed Tracking History</h2>
                                    </div>

                                    <div class="p-8">
                                        <div
                                            v-for="row in timeline"
                                            :key="row.step"
                                            class="flex gap-6 relative"
                                            :class="row.index < timeline.length - 1 ? 'pb-10' : ''"
                                        >
                                            <span
                                                v-if="row.index < timeline.length - 1"
                                                class="absolute left-5 top-10 bottom-0 w-0.5"
                                                :class="row.lineToNextCompleted ? 'bg-[#0d9488]' : 'bg-slate-200'"
                                            ></span>

                                            <div
                                                class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 z-10"
                                                :class="row.completed
                                                    ? 'bg-[#0d9488] text-white shadow-lg shadow-[#0d9488]/20'
                                                    : 'bg-slate-100 border-2 border-slate-200 text-slate-300'"
                                            >
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="row.icon"></svg>
                                            </div>

                                            <div class="flex-1">
                                                <div class="flex items-center justify-between gap-3">
                                                    <h4
                                                        class="font-bold"
                                                        :class="row.completed ? 'text-slate-900' : 'text-slate-400'"
                                                    >
                                                        {{ row.label }}
                                                    </h4>
                                                    <span
                                                        v-if="formatDate(row.timestamp)"
                                                        class="text-xs font-semibold text-slate-400 shrink-0"
                                                    >
                                                        {{ formatDate(row.timestamp) }}
                                                    </span>
                                                    <span
                                                        v-else-if="row.isCurrent"
                                                        class="text-xs font-semibold text-[#0d9488] shrink-0"
                                                    >
                                                        Current status
                                                    </span>
                                                </div>
                                                <p
                                                    class="text-sm mt-1"
                                                    :class="row.completed ? 'text-slate-500' : 'text-slate-400'"
                                                >
                                                    {{ row.description }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                            </template>

                        </div>

                        <!-- Right: Address / Shipping Provider / Help -->
                        <div class="space-y-6">

                            <section class="bg-white rounded-3xl p-8 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">Delivery Address</h3>
                                <div class="flex gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400 shrink-0">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" /><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">{{ deliveryAddress.recipient_name || 'Recipient not available' }}</p>
                                        <p class="text-sm text-slate-500 leading-relaxed mt-1">{{ deliveryAddress.address || 'No address available' }}</p>
                                    </div>
                                </div>
                            </section>

                            <section class="bg-white rounded-3xl p-8 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6">Shipping Provider</h3>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-500">Courier</span>
                                        <span class="text-sm font-bold text-slate-900">{{ order.shipping_carrier || 'Not yet assigned' }}</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-500">Tracking No.</span>
                                        <span class="text-sm font-bold text-[#0d9488]">{{ order.tracking_number || 'Not yet available' }}</span>
                                    </div>
                                </div>
                                <p class="text-xs text-slate-400 mt-6 pt-6 border-t border-slate-50 leading-relaxed">
                                    We don't have real-time courier tracking built in yet. For the most precise, up-to-the-minute location, use the tracking number above directly with the courier.
                                </p>
                            </section>

                            <section class="rounded-3xl p-8 shadow-xl" style="background: #0f2f2c;">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-teal-300" style="background: #16423e;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" /><path d="M12 17h.01" />
                                        </svg>
                                    </div>
                                    <h3 class="font-bold text-white">Need assistance?</h3>
                                </div>
                                <p class="text-sm text-teal-100/70 leading-relaxed mb-6">
                                    Having trouble with your delivery? Send us a message and our team will look into it.
                                </p>
                                <a
                                    :href="needHelpMailtoHref"
                                    class="w-full py-3 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-all flex items-center justify-center"
                                >
                                    Email Support
                                </a>
                            </section>

                        </div>

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

    <div
        v-else
        class="empty-products"
    >
        <p>Order not found.</p>
        <button
            type="button"
            class="clear-filters-button"
            @click="emit('back')"
        >
            Back to Orders
        </button>
    </div>

</template>