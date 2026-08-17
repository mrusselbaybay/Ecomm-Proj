<!-- resources/js/logistics/components/Dashboard.vue -->
<template>
  <div class="logistics-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">Dashboard</h2>
        <p class="page-subtitle">Overview of {{ companyName }}'s courier network.</p>
      </div>
    </div>

    <div class="grid grid-cols-4 gap-4 mb-6">
      <div class="stat-card">
        <p class="field-label">Total Couriers</p>
        <p class="text-2xl font-bold stat-total">{{ couriers.length }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Pending Applications</p>
        <p class="text-2xl font-bold stat-pending">{{ pendingCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Accepted</p>
        <p class="text-2xl font-bold stat-active">{{ acceptedCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Rejected</p>
        <p class="text-2xl font-bold stat-deactivated">{{ rejectedCount }}</p>
      </div>
    </div>

    <div class="card p-6">
      <h3 class="section-label mb-3">Recent Applications</h3>
      <div v-if="recentApplications.length === 0" class="empty-state">
        <p>No applications yet.</p>
      </div>
      <div v-else class="doc-list">
        <div v-for="app in recentApplications" :key="app.id" class="doc-row">
          <div class="doc-info">
            <div class="avatar">{{ initials(app) }}</div>
            <div>
              <p class="doc-type">{{ app.courier?.first_name }} {{ app.courier?.last_name }}</p>
              <p class="doc-date">Applied {{ formatDate(app.applied_at) }}</p>
            </div>
          </div>
          <span class="badge" :class="badgeClass(app.status)">{{ app.status }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const { companyName, applications, couriers, pendingCount, loadApplications, loadCouriers } = useLogistics();

const acceptedCount = computed(() => applications.value.filter(a => a.status === 'accepted').length);
const rejectedCount = computed(() => applications.value.filter(a => a.status === 'rejected').length);
const recentApplications = computed(() => applications.value.slice(0, 5));

function initials(app) {
  const n = `${app.courier?.first_name || ''} ${app.courier?.last_name || ''}`.trim();
  return n.split(' ').filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function badgeClass(status) {
  return {
    'badge-teal': status === 'accepted',
    'badge-amber': status === 'pending',
    'badge-red': status === 'rejected' || status === 'withdrawn',
  };
}

onMounted(async () => {
  await loadApplications();
  await loadCouriers();
});
</script>

<style scoped>
@import '../../../css/logistics/logistics.css';
</style>