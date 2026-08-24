<!-- resources/js/seller/components/SellerLayout.vue -->
<template>
    <div class="seller-app">
        <!-- Loading State -->
        <div
            v-if="isLoading"
            class="flex min-h-screen items-center justify-center"
            style="height: 100vh"
        >
            <div class="text-center">
                <div class="loading-spinner mb-4"></div>
                <p class="text-slate-500">Loading seller portal...</p>
            </div>
        </div>

        <!-- Pending Approval -->
        <div
            v-else-if="
                isAuthenticated && isSeller && profile?.status === 'pending'
            "
            class="flex min-h-screen items-center justify-center"
            style="height: 100vh"
        >
            <div class="text-center" style="max-width: 26rem">
                <div class="placeholder-page">
                    <div class="icon-wrap">
                        <svg
                            width="26"
                            height="26"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 7v5l3 3" />
                        </svg>
                    </div>
                    <h3>Application under review</h3>
                    <p>
                        Your seller registration is being reviewed by our team.
                        You'll get an email once it's approved — usually within
                        1-2 business days.
                    </p>
                    <button
                        @click="confirmLogout"
                        class="btn-outline"
                        style="margin-top: 1.25rem"
                    >
                        Log out
                    </button>
                </div>
            </div>
        </div>

        <!-- Rejected -->
        <div
            v-else-if="
                isAuthenticated && isSeller && profile?.status === 'rejected'
            "
            class="flex min-h-screen items-center justify-center"
            style="height: 100vh"
        >
            <div class="text-center" style="max-width: 26rem">
                <div class="placeholder-page">
                    <div
                        class="icon-wrap"
                        style="background: #fef2f2; color: #dc2626"
                    >
                        <svg
                            width="26"
                            height="26"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <circle cx="12" cy="12" r="9" />
                            <path d="M15 9l-6 6M9 9l6 6" />
                        </svg>
                    </div>
                    <h3>Application not approved</h3>
                    <p>
                        {{
                            profile?.rejection_reason ||
                            'Your seller application was not approved. Please contact support for more information.'
                        }}
                    </p>
                    <button
                        @click="confirmLogout"
                        class="btn-outline"
                        style="margin-top: 1.25rem"
                    >
                        Log out
                    </button>
                </div>
            </div>
        </div>

        <!-- Seller Portal -->
        <div
            v-else-if="isAuthenticated && isSeller"
            class="flex min-h-screen"
            style="height: 100vh; overflow: hidden"
        >
            <!-- SIDEBAR -->
            <aside class="seller-sidebar">
                <div class="sidebar-top">
                    <div class="sidebar-logo">
                        <div class="logo-icon">
                            <svg
                                width="20"
                                height="20"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="white"
                                stroke-width="2"
                            >
                                <path
                                    d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"
                                />
                                <path d="M3 6h18" />
                                <path d="M16 10a4 4 0 0 1-8 0" />
                            </svg>
                        </div>
                        <div>
                            <p class="logo-text">NEXMART</p>
                            <p class="logo-sub">Seller Portal</p>
                        </div>
                    </div>

                    <nav class="sidebar-nav">
                        <template v-for="item in navItems" :key="item.id">
                            <div
                                v-if="item.sectionBefore"
                                class="sidebar-section-label"
                            >
                                {{ item.sectionBefore }}
                            </div>
                            <div
                                @click="navigateTo(item.id)"
                                class="sidebar-link"
                                :class="{ active: activeNavId === item.id }"
                            >
                                <span
                                    class="icon-wrap"
                                    v-html="getIcon(item.icon)"
                                ></span>
                                <span class="nav-label">{{ item.label }}</span>
                                <span v-if="item.badge" class="nav-badge">{{
                                    item.badge
                                }}</span>
                            </div>
                        </template>
                    </nav>
                </div>

                <div>
                    <div class="sidebar-divider"></div>
                    <div
                        class="sidebar-link logout"
                        @click="showLogoutConfirm = true"
                    >
                        <span class="icon-wrap">
                            <svg
                                width="18"
                                height="18"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"
                                />
                                <polyline points="16 17 21 12 16 7" />
                                <line x1="21" y1="12" x2="9" y2="12" />
                            </svg>
                        </span>
                        <span class="nav-label">Logout</span>
                    </div>

                    <div class="seller-profile-mini">
                        <div class="profile-avatar">{{ initials }}</div>
                        <div class="profile-info">
                            <p class="profile-name">
                                {{ sellerDetails?.business_name || fullName }}
                            </p>
                            <p class="profile-role">Seller Partner</p>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- MAIN CONTENT -->
            <main class="seller-main">
                <div class="seller-content-wrapper">
                    <!-- Hidden on the Account Settings page only: that page has
                         its own header (see Profile.vue), and the search bar /
                         notification bell / profile summary here would just
                         duplicate what "My Account" already shows in full. -->
                    <div v-if="currentSection !== 'account'" class="content-header">
                        <div>
                            <p class="header-subtitle">Seller Center</p>
                            <h1 class="header-title">{{ sectionLabel }}</h1>
                            <p class="header-breadcrumb">
                                NEXMART Seller Center &gt; {{ sectionLabel }}
                            </p>
                        </div>

                        <div class="header-actions">
                            <div class="header-search">
                                <span class="search-icon">
                                    <svg
                                        width="16"
                                        height="16"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <circle cx="11" cy="11" r="7" />
                                        <path d="m21 21-4.35-4.35" />
                                    </svg>
                                </span>
                                <input
                                    type="text"
                                    placeholder="Search orders, products…"
                                />
                            </div>

                            <div class="header-right">
                                <button class="notif-btn" title="Notifications">
                                    <svg
                                        width="20"
                                        height="20"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path
                                            d="M6 8a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6Z"
                                        />
                                        <path d="M10 20a2 2 0 0 0 4 0" />
                                    </svg>
                                    <span class="notif-dot"></span>
                                </button>
                                <div class="header-profile">
                                    <div class="header-profile-text">
                                        <p class="header-profile-name">
                                            {{
                                                sellerDetails?.business_name ||
                                                fullName
                                            }}
                                        </p>
                                        <p class="header-profile-role">
                                            Seller Partner
                                        </p>
                                    </div>
                                    <div class="header-profile-avatar">
                                        {{ initials }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <component
                        :is="currentComponent"
                        v-bind="currentComponentProps"
                    />
                </div>
            </main>
        </div>

        <!-- Not Authorized -->
        <div
            v-else
            class="flex min-h-screen items-center justify-center"
            style="height: 100vh"
        >
            <div class="text-center">
                <h1 class="mb-2 text-2xl font-bold text-red-600">
                    Access Denied
                </h1>
                <p class="mb-4 text-slate-500">
                    You do not have permission to view this page.
                </p>
                <a href="/" class="text-teal-600 hover:underline"
                    >Return to Home</a
                >
            </div>
        </div>

        <!-- LOGOUT CONFIRM MODAL -->
        <div
            v-if="showLogoutConfirm"
            class="modal-overlay"
            @click.self="showLogoutConfirm = false"
        >
            <div class="modal-panel">
                <div class="modal-header">
                    <h3>Log out?</h3>
                    <button
                        class="modal-close"
                        @click="showLogoutConfirm = false"
                    >
                        <svg class="icon" viewBox="0 0 20 20" fill="none">
                            <path
                                d="M5 5l10 10M15 5L5 15"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>
                    </button>
                </div>
                <p class="modal-desc text-center">
                    You'll need to sign in again to access the seller portal.
                </p>
                <div class="modal-actions">
                    <button
                        @click="showLogoutConfirm = false"
                        class="btn-outline"
                        style="flex: 1"
                    >
                        Cancel
                    </button>
                    <button
                        @click="confirmLogout"
                        class="btn-danger"
                        style="flex: 1"
                    >
                        Log Out
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useSeller } from '../composables/useSeller';

