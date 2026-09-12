<template>
    <div v-if="isLoading" class="portal-state">
        <div
            class="loading-spinner"
            aria-label="Loading logistics portal"
        ></div>
        <p>Checking your logistics account…</p>
    </div>
    <div v-else-if="isAuthenticated" class="logistics-shell">
        <a href="#main-content" class="skip-link">Skip to main content</a>

        <!-- Mobile chrome. On desktop the sidebar carries the brand and
             the account, so this is hidden and nothing is repeated. -->
        <header class="logistics-topbar">
            <button
                type="button"
                class="sidebar-toggle"
                :aria-expanded="sidebarOpen"
                aria-controls="logistics-sidebar"
                aria-label="Toggle navigation"
                @click="sidebarOpen = !sidebarOpen"
            >
                <NavIcon :name="sidebarOpen ? 'close' : 'menu'" :size="20" />
            </button>
            <div class="topbar-brand">
                <span class="brand-mark">N</span>
                <span>{{ companyName || 'BuyTheWay Logistics' }}</span>
            </div>
            <span class="topbar-avatar" :title="profileName">{{
                profileInitials
            }}</span>
        </header>

        <div
            v-if="sidebarOpen"
            class="sidebar-backdrop is-open"
            @click="sidebarOpen = false"
        ></div>

        <aside
            id="logistics-sidebar"
            v-show="sidebarOpen || !isMobile"
            class="logistics-sidebar"
            aria-label="Logistics navigation"
        >
            <div class="sidebar-brand">
                <span class="brand-mark">N</span>
                <div>
                    <p class="brand-name">BuyTheWay</p>
                    <p class="brand-sub">Sorting Center</p>
                </div>
            </div>

            <nav class="sidebar-nav">
                <template v-for="group in navGroups" :key="group.label">
                    <p class="sidebar-nav-label">{{ group.label }}</p>
                    <button
                        v-for="tab in group.tabs"
                        :key="tab.key"
                        type="button"
                        class="sidebar-link"
                        :class="{ active: activeTab === tab.key }"
                        :aria-current="
                            activeTab === tab.key ? 'page' : undefined
                        "
                        @click="selectTab(tab.key)"
                    >
                        <NavIcon :name="tab.icon" />
                        <span>{{ tab.label }}</span>
                        <span
                            v-if="badgeFor(tab.key)"
                            class="sidebar-badge"
                            :aria-label="`${badgeFor(tab.key)} needing attention`"
                            >{{ badgeFor(tab.key) }}</span
                        >
                    </button>
                </template>
            </nav>

            <div class="sidebar-account">
                <div class="profile-avatar" aria-hidden="true">
                    {{ profileInitials }}
                </div>
                <div class="profile-copy">
                    <strong>{{ profileName }}</strong>
                    <span>{{ companyName || 'Logistics company' }}</span>
                </div>
                <button
                    type="button"
                    class="logout-icon"
                    aria-label="Log out"
                    title="Log out"
                    @click="showLogoutConfirm = true"
                >
                    <NavIcon name="logout" :size="17" />
                </button>
            </div>
        </aside>

        <!-- The page component owns its own header (title, subtitle,
             actions). The portal used to stack a second title bar above
             it that repeated the same words, so that has been removed. -->
        <main id="main-content" class="logistics-main">
            <KeepAlive>
                <component
                    :is="activeComponent"
                    v-bind="activeProps"
                    @open-section="selectTab"
                />
            </KeepAlive>
        </main>

        <!-- One toast stack for the whole portal (pages used to each
             render their own, fighting for the same corner). -->
        <div class="toast-stack" role="status" aria-live="polite">
            <TransitionGroup name="toast">
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="toast"
                    :class="toast.type"
                >
                    <NavIcon
                        :name="toast.type === 'error' ? 'alert' : 'check'"
                        :size="16"
                    />
                    <span>{{ toast.message }}</span>
                    <button
                        type="button"
                        class="toast-close"
                        aria-label="Dismiss"
                        @click="dismissToast(toast.id)"
                    >
                        <NavIcon name="close" :size="13" />
                    </button>
                </div>
            </TransitionGroup>
        </div>

        <!-- One confirm dialog for the whole portal. `modal-overlay--top`
             keeps it above page modals that teleport into .logistics-shell
             after it in the DOM (e.g. the delivery-area editor) — without
             it the confirmation renders behind and can't be reached. -->
        <div
            v-if="confirmState"
            class="modal-overlay modal-overlay--top"
            @click.self="resolveConfirm(false)"
        >
            <div
                class="modal-panel modal-sm"
                role="alertdialog"
                aria-modal="true"
            >
                <div class="modal-header">
                    <h3>{{ confirmState.title }}</h3>
                    <button
                        class="modal-close"
                        aria-label="Close"
                        @click="resolveConfirm(false)"
                    >
                        <NavIcon name="close" :size="15" />
                    </button>
                </div>
                <p class="modal-desc">{{ confirmState.message }}</p>
                <div class="modal-actions">
                    <button class="btn-outline" @click="resolveConfirm(false)">
                        {{ confirmState.cancelLabel }}
                    </button>
                    <button
                        :class="
                            confirmState.tone === 'danger'
                                ? 'btn-danger'
                                : 'btn-primary'
                        "
                        @click="resolveConfirm(true)"
                    >
                        {{ confirmState.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="showLogoutConfirm"
            class="modal-overlay modal-overlay--top"
            @click.self="showLogoutConfirm = false"
        >
            <div class="modal-panel modal-sm">
                <div class="modal-header">
                    <h3>Log out of BuyTheWay?</h3>
                    <button
                        class="modal-close"
                        aria-label="Close"
                        @click="showLogoutConfirm = false"
                    >
                        <NavIcon name="close" :size="15" />
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
                        Stay signed in
                    </button>
                    <button class="btn-danger" @click="logout">Log out</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import {
    computed,
    defineAsyncComponent,
    onMounted,
    onUnmounted,
    ref,
} from 'vue';
import { useLogistics } from '../composables/useLogistics';
import { useLogisticsUi } from '../composables/useLogisticsUi';
import Dashboard from './Dashboard.vue';
import NavIcon from './NavIcon.vue';
import PortalPlaceholder from './PortalPlaceholder.vue';

// The section pages are code-split: each is fetched only when its tab is
// first opened, so the initial portal load no longer parses and mounts
// every page (the parcel queue, both rider/area pages, the applications
// desk, account settings) up front. Dashboard is the landing tab, so it
// stays a static import.
const ParcelOperations = defineAsyncComponent(
    () => import('./ParcelOperations.vue'),
);
const DeliveryAreas = defineAsyncComponent(() => import('./DeliveryAreas.vue'));
const Riders = defineAsyncComponent(() => import('./Riders.vue'));
const Applications = defineAsyncComponent(() => import('./Applications.vue'));
const AccountSettings = defineAsyncComponent(
    () => import('./AccountSettings.vue'),
);
const Messages = defineAsyncComponent(() => import('./Messages.vue'));
const CustomerServicePage = defineAsyncComponent(
    () => import('../../shared/CustomerServicePage.vue'),
);

const {
    companyName,
    pendingCount,
    pendingResignationCount,
    pendingTransferCount,
    isLoading,
    isAuthenticated,
    logisticsProfile,
    checkAuth,
    logout,
    resolveCompany,
    loadTransferRequests,
} = useLogistics();
const { toasts, dismissToast, confirmState, resolveConfirm } = useLogisticsUi();

const activeTab = ref('dashboard');
const sidebarOpen = ref(false);
const isMobile = ref(false);
const showLogoutConfirm = ref(false);

const navGroups = [
    {
        label: 'Operations',
        tabs: [
            { key: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
            { key: 'parcels', label: 'Parcel Sorting', icon: 'parcels' },
            { key: 'areas', label: 'Delivery Areas', icon: 'pin' },
            { key: 'riders', label: 'Riders', icon: 'couriers' },
            {
                key: 'applications',
                label: 'Rider Applications',
                icon: 'applications',
            },
        ],
    },
    {
        label: 'Management',
        tabs: [
            { key: 'messages', label: 'Messages', icon: 'messages' },
            {
                key: 'customer-service',
                label: 'Customer Service',
                icon: 'support',
            },
            { key: 'reports', label: 'Reports', icon: 'reports' },
            { key: 'account', label: 'Account Settings', icon: 'account' },
        ],
    },
];
const allTabs = navGroups.flatMap((group) => group.tabs);

const TAB_COMPONENTS = {
    dashboard: Dashboard,
    parcels: ParcelOperations,
    areas: DeliveryAreas,
    riders: Riders,
    applications: Applications,
    messages: Messages,
    'customer-service': CustomerServicePage,
    account: AccountSettings,
};

// Old single "Riders & Areas" tab — keep deep links and any saved
// bookmarks working by landing them on the areas page.
const LEGACY_TAB_ALIASES = { couriers: 'areas' };

// <KeepAlive> above caches whichever of these is mounted, so switching
// away and back no longer tears the page down and refetches everything.
const activeComponent = computed(
    () => TAB_COMPONENTS[activeTab.value] || PortalPlaceholder,
);

// Only the placeholder takes a prop; binding `section` on the real pages
// would leak a stray attribute onto their root element.
const activeProps = computed(() =>
    TAB_COMPONENTS[activeTab.value] ? {} : { section: activeTab.value },
);

/**
 * Rider Applications carries both open applications and resignations;
 * Parcel Sorting carries incoming cross-region transfer requests waiting
 * on this company to accept or reject them.
 */
function badgeFor(key) {
    if (key === 'applications') {
        return pendingCount.value + pendingResignationCount.value;
    }

    if (key === 'parcels') {
        return pendingTransferCount.value;
    }

    return 0;
}

const profileName = computed(
    () =>
        `${logisticsProfile.value?.first_name || ''} ${logisticsProfile.value?.last_name || ''}`.trim() ||
        'Logistics Owner',
);
const profileInitials = computed(
    () =>
        profileName.value
            .split(' ')
            .filter(Boolean)
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase() || 'LG',
);

function selectTab(key) {
    key = LEGACY_TAB_ALIASES[key] || key;

    if (!allTabs.some((tab) => tab.key === key)) {
        return;
    }

    activeTab.value = key;
    window.history.replaceState(
        {},
        '',
        key === 'dashboard' ? '/logistics/dashboard' : `/logistics/${key}`,
    );

    if (isMobile.value) {
        sidebarOpen.value = false;
    }
}

function syncIsMobile() {
    isMobile.value = window.matchMedia('(max-width: 900px)').matches;

    if (!isMobile.value) {
        sidebarOpen.value = false;
    }
}

function handleEscape(event) {
    if (event.key === 'Escape' && sidebarOpen.value) {
        sidebarOpen.value = false;
    }
}

onMounted(async () => {
    syncIsMobile();
    window.addEventListener('resize', syncIsMobile);
    window.addEventListener('keydown', handleEscape);

    const rawTab = window.location.pathname.split('/').filter(Boolean)[1];
    const requestedTab = LEGACY_TAB_ALIASES[rawTab] || rawTab;

    if (allTabs.some((tab) => tab.key === requestedTab)) {
        activeTab.value = requestedTab;
    }

    if (!(await checkAuth())) {
        return;
    }

    // checkAuth already resolved the signed-in profile — hand its id
    // straight over so resolveCompany skips a second auth.getUser() call.
    await resolveCompany(logisticsProfile.value?.id);

    // Best-effort — powers the Parcel Sorting sidebar badge before that
    // tab has been opened. The page itself refetches on mount.
    loadTransferRequests().catch(() => {});
});

onUnmounted(() => {
    window.removeEventListener('resize', syncIsMobile);
    window.removeEventListener('keydown', handleEscape);
});
</script>
