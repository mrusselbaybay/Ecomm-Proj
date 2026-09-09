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
            <aside class="seller-sidebar" :class="{ collapsed: sidebarCollapsed }">
                <div class="sidebar-top">
                    <div class="sidebar-toggle-row">
                        <button
                            type="button"
                            class="sidebar-toggle-btn"
                            @click="toggleSidebar"
                            :aria-expanded="!sidebarCollapsed"
                            :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                            :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                <path d="M15 5 8 12l7 7" />
                            </svg>
                        </button>
                    </div>
                    <div class="sidebar-logo">
                        <div class="logo-icon">
                            <img
                                :src="collapseLogoUrl"
                                alt="BuyTheWay"
                                class="logo-icon-img"
                            />
                        </div>
                        <div class="logo-full">
                            <img
                                :src="brandLogoUrl"
                                alt="BuyTheWay"
                                class="logo-full-img"
                            />
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
                        @click="navigateTo('account')"
                        class="sidebar-link"
                        :class="{ active: activeNavId === 'account' }"
                    >
                        <span class="icon-wrap" v-html="getIcon('user')"></span>
                        <span class="nav-label">Account</span>
                        <span v-if="pendingDocsCount > 0" class="nav-badge">{{
                            pendingDocsCount
                        }}</span>
                    </div>
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
            <main class="seller-main" :class="{ 'chat-shell': currentSection === 'messages' }">
                <div
                    class="seller-content-wrapper"
                    :class="{
                        'theme-dark':
                            currentSection === 'inventory' ||
                            currentSection === 'dashboard' ||
                            currentSection === 'orders' ||
                            currentSection === 'orderDetails' ||
                            currentSection === 'prepareOrders' ||
                            currentSection === 'courierHandover' ||
                            currentSection === 'delivery' ||
                            currentSection === 'reports' ||
                            currentSection === 'feedback' ||
                            currentSection === 'messages',
                        'chat-shell': currentSection === 'messages',
                    }"
                >
                    <div class="content-header">
                        <div
                            v-if="currentSection === 'dashboard'"
                            class="dash-greeting"
                        >
                            <div class="live-pill">
                                <span class="live-dot"></span> Store active
                            </div>
                            <h1 class="header-title">
                                Good {{ greetingWord }},
                                {{ profile?.first_name || 'Seller' }}
                            </h1>
                            <p class="header-breadcrumb">
                                Here's how
                                {{
                                    sellerDetails?.business_name ||
                                    'your store'
                                }}
                                is doing today, {{ todayLabel }}.
                            </p>
                        </div>
                        <!-- Messages.vue renders its own title + subtitle
                             right at the top of the page (matches the
                             reference's single combined header) — showing
                             "Messages" again here would just repeat it. -->
                        <div v-else-if="currentSection !== 'messages'">
                            <h1 class="header-title">{{ sectionLabel }}</h1>
                        </div>
                        <div v-else></div>

                        <div class="header-actions">
                            <!-- Only rendered where it actually does something —
                                 it used to fall back to an unbound, non-functional
                                 input (no v-model, placeholder only) on every other
                                 section, which just looked like a broken search box
                                 to a seller typing into it on e.g. Courier Handover
                                 or Dashboard. -->
                            <div v-if="currentSection === 'inventory' || currentSection === 'orders'" class="header-search">
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
                                    v-if="currentSection === 'inventory'"
                                    type="text"
                                    v-model="inventorySearchQuery"
                                    placeholder="Search products or SKU"
                                />
                                <input
                                    v-else
                                    type="text"
                                    v-model="orderFilters.search"
                                    placeholder="Search order ID, customer…"
                                />
                            </div>

                            <div class="header-right">
                                <div v-if="currentSection === 'orders'" class="order-filter-wrap">
                                    <button
                                        type="button"
                                        class="header-tool-btn"
                                        :class="{ active: orderFilterOpen }"
                                        @click="toggleOrderFilterPanel"
                                    >
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 6h16M7 12h10M10 18h4" />
                                        </svg>
                                        Filter
                                    </button>

                                    <div
                                        v-if="orderFilterOpen"
                                        class="notif-backdrop"
                                        @click="orderFilterOpen = false"
                                    ></div>
                                    <div v-if="orderFilterOpen" class="order-filter-panel">
                                        <div class="order-filter-panel-head">
                                            <strong>Filters</strong>
                                            <button type="button" class="notif-mark-all" @click="resetOrderFilters">
                                                Clear all
                                            </button>
                                        </div>
                                        <div class="order-filter-panel-body">
                                            <label class="order-filter-field">
                                                <span>Order status</span>
                                                <select v-model="orderFilters.status">
                                                    <option value="">All statuses</option>
                                                    <option
                                                        v-for="(label, value) in ORDER_STATUS_FILTER_OPTIONS"
                                                        :key="value"
                                                        :value="value"
                                                    >{{ label }}</option>
                                                </select>
                                            </label>
                                            <label class="order-filter-field">
                                                <span>Payment</span>
                                                <select v-model="orderFilters.payment_status">
                                                    <option value="">Any payment</option>
                                                    <option value="Paid">Paid</option>
                                                    <option value="Unpaid">Unpaid</option>
                                                    <option value="Refunded">Refunded</option>
                                                </select>
                                            </label>
                                            <div class="order-filter-field-row">
                                                <label class="order-filter-field">
                                                    <span>From</span>
                                                    <input type="date" v-model="orderFilters.date_from" :max="orderFilters.date_to || undefined" />
                                                </label>
                                                <label class="order-filter-field">
                                                    <span>To</span>
                                                    <input type="date" v-model="orderFilters.date_to" :min="orderFilters.date_from || undefined" />
                                                </label>
                                            </div>
                                            <label class="order-filter-field">
                                                <span>Sort by</span>
                                                <select v-model="orderFilters.sort">
                                                    <option value="newest">Newest first</option>
                                                    <option value="oldest">Oldest first</option>
                                                    <option value="total_high">Total: high to low</option>
                                                    <option value="total_low">Total: low to high</option>
                                                </select>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <button
                                    v-if="currentSection === 'orders'"
                                    type="button"
                                    class="header-tool-btn"
                                    @click="exportOrdersCsv"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 3v13m0 0 4-4m-4 4-4-4M5 21h14" />
                                    </svg>
                                    Export
                                </button>
                                <button
                                    v-if="currentSection === 'dashboard'"
                                    class="add-product-btn"
                                    type="button"
                                    @click="navigateTo('inventory')"
                                >
                                    <svg
                                        width="15"
                                        height="15"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <circle cx="10" cy="10" r="8" />
                                        <path d="M10 6v8M6 10h8" />
                                    </svg>
                                    Add Product
                                </button>
                                <div class="notif-wrap">
                                    <button
                                        class="notif-btn"
                                        title="Notifications"
                                        @click="toggleNotifPanel"
                                    >
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
                                        <span
                                            v-if="notifUnread > 0"
                                            class="notif-badge"
                                        >{{ notifUnread > 9 ? '9+' : notifUnread }}</span>
                                    </button>

                                    <Transition name="pop">
                                    <div
                                        v-if="notifOpen"
                                        class="notif-backdrop"
                                        @click="notifOpen = false"
                                    ></div>
                                    </Transition>
                                    <Transition name="pop">
                                    <div v-if="notifOpen" class="notif-panel">
                                        <div class="notif-panel-head">
                                            <strong>Notifications</strong>
                                            <button
                                                v-if="notifUnread > 0"
                                                type="button"
                                                class="notif-mark-all"
                                                @click="markAllNotifRead"
                                            >
                                                Mark all read
                                            </button>
                                        </div>
                                        <p
                                            v-if="notifLoading && !notifItems.length"
                                            class="notif-empty"
                                        >
                                            Loading…
                                        </p>
                                        <p
                                            v-else-if="!notifItems.length"
                                            class="notif-empty"
                                        >
                                            No notifications yet.
                                        </p>
                                        <ul v-else class="notif-list">
                                            <li
                                                v-for="n in notifItems"
                                                :key="n.id"
                                                class="notif-item"
                                                :class="{ unread: !n.read }"
                                                @click="openNotification(n)"
                                            >
                                                <span class="notif-item-title">{{ n.title }}</span>
                                                <span v-if="n.body" class="notif-item-body">{{ n.body }}</span>
                                                <span class="notif-item-time">{{ notifTime(n.createdAt) }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                    </Transition>
                                </div>
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
        <Transition name="modal-fade">
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
        </Transition>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useMessaging } from '../composables/useMessaging';
import { useOrders } from '../composables/useOrders';
import { useSeller } from '../composables/useSeller';
import { useSellerNotifications } from '../composables/useSellerNotifications';
import { useSellerProducts } from '../composables/useSellerProducts';

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

// A bound (not static) `src` so Vite's SFC compiler treats this as a
// plain runtime string instead of trying to resolve/bundle it as a
// module — the file lives in public/images, served as-is by Laravel,
// not through the asset pipeline.
const brandLogoUrl = '/images/BuyTheWay%20Logo.png';
const collapseLogoUrl = '/images/collapse%20logo.png';

const showLogoutConfirm = ref(false);
const currentSection = ref('dashboard');
const selectedOrderId = ref(null);
const ordersStatusFilter = ref(null);

// Collapsed state is a per-device UI preference, not seller data — kept
// in localStorage only, never sent to the server.
const SIDEBAR_COLLAPSED_KEY = 'nexmart:seller-sidebar-collapsed';
const sidebarCollapsed = ref(
    typeof window !== 'undefined' && window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1',
);

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, sidebarCollapsed.value ? '1' : '0');
}

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
    hasUnsavedAccountChanges,
    checkAuth,
    refreshAll,
    confirmLogout,
} = useSeller();

// Polled independently of whether the seller is currently viewing the
// Messages page, so the sidebar badge (see navItems below) stays live
// while they're on Dashboard/Orders/etc. Right now this will just stay
// at 0 — see useMessaging.js's docblock: there is no messaging backend
// deployed yet, so /api/seller/messages/unread-count 404s and the poll
// silently no-ops rather than showing a fake count.
const { unreadBadgeCount, startUnreadPolling, stopUnreadPolling } = useMessaging();

// The shared header search is otherwise decorative (no page behind it to
// search) — on Inventory it binds directly to the real product search
// state instead of duplicating a second, disconnected search box.
const { searchQuery: inventorySearchQuery } = useSellerProducts();

// Seller notification inbox (new-order alerts, status changes) — the
// header bell.
const {
    items: notifItems,
    unreadCount: notifUnread,
    isLoading: notifLoading,
    loadNotifications,
    markRead: markNotifRead,
    markAllRead: markAllNotifRead,
    startPolling: startNotifPolling,
    stopPolling: stopNotifPolling,
} = useSellerNotifications();

// Orders' search box, Filter popover, and Export button — hosted here
// (rather than in Orders.vue's own layout) so they sit in the page
// header row, matching the reference. orderFilters/resetOrderFilters/
// orders are the same shared refs Orders.vue reads, so there is one
// real filter state, not a duplicate.
const {
    orders: ordersForExport,
    orderFilters,
    resetOrderFilters,
} = useOrders();

// Seller only wants these 4 stages offered in the filter (not every
// granular status the pipeline can be in) — statusLabel()/STATUS_LABELS
// elsewhere still resolve the full set for badges, kanban, etc.
const ORDER_STATUS_FILTER_OPTIONS = {
    New: 'New',
    Processing: 'Processing',
    'In Transit': 'In Transit',
    Delivered: 'Delivered',
};

const orderFilterOpen = ref(false);

function toggleOrderFilterPanel() {
    orderFilterOpen.value = !orderFilterOpen.value;
}

function csvCell(value) {
    const s = String(value ?? '');

    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

// Real client-side export of whatever the seller currently has loaded
// (already filtered by orderFilters) — no server export endpoint exists,
// so this builds the CSV from the same real `orders` array Orders.vue
// renders, rather than adding a button that does nothing.
function exportOrdersCsv() {
    const rows = [
        ['Order', 'Buyer', 'Date', 'Status', 'Payment method', 'Payment status', 'Total'],
        ...ordersForExport.value.map((o) => [
            o.id,
            o.customer || '',
            o.date || '',
            o.status || '',
            o.paymentMethod || '',
            o.paymentStatus || '',
            o.total ?? 0,
        ]),
    ];

    const csv = rows.map((r) => r.map(csvCell).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `orders-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

const notifOpen = ref(false);

function toggleNotifPanel() {
    notifOpen.value = !notifOpen.value;

    if (notifOpen.value) {
        loadNotifications();
    }
}

function openNotification(n) {
    markNotifRead(n.id);
    notifOpen.value = false;

    if (n.orderNumber) {
        navigateTo('orderDetails', n.orderNumber);
    }
}

function notifTime(iso) {
    if (!iso) {
        return '';
    }

    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.round(diff / 60000);

    if (mins < 1) {
        return 'just now';
    }

    if (mins < 60) {
        return `${mins}m ago`;
    }

    if (mins < 1440) {
        return `${Math.round(mins / 60)}h ago`;
    }

    return new Date(iso).toLocaleDateString();
}

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
// active; Orders needs to know an optional incoming status filter (see
// Reports.vue's order-breakdown click-through); every other section
// ignores v-bind="{}" harmlessly.
const currentComponentProps = computed(() => {
    if (
        currentSection.value === 'orderDetails' ||
        currentSection.value === 'prepareOrders'
    ) {
        return { orderId: selectedOrderId.value };
    }

    if (currentSection.value === 'orders') {
        return { statusFilter: ordersStatusFilter.value };
    }

    return {};
});

// The Orders sidebar link should stay highlighted while viewing a
// single order's details, since that's conceptually still "Orders".
const activeNavId = computed(() =>
    currentSection.value === 'orderDetails' ? 'orders' : currentSection.value,
);

const greetingWord = computed(() => {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'morning';
    }

    if (hour < 18) {
        return 'afternoon';
    }

    return 'evening';
});

const todayLabel = computed(() =>
    new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'short',
        day: 'numeric',
    }),
);

const sectionLabel = computed(() => {
    const labels = {
        dashboard: 'Dashboard',
        inventory: 'Products & Inventory',
        orders: 'Orders',
        orderDetails: 'Order Details',
        prepareOrders: 'Order Preparation',
        courierHandover: 'Courier Handover',
        delivery: 'Deliveries',
        feedback: 'Reviews',
        reports: 'Reports',
        messages: 'Messages',
        account: 'Account',
    };

    return labels[currentSection.value] || 'Dashboard';
});

// Grouped to match the seller's actual workflow: land on Dashboard, run
// the catalog/fulfillment pipeline in Management, then step back for
// Insights, then Support. Account/Logout live in the sidebar footer
// (see template) rather than here, since they aren't part of the daily
// workflow the way these sections are.
const navItems = computed(() => [
    { id: 'dashboard', label: 'Dashboard', icon: 'grid', sectionBefore: 'Overview' },
    { id: 'inventory', label: 'Products & Inventory', icon: 'layers', sectionBefore: 'Management' },
    { id: 'orders', label: 'Orders', icon: 'clipboard' },
    { id: 'prepareOrders', label: 'Order Preparation', icon: 'package' },
    { id: 'courierHandover', label: 'Courier Handover', icon: 'truck' },
    { id: 'delivery', label: 'Deliveries', icon: 'pin' },
    { id: 'reports', label: 'Reports', icon: 'bar', sectionBefore: 'Insights' },
    { id: 'feedback', label: 'Reviews', icon: 'star' },
    {
        id: 'messages',
        label: 'Messages',
        icon: 'mail',
        sectionBefore: 'Support',
        badge: unreadBadgeCount.value > 0 ? unreadBadgeCount.value : null,
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

function navigateTo(sectionId, orderId = null, statusFilter = null) {
    // The Account Settings section is unmounted on nav, so leaving it
    // with unsaved edits would silently drop them — confirm first.
    if (
        currentSection.value === 'account' &&
        sectionId !== 'account' &&
        hasUnsavedAccountChanges.value &&
        !window.confirm('Discard unsaved changes to your account settings?')
    ) {
        return;
    }

    if (sectionId === 'orderDetails') {
        if (!orderId) {
            return;
        }

        // The list shows order numbers as "#SN-1234"; a leading "#" in
        // the path is a URL fragment, so strip it before it reaches the
        // address bar (the API accepts both forms). This keeps
        // /seller/orders/SN-1234 refreshable and shareable.
        const cleanId = String(orderId).replace(/^#/, '');

        selectedOrderId.value = cleanId;
        currentSection.value = 'orderDetails';
        const path = `/seller/orders/${cleanId}`;

        if (window.location.pathname !== path) {
            window.history.pushState({ section: sectionId, orderId: cleanId }, '', path);
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
        selectedOrderId.value = orderId ? String(orderId).replace(/^#/, '') : orderId;
    }

    // Real status string (e.g. 'Delivered') from Reports.vue's order
    // breakdown click-through — cleared on every other navigation so a
    // stale filter from a previous visit never silently re-applies.
    ordersStatusFilter.value = sectionId === 'orders' ? statusFilter : null;

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

// Lets nested components (e.g. Dashboard's quick actions, Orders.vue
// linking into OrderDetails, or Reports.vue's order-breakdown
// click-through) request a tab switch without prop-drilling a
// navigate() function through every level. Accepts either a plain
// section string (legacy) or { section, orderId, statusFilter }.
function handleSellerNav(event) {
    const detail = event.detail;

    if (typeof detail === 'string') {
        navigateTo(detail);
    } else if (detail && typeof detail === 'object') {
        navigateTo(detail.section, detail.orderId, detail.statusFilter);
    }
}

onMounted(async () => {
    const initial = resolveSection(window.location.pathname);
    currentSection.value = initial.section;
    selectedOrderId.value = initial.orderId;

    await checkAuth();

    if (isSeller.value) {
        await refreshAll();
        startUnreadPolling();
        startNotifPolling();
    }

    window.addEventListener('popstate', handlePopState);
    window.addEventListener('seller-nav', handleSellerNav);
});

onBeforeUnmount(() => {
    window.removeEventListener('popstate', handlePopState);
    window.removeEventListener('seller-nav', handleSellerNav);
    stopUnreadPolling();
    stopNotifPolling();
});
</script>

<style scoped>
@import '../../../css/seller/layout.css';

/* ============================================================
   BuyTheWay shell redesign — near-black / teal system.

   Scoped entirely to elements SellerLayout.vue renders itself
   (sidebar + top header chrome). Every other page's own content
   keeps using the existing teal design-system classes from
   layout.css untouched — only the shell around it changes here.
   ============================================================ */
.seller-app {
    --nx-side-bg: #0f1210;
    --nx-side-ink: #c7cbc6;
    --nx-side-dim: #697068;
    --nx-side-active: #e9f5ee;
    --nx-accent: #0f766e;
    --nx-accent-strong: #0b5f58;
    --nx-accent-soft: rgba(15, 118, 110, 0.16);
    --nx-ink-900: #14171a;
    --nx-ink-500: #6d737a;
    --nx-border: #e7e7e0;
}

/* ---- sidebar ---- */
.seller-sidebar {
    background-color: var(--nx-side-bg);
    background-image: none;
}
.logo-icon {
    background: transparent;
    padding: 0;
    box-shadow: none;
}
.logo-icon-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.logo-sub {
    color: var(--nx-side-dim);
}

/* Expanded sidebar shows the real BuyTheWay logo file (icon + wordmark
   baked into one image); collapsed sidebar falls back to the small
   vector .logo-icon badge above, since the full lockup is too wide for
   the icon-only rail. */
.seller-sidebar:not(.collapsed) .logo-icon {
    display: none;
}
.seller-sidebar:not(.collapsed) .sidebar-logo {
    justify-content: center;
}
.logo-full {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.3rem;
    min-width: 0;
}
.logo-full-img {
    display: block;
    width: 6rem;
    max-width: 100%;
    height: auto;
}
.seller-sidebar.collapsed .logo-full {
    display: none;
}
.sidebar-section-label {
    color: var(--nx-side-dim);
}
.sidebar-link {
    color: var(--nx-side-ink);
    border-right: 3px solid transparent;
}
.sidebar-link:hover {
    background: rgba(255, 255, 255, 0.06);
    color: #fff;
}
.sidebar-link.active {
    background: var(--nx-accent-soft);
    border-right-color: var(--nx-accent);
    color: var(--nx-side-active);
}
.sidebar-link.active .icon-wrap {
    color: #2dd4bf;
}
.sidebar-link.logout:hover {
    color: #f2a8a3;
    background: rgba(200, 67, 61, 0.12);
}
.sidebar-divider {
    border-top-color: rgba(255, 255, 255, 0.08);
}
.profile-avatar {
    background: rgba(15, 118, 110, 0.24);
    color: #99f6e4;
}
.profile-name {
    color: #fff;
}
.profile-role {
    color: var(--nx-side-dim);
}

/* ---- top header ---- */
.header-title {
    color: var(--nx-ink-900);
}
.header-breadcrumb {
    color: var(--nx-ink-500);
}
.header-search input:focus {
    border-color: var(--nx-accent);
    box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.18);
}
.header-profile-avatar {
    background: linear-gradient(
        135deg,
        var(--nx-accent),
        var(--nx-accent-strong)
    );
}
.header-profile-role {
    color: var(--nx-accent-strong);
}

/* ---- Dark-themed content area — Inventory and Dashboard's cards
   are dark (see Inventory.vue / Dashboard.vue), so the shared
   header/wrapper needs a matching dark background here instead of
   the default light one, otherwise the dark cards float on a
   mismatched light strip. Scoped to these two sections only; every
   other page keeps the default light .seller-content-wrapper. ---- */
.seller-content-wrapper.theme-dark {
    background: #0f1210;
    min-height: 100%;
}
.seller-content-wrapper.theme-dark .header-title {
    color: #f2f4f1;
}
.seller-content-wrapper.theme-dark .header-breadcrumb {
    color: #97a099;
}
.seller-content-wrapper.theme-dark .header-search input {
    background: #1d231e;
    border-color: rgba(255, 255, 255, 0.08);
    color: #f2f4f1;
}
.seller-content-wrapper.theme-dark .header-search input::placeholder {
    color: #6d766e;
}
.seller-content-wrapper.theme-dark .header-search .search-icon {
    color: #6d766e;
}
.seller-content-wrapper.theme-dark .notif-btn {
    color: #97a099;
}
.seller-content-wrapper.theme-dark .header-profile-name {
    color: #f2f4f1;
}
.seller-content-wrapper.theme-dark .header-profile-role {
    color: #5eead4;
}
.seller-content-wrapper.theme-dark .dash-greeting .live-pill {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
}

/* ---- Messages ("chat-shell") — every other page is fine scrolling as
   part of .seller-main's own page-level scroll, but Messages.vue is a
   3-pane chat frame that needs a FIXED height so switching conversations
   (a 2-message thread vs. a 40-message one, an order with 2 timeline
   steps vs. none) never grows or shrinks the whole page — only the
   panels' own internal overflow should ever move. Pinning both this
   wrapper and .seller-main to the viewport height, instead of letting
   .seller-main scroll, is what makes that possible. ---- */
.seller-main.chat-shell {
    height: 100vh;
    overflow: hidden;
}
.seller-content-wrapper.chat-shell {
    display: flex;
    flex-direction: column;
    height: 100vh;
    min-height: 0;
}
.seller-content-wrapper.chat-shell .content-header {
    flex-shrink: 0;
}

.dash-greeting .live-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.28rem 0.7rem;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.1);
    color: var(--nx-accent-strong);
    font-size: 0.72rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.add-product-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.55rem 1rem;
    border-radius: 999px;
    border: none;
    background: var(--nx-accent);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 8px 18px -8px rgba(15, 118, 110, 0.55);
    transition: background 0.15s ease;
}
.add-product-btn:hover {
    background: var(--nx-accent-strong);
}

/* Header notification bell panel */
.notif-wrap {
    position: relative;
}

/* ---- Orders header tools (Filter popover + Export) — only ever shown
   for the orders section, which is always dark-themed, so these are
   styled dark directly rather than gated by .theme-dark. ---- */
.header-tool-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.9rem;
    border-radius: 0.6rem;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: rgba(255, 255, 255, 0.04);
    color: #cdd3cd;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
.header-tool-btn:hover {
    border-color: rgba(255, 255, 255, 0.22);
    color: #f2f4f1;
}
.header-tool-btn.active {
    background: rgba(15, 118, 110, 0.22);
    border-color: rgba(15, 118, 110, 0.45);
    color: #5eead4;
}
.order-filter-wrap {
    position: relative;
}
.order-filter-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 50;
    width: 19rem;
    background: #1d231e;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.85rem;
    box-shadow: 0 16px 36px -10px rgba(0, 0, 0, 0.55);
}
.order-filter-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    color: #f2f4f1;
    font-size: 0.85rem;
}
.order-filter-panel-body {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    padding: 1rem;
}
.order-filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    flex: 1;
    min-width: 0;
}
.order-filter-field span {
    font-size: 0.68rem;
    font-weight: 700;
    color: #97a099;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.order-filter-field select,
.order-filter-field input {
    padding: 0.5rem 0.6rem;
    border-radius: 0.5rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: #161b17;
    color: #f2f4f1;
    font: inherit;
    font-size: 0.82rem;
}
.order-filter-field-row {
    display: flex;
    gap: 0.7rem;
}

.notif-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: #dc2626;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    line-height: 16px;
    text-align: center;
}

.notif-backdrop {
    position: fixed;
    inset: 0;
    z-index: 40;
}

.notif-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 50;
    width: 320px;
    max-height: 420px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    box-shadow: 0 12px 32px -8px rgba(15, 23, 42, 0.25);
}

.notif-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.7rem 0.9rem;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}

.notif-mark-all {
    border: 0;
    background: none;
    color: #0f766e;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
}

.notif-empty {
    padding: 1.5rem 0.9rem;
    text-align: center;
    color: #94a3b8;
    font-size: 0.82rem;
}

.notif-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.notif-item {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.7rem 0.9rem;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
}

.notif-item:hover {
    background: #f8fafc;
}

.notif-item.unread {
    background: #f0fdfa;
}

.notif-item-title {
    font-size: 0.82rem;
    font-weight: 600;
    color: #1e293b;
}

.notif-item-body {
    font-size: 0.75rem;
    color: #64748b;
}

.notif-item-time {
    font-size: 0.68rem;
    color: #94a3b8;
}
</style>