import CourierHandover from './CourierHandover.vue';
import Dashboard from './Dashboard.vue';
import Delivery from './Delivery.vue';
import Feedback from './Feedback.vue';
import Inventory from './Inventory.vue';
import Messages from './Messages.vue';
import OrderDetails from './OrderDetails.vue';
import Orders from './Orders.vue';
import PrepareOrders from './PrepareOrders.vue';
import Profile from './Profile.vue';
import Reports from './Reports.vue';

const showLogoutConfirm = ref(false);
const currentSection = ref('dashboard');
const selectedOrderId = ref(null);

// /seller/orders/{id} is a dynamic sub-route of Orders that isn't a
// literal entry in pathToSection/sectionToPath below (those are 1:1
// static maps). It's matched separately in resolveSection().
const ORDER_DETAILS_PATH = /^\/seller\/orders\/([^/]+)$/;

const {
    isLoading,
    isAuthenticated,
    isSeller,
    profile,
    sellerDetails,
    fullName,
    initials,
    pendingDocsCount,
    checkAuth,
    refreshAll,
    confirmLogout,
} = useSeller();

const pathToSection = {
    '/seller/dashboard': 'dashboard',
    '/seller/inventory': 'inventory',
    '/seller/orders': 'orders',
    '/seller/prepare-orders': 'prepareOrders',
    '/seller/courier-handover': 'courierHandover',
    '/seller/delivery': 'delivery',
    '/seller/feedback': 'feedback',
    '/seller/reports': 'reports',
    '/seller/messages': 'messages',
    '/seller/account': 'account',
};
const sectionToPath = {
    dashboard: '/seller/dashboard',
    inventory: '/seller/inventory',
    orders: '/seller/orders',
    prepareOrders: '/seller/prepare-orders',
    courierHandover: '/seller/courier-handover',
    delivery: '/seller/delivery',
    feedback: '/seller/feedback',
    reports: '/seller/reports',
    messages: '/seller/messages',
    account: '/seller/account',
};

