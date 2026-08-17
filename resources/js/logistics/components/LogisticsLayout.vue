<!-- resources/js/logistics/components/LogisticsLayout.vue -->
<template>
  <div class="logistics-shell">
    <aside class="logistics-sidebar">
      <div class="sidebar-brand">
        <span class="brand-mark">N</span>
        <div>
          <p class="brand-name">NEXMART</p>
          <p class="brand-sub">{{ companyName || 'Logistics' }}</p>
        </div>
      </div>

      <nav class="sidebar-nav">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          class="sidebar-link"
          :class="{ active: activeTab === tab.key }"
          @click="activeTab = tab.key"
        >
          <span v-html="tab.icon"></span>
          {{ tab.label }}
          <span v-if="tab.key === 'applications' && pendingCount > 0" class="sidebar-badge">{{ pendingCount }}</span>
        </button>
      </nav>
    </aside>

    <main class="logistics-main">
      <Dashboard v-if="activeTab === 'dashboard'" />
      <Applications v-else-if="activeTab === 'applications'" />
      <Couriers v-else-if="activeTab === 'couriers'" />
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Dashboard from './Dashboard.vue';
import Applications from './Applications.vue';
import Couriers from './Couriers.vue';
import { useLogistics } from '../composables/useLogistics';

const { companyName, pendingCount, resolveCompany, loadApplications } = useLogistics();

const activeTab = ref('dashboard');

const tabs = [
  { key: 'dashboard', label: 'Dashboard', icon: '📊' },
  { key: 'applications', label: 'Applications', icon: '📝' },
  { key: 'couriers', label: 'Couriers', icon: '🛵' },
];

onMounted(async () => {
  await resolveCompany();
  await loadApplications();
});
</script>

<style scoped>
@import '../../../css/logistics/logistics.css';
</style>