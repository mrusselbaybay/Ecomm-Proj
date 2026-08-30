<template>
    <div v-if="isLoading" class="portal-state">
        <div
            class="loading-spinner"
            aria-label="Loading logistics portal"
        ></div>
        <p>Checking your logistics account...</p>
    </div>
    <div v-else-if="isAuthenticated" class="logistics-shell">
        <a href="#main-content" class="skip-link">Skip to main content</a>
        <header class="logistics-topbar">
            <div class="topbar-brand">
                <span class="brand-mark">N</span
                ><span>{{ companyName || 'NEXMART Logistics' }}</span>
            </div>
            <button
                type="button"
                class="sidebar-toggle"
                :aria-expanded="sidebarOpen"
                @click="sidebarOpen = !sidebarOpen"
            >
                Menu
            </button>
        </header>
        <div
            v-if="sidebarOpen"
            class="sidebar-backdrop is-open"
            @click="sidebarOpen = false"
        ></div>
        <aside
            v-show="sidebarOpen || !isMobile"
            class="logistics-sidebar"
            aria-label="Logistics navigation"
        >
            <div class="sidebar-brand">
                <span class="brand-mark">N</span>
                <div>
                    <p class="brand-name">NEXMART</p>
                    <p class="brand-sub">Sorting Center</p>
                </div>
            </div>
            <nav class="sidebar-nav">
                <p class="sidebar-nav-label">Operations</p>
                <button
                    v-for="tab in operationTabs"
                    :key="tab.key"
                    type="button"
                    class="sidebar-link"
                    :class="{ active: activeTab === tab.key }"
                    @click="selectTab(tab.key)"
                >
                    <span class="nav-symbol" aria-hidden="true">{{
                        tab.symbol
                    }}</span
                    ><span>{{ tab.label }}</span>
                    <span
                        v-if="tab.key === 'applications' && pendingCount"
                        class="sidebar-badge"
                        >{{ pendingCount }}</span
                    >
                </button>
                <p class="sidebar-nav-label sidebar-section-gap">Management</p>
                <button
                    v-for="tab in managementTabs"
                    :key="tab.key"
                    type="button"
                    class="sidebar-link"
                    :class="{ active: activeTab === tab.key }"
                    @click="selectTab(tab.key)"
                >
                    <span class="nav-symbol" aria-hidden="true">{{
                        tab.symbol
                    }}</span
                    ><span>{{ tab.label }}</span>
                </button>
            </nav>
            <div class="sidebar-account">
                <div class="profile-avatar">{{ profileInitials }}</div>
                <div class="profile-copy">
                    <strong>{{ profileName }}</strong
                    ><span>{{ companyName || 'Logistics company' }}</span>
                </div>
                <button
                    class="logout-icon"
                    title="Log out"
                    @click="showLogoutConfirm = true"
                >
                    ↪
                </button>
            </div>
        </aside>
        <main id="main-content" class="logistics-main">
            <header class="portal-header">
                <div>
                    <p class="eyebrow">
                        {{ companyName || 'Logistics Portal' }}
                    </p>
                    <h1>{{ currentLabel }}</h1>
                </div>
                <div class="portal-header-actions">
                    <span class="live-indicator"><i></i> Operations online</span
                    ><button
                        class="btn-outline"
                        @click="showLogoutConfirm = true"
                    >
                        Log out
                    </button>
                </div>
            </header>
            <Dashboard v-if="activeTab === 'dashboard'" />
            <Applications v-else-if="activeTab === 'applications'" />
            <ParcelOperations
                v-else-if="activeTab === 'parcels'"
                @open-section="selectTab"
            />
            <Couriers v-else-if="activeTab === 'couriers'" />
            <PortalPlaceholder v-else :section="activeTab" />
        </main>
        <div
            v-if="showLogoutConfirm"
            class="modal-overlay"
            @click.self="showLogoutConfirm = false"
        >
            <div class="modal-panel modal-sm">
                <div class="modal-header">
                    <h3>Log out of NEXMART?</h3>
                    <button
                        class="modal-close"
                        @click="showLogoutConfirm = false"
                    >
                        ×
                    </button>
                </div>
                <p class="modal-desc">
                    This removes the saved logistics session from this browser.
                </p>
                <div class="modal-actions">
                    <button
                        class="btn-outline"
                        @click="showLogoutConfirm = false"
                    >
                        Stay signed in</button
                    ><button class="btn-danger" @click="logout">Log out</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Applications from './Applications.vue';
import Couriers from './Couriers.vue';
import Dashboard from './Dashboard.vue';
import ParcelOperations from './ParcelOperations.vue';
import PortalPlaceholder from './PortalPlaceholder.vue';
import { useLogistics } from '../composables/useLogistics';

const {
    companyName,
    pendingCount,
    isLoading,
    isAuthenticated,
    logisticsProfile,
    checkAuth,
    logout,
    resolveCompany,
} = useLogistics();
const activeTab = ref('dashboard');
const sidebarOpen = ref(false);
const isMobile = ref(false);
const showLogoutConfirm = ref(false);
const operationTabs = [
    { key: 'dashboard', label: 'Dashboard', symbol: '⌂' },
    { key: 'applications', label: 'Rider Applications', symbol: '◫' },
    { key: 'parcels', label: 'Parcel Sorting', symbol: '▣' },
    { key: 'couriers', label: 'Riders & Areas', symbol: '♙' },
];
const managementTabs = [
    { key: 'messages', label: 'Messages', symbol: '◇' },
    { key: 'reports', label: 'Reports', symbol: '▥' },
    { key: 'account', label: 'Account Settings', symbol: '⚙' },
];
const allTabs = [...operationTabs, ...managementTabs];
const currentLabel = computed(
    () =>
        allTabs.find((tab) => tab.key === activeTab.value)?.label ||
        'Dashboard',
);
const profileName = computed(
    () =>
        `${logisticsProfile.value?.first_name || ''} ${logisticsProfile.value?.last_name || ''}`.trim() ||
        'Logistics Owner',
);
const profileInitials = computed(() =>
    profileName.value
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

function selectTab(key) {
    activeTab.value = key;
    window.history.replaceState(
        {},
        '',
        key === 'dashboard' ? '/logistics/dashboard' : `/logistics/${key}`,
    );
    if (isMobile.value) sidebarOpen.value = false;
}
function syncIsMobile() {
    isMobile.value = window.matchMedia('(max-width: 900px)').matches;
    if (!isMobile.value) sidebarOpen.value = false;
}
onMounted(async () => {
    syncIsMobile();
    window.addEventListener('resize', syncIsMobile);
    const requestedTab = window.location.pathname.split('/').filter(Boolean)[1];
    if (allTabs.some((tab) => tab.key === requestedTab))
        activeTab.value = requestedTab;
    if (!(await checkAuth())) return;
    await resolveCompany();
});
onUnmounted(() => window.removeEventListener('resize', syncIsMobile));
</script>