const componentMap = {
    dashboard: Dashboard,
    inventory: Inventory,
    orders: Orders,
    orderDetails: OrderDetails,
    prepareOrders: PrepareOrders,
    courierHandover: CourierHandover,
    delivery: Delivery,
    feedback: Feedback,
    reports: Reports,
    messages: Messages,
    account: Profile,
};

const currentComponent = computed(
    () => componentMap[currentSection.value] || Dashboard,
);

// OrderDetails and PrepareOrders both need to know which order is
// active; every other section ignores v-bind="{}" harmlessly.
const currentComponentProps = computed(() => {
    if (
        currentSection.value === 'orderDetails' ||
        currentSection.value === 'prepareOrders'
    ) {
        return { orderId: selectedOrderId.value };
    }

    return {};
});

// The Orders sidebar link should stay highlighted while viewing a
// single order's details, since that's conceptually still "Orders".
const activeNavId = computed(() =>
    currentSection.value === 'orderDetails' ? 'orders' : currentSection.value,
);

const sectionLabel = computed(() => {
    const labels = {
        dashboard: 'Dashboard',
        inventory: 'Inventory',
        orders: 'Orders',
        orderDetails: 'Order Details',
        prepareOrders: 'Prepare Orders',
        courierHandover: 'Courier Handover',
        delivery: 'Delivery',
        feedback: 'Feedback',
        reports: 'Reports',
        messages: 'Messages',
        account: 'My Account',
    };

    return labels[currentSection.value] || 'Dashboard';
});

const navItems = computed(() => [
    { id: 'dashboard', label: 'Dashboard', icon: 'grid' },
    { id: 'inventory', label: 'Inventory', icon: 'layers' },
    { id: 'orders', label: 'Orders', icon: 'clipboard' },
    { id: 'prepareOrders', label: 'Prepare Orders', icon: 'package' },
    { id: 'courierHandover', label: 'Courier Handover', icon: 'truck' },
    { id: 'delivery', label: 'Delivery', icon: 'pin' },
    { id: 'reports', label: 'Reports', icon: 'bar', sectionBefore: 'Analysis' },
    { id: 'feedback', label: 'Feedback', icon: 'star' },
    {
        id: 'messages',
        label: 'Messages',
        icon: 'mail',
        sectionBefore: 'Communication',
    },
    {
        id: 'account',
        label: 'My Account',
        icon: 'user',
        badge: pendingDocsCount.value > 0 ? pendingDocsCount.value : null,
    },
]);

function getIcon(iconName) {
    const icons = {
        grid: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>`,
        layers: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>`,
        clipboard: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 2h6v3H9zM8 10h8M8 14h8M8 18h5"/></svg>`,
        package: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg>`,
        truck: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="6" width="14" height="11" rx="1"/><path d="M15 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17.5" cy="19" r="2"/></svg>`,
        pin: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>`,
        star: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2Z"/></svg>`,
        bar: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M6 21V10M12 21V4M18 21v-7"/></svg>`,
        mail: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>`,
        user: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>`,
    };

    return icons[iconName] || '';
}

function navigateTo(sectionId, orderId = null) {
    if (sectionId === 'orderDetails') {
        if (!orderId) {
            return;
        }

        selectedOrderId.value = orderId;
        currentSection.value = 'orderDetails';
        const path = `/seller/orders/${orderId}`;

        if (window.location.pathname !== path) {
            window.history.pushState({ section: sectionId, orderId }, '', path);
        }

        return;
    }

    const path = sectionToPath[sectionId];

    if (!path) {
        return;
    }

    // prepareOrders also needs to know which order is active — unlike
    // orderDetails it doesn't get its own /seller/prepare-orders/{id}
    // URL (so refreshing lands back on the plain list), but the id still
    // has to survive in memory for the duration of this navigation.
    if (sectionId === 'prepareOrders') {
        selectedOrderId.value = orderId;
    }

    currentSection.value = sectionId;

    if (window.location.pathname !== path) {
        window.history.pushState({ section: sectionId }, '', path);
    }
}

// Resolves a URL pathname to { section, orderId } for both the static
// 1:1 routes (pathToSection) and the dynamic /seller/orders/{id} route.
function resolveSection(path) {
    const orderMatch = path.match(ORDER_DETAILS_PATH);

    if (orderMatch) {
        return {
            section: 'orderDetails',
            orderId: decodeURIComponent(orderMatch[1]),
        };
    }

    return { section: pathToSection[path] || 'dashboard', orderId: null };
}

function handlePopState() {
    const { section, orderId } = resolveSection(window.location.pathname);
    currentSection.value = section;
    selectedOrderId.value = orderId;
}

// Lets nested components (e.g. Dashboard's quick actions, or Orders.vue
// linking into OrderDetails) request a tab switch without prop-drilling
// a navigate() function through every level. Accepts either a plain
// section string (legacy) or { section, orderId }.
function handleSellerNav(event) {
    const detail = event.detail;

    if (typeof detail === 'string') {
        navigateTo(detail);
    } else if (detail && typeof detail === 'object') {
        navigateTo(detail.section, detail.orderId);
    }
}

onMounted(async () => {
    const initial = resolveSection(window.location.pathname);
    currentSection.value = initial.section;
    selectedOrderId.value = initial.orderId;

    await checkAuth();

    if (isSeller.value) {
        await refreshAll();
    }

    window.addEventListener('popstate', handlePopState);
    window.addEventListener('seller-nav', handleSellerNav);
});

onBeforeUnmount(() => {
    window.removeEventListener('popstate', handlePopState);
    window.removeEventListener('seller-nav', handleSellerNav);
});
</script>

<style scoped>
@import '../../../css/seller/layout.css';
</style>