<!-- resources/js/logistics/components/LogisticsLayout.vue -->
<template>
  <div class="logistics-shell">
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <!-- Mobile topbar -->
    <header class="logistics-topbar">
      <div class="topbar-brand">
        <span class="brand-mark" aria-hidden="true">N</span>
        <span>{{ companyName || 'Logistics' }}</span>
      </div>
      <button
        type="button"
        class="sidebar-toggle"
        :aria-expanded="sidebarOpen"
        aria-controls="logistics-sidebar"
        aria-label="Toggle navigation menu"
        @click="sidebarOpen = !sidebarOpen"
      >
        <svg class="icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      </button>
    </header>

    <!-- Backdrop for mobile drawer -->
    <transition name="backdrop">
      <div
        v-if="sidebarOpen"
        class="sidebar-backdrop is-open"
        @click="sidebarOpen = false"
      ></div>
    </transition>

    <transition name="sidebar-drawer">
      <aside
        v-show="sidebarOpen || !isMobile"
        id="logistics-sidebar"
        class="logistics-sidebar"
        aria-label="Logistics navigation"
      >
        <div class="sidebar-brand">
          <span class="brand-mark" aria-hidden="true">N</span>
          <div>
            <p class="brand-name">NEXMART</p>
            <p class="brand-sub">{{ companyName || 'Logistics' }}</p>
          </div>
        </div>

        <nav class="sidebar-nav" aria-label="Primary">
          <p class="sidebar-nav-label">Menu</p>
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="sidebar-link"
            :class="{ active: activeTab === tab.key }"
            :aria-current="activeTab === tab.key ? 'page' : undefined"
            @click="selectTab(tab.key)"
          >
            <svg class="icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" v-html="tab.icon"></svg>
            {{ tab.label }}
            <span v-if="tab.key === 'applications' && pendingCount > 0" class="sidebar-badge">{{ pendingCount }}</span>
          </button>
        </nav>

        <p class="sidebar-footer">Logistics Portal &copy; NEXMART</p>
      </aside>
    </transition>

    <main class="logistics-main" id="main-content">
      <Dashboard v-if="activeTab === 'dashboard'" />
      <Applications v-else-if="activeTab === 'applications'" />
      <Couriers v-else-if="activeTab === 'couriers'" />
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import Dashboard from './Dashboard.vue';
import Applications from './Applications.vue';
import Couriers from './Couriers.vue';
import { useLogistics } from '../composables/useLogistics';

const { companyName, pendingCount, resolveCompany, loadApplications } = useLogistics();

const activeTab = ref('dashboard');
const sidebarOpen = ref(false);
const isMobile = ref(false);

const tabs = [
  {
    key: 'dashboard',
    label: 'Dashboard',
    icon: '<path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6V11h-6v9Zm0-16v5h6V4h-6Z" fill="currentColor" />',
  },
  {
    key: 'applications',
    label: 'Applications',
    icon: '<path d="M9 12h6M9 16h6M9 8h1M6 4h8l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none" />',
  },
  {
    key: 'couriers',
    label: 'Couriers',
    icon: '<path d="M4 17V8a1 1 0 0 1 1-1h9v10M4 17h10M4 17a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0M14 10h4l3 3v4h-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" />',
  },
];

function selectTab(key) {
  activeTab.value = key;
  if (isMobile.value) sidebarOpen.value = false;
}

function syncIsMobile() {
  isMobile.value = window.matchMedia('(max-width: 900px)').matches;
  if (!isMobile.value) sidebarOpen.value = false;
}

onMounted(async () => {
  syncIsMobile();
  window.addEventListener('resize', syncIsMobile);
  await resolveCompany();
  await loadApplications();
});

onUnmounted(() => {
  window.removeEventListener('resize', syncIsMobile);
});
</script>

<style scoped>
@import '../../../css/logistics/logistics.css';
</style>
