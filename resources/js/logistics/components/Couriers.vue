<!-- resources/js/logistics/components/Couriers.vue -->
<template>
  <div class="logistics-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">Our Couriers</h2>
        <p class="page-subtitle">Couriers currently affiliated with {{ companyName }}.</p>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="table-scroll">
      <table class="admin-table">
        <thead>
          <tr><th>Courier</th><th>Vehicle</th><th>Contact</th><th class="text-right">Actions</th></tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td colspan="4" class="text-center py-8"><div class="loading-spinner" role="status" aria-label="Loading couriers"></div></td></tr>
          <tr v-else-if="couriers.length === 0">
            <td colspan="4">
              <div class="empty-state">
                <svg class="icon-lg empty-state-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 17V8a1 1 0 0 1 1-1h9v10M4 17h10M4 17a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0M14 10h4l3 3v4h-2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <p>No couriers yet — accepted applications will appear here.</p>
              </div>
            </td>
          </tr>
          <tr v-for="c in couriers" :key="c.profile_id">
            <td>
              <div class="flex items-center gap-3">
                <div class="avatar" aria-hidden="true">{{ initials(c) }}</div>
                <div>
                  <p class="font-medium text-slate-800">{{ c.profile?.first_name }} {{ c.profile?.last_name }}</p>
                  <p class="text-xs text-slate-500">{{ c.profile?.email }}</p>
                </div>
              </div>
            </td>
            <td>{{ c.vehicle }} — {{ c.plate_number }}</td>
            <td>{{ c.profile?.contact_no || '—' }}</td>
            <td>
              <button @click="removeCourier(c)" class="btn-danger-outline">Remove</button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const { supabase, companyName, couriers, loadCouriers } = useLogistics();
const loading = ref(false);

async function removeCourier(c) {
  if (!confirm(`Remove ${c.profile?.first_name} from ${companyName.value}?`)) return;
  try {
    const { error: e1 } = await supabase
      .from('courier_details')
      .update({ logistics_company_id: null })
      .eq('profile_id', c.profile_id);
    if (e1) throw e1;

    await supabase
      .from('courier_applications')
      .update({ status: 'rejected', rejection_reason: 'Removed from company by company staff' })
      .eq('courier_profile_id', c.profile_id)
      .eq('status', 'accepted');

    await loadCouriers();
  } catch (e) {
    alert('Failed to remove courier: ' + e.message);
  }
}

function initials(c) {
  const n = `${c.profile?.first_name || ''} ${c.profile?.last_name || ''}`.trim();
  return n.split(' ').filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
}

onMounted(async () => {
  loading.value = true;
  await loadCouriers();
  loading.value = false;
});
</script>

<style scoped>
@import '../../../css/logistics/logistics.css';
</style>