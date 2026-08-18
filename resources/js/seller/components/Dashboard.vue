<!-- resources/js/seller/components/Dashboard.vue -->
<template>
  <div>
    <!-- Quick Actions Launchpad -->
    <div class="card launchpad">
      <p class="launchpad-label">Quick Partner Actions Launchpad</p>
      <div class="launchpad-actions">
        <button class="btn-primary" @click="goTo('account')">
          <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="7" r="3.2"/><path d="M3.5 17c0-3.3 3-5.5 6.5-5.5s6.5 2.2 6.5 5.5"/></svg>
          Edit My Profile
        </button>
        <button class="btn-teal-outline" @click="goTo('account')">
          <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 2.5h6l3 3v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1z"/><path d="M8 10h5M8 13h5M12 2.5V6h3.5"/></svg>
          View Documents
        </button>
        <button class="btn-outline" @click="refresh" :disabled="isRefreshing">
          <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 10a7 7 0 0 1 12-5l1.5 1.5M17 10a7 7 0 0 1-12 5L3.5 13.5"/><path d="M14.5 3v3.5H11M5.5 17v-3.5H9"/></svg>
          {{ isRefreshing ? 'Refreshing…' : 'Refresh Status' }}
        </button>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-stats mb-6">
      <div class="stat-card">
        <div class="stat-card-head">
          <p class="field-label">Account Status</p>
          <span class="stat-dot" :style="{ background: accountStatusColor }"></span>
        </div>
        <p class="stat-value" style="text-transform:capitalize;">{{ profile?.account_status || 'pending' }}</p>
        <p class="stat-sub neutral">Registration: {{ profile?.status || 'pending' }}</p>
      </div>

      <div class="stat-card">
        <div class="stat-card-head">
          <p class="field-label">Documents Verified</p>
          <span class="stat-dot" style="background:var(--teal-500);"></span>
        </div>
        <p class="stat-value">{{ verifiedDocsCount }}/{{ totalDocsCount }}</p>
        <p class="stat-sub" :class="pendingDocsCount > 0 ? 'warn' : 'positive'">
          {{ pendingDocsCount > 0 ? `${pendingDocsCount} pending review` : 'All verified' }}
        </p>
      </div>

      <div class="stat-card">
        <div class="stat-card-head">
          <p class="field-label">Business Category</p>
          <span class="stat-dot" style="background:#3b82f6;"></span>
        </div>
        <p class="stat-value" style="font-size:1.05rem;line-height:1.4;">{{ sellerDetails?.line_of_business || '—' }}</p>
        <p class="stat-sub neutral">{{ sellerDetails?.business_name || 'No business name yet' }}</p>
      </div>

      <div class="stat-card">
        <div class="stat-card-head">
          <p class="field-label">Active Products</p>
          <span class="stat-dot" style="background:#cbd5e1;"></span>
        </div>
        <p class="stat-value">0</p>
        <p class="stat-sub neutral">Inventory module coming soon</p>
      </div>

      <div class="stat-card">
        <div class="stat-card-head">
          <p class="field-label">Total Orders</p>
          <span class="stat-dot" style="background:#cbd5e1;"></span>
        </div>
        <p class="stat-value">0</p>
        <p class="stat-sub neutral">Orders module coming soon</p>
      </div>

      <div class="stat-card">
        <div class="stat-card-head">
          <p class="field-label">Member Since</p>
          <span class="stat-dot" style="background:#f59e0b;"></span>
        </div>
        <p class="stat-value" style="font-size:1.05rem;">{{ formatDate(profile?.created_at) }}</p>
        <p class="stat-sub neutral">Seller partner</p>
      </div>
    </div>

    <!-- Readiness + Compliance -->
    <div class="grid grid-2col mb-6">
      <div class="card" style="padding:1.4rem 1.5rem;">
        <div class="flex items-center justify-between" style="margin-bottom:1rem;">
          <div>
            <p class="section-label">Store Readiness</p>
            <p class="section-sub">Complete these steps to keep your store in good standing.</p>
          </div>
        </div>
        <div class="checklist">
          <div class="checklist-item">
            <span class="checklist-icon" :class="hasProfileInfo ? 'done' : 'todo'">
              <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 10l4 4 8-8"/></svg>
            </span>
            <div>
              <p class="checklist-title">Personal information</p>
              <p class="checklist-desc">Name, sex, birthday, and contact number on file.</p>
            </div>
          </div>
          <div class="checklist-item">
            <span class="checklist-icon" :class="hasAddress ? 'done' : 'todo'">
              <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 10l4 4 8-8"/></svg>
            </span>
            <div>
              <p class="checklist-title">Store address</p>
              <p class="checklist-desc">Province, municipality, barangay, and street address.</p>
            </div>
          </div>
          <div class="checklist-item">
            <span class="checklist-icon" :class="hasBusinessInfo ? 'done' : 'todo'">
              <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 10l4 4 8-8"/></svg>
            </span>
            <div>
              <p class="checklist-title">Business details</p>
              <p class="checklist-desc">Business name and line of business selected.</p>
            </div>
          </div>
          <div class="checklist-item">
            <span class="checklist-icon" :class="verifiedDocsCount === totalDocsCount && totalDocsCount > 0 ? 'done' : 'todo'">
              <svg class="icon-xs" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 10l4 4 8-8"/></svg>
            </span>
            <div>
              <p class="checklist-title">Compliance documents</p>
              <p class="checklist-desc">Valid ID and business permit verified by admin.</p>
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="padding:1.4rem 1.5rem;">
        <p class="section-label">Document Compliance</p>
        <p class="section-sub" style="margin-bottom:1rem;">Today</p>

        <div v-if="totalDocsCount === 0" class="empty-state">
          <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2.5h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-17a1 1 0 0 1 1-1z"/></svg>
          <p>No documents submitted yet.</p>
        </div>
        <div v-else class="donut-wrap">
          <svg width="130" height="130" viewBox="0 0 130 130">
            <circle
              v-for="(seg, idx) in donutSegments"
              :key="idx"
              cx="65" cy="65" r="54"
              fill="none"
              :stroke="seg.color"
              stroke-width="16"
              :stroke-dasharray="`${seg.dash} ${circumference - seg.dash}`"
              :stroke-dashoffset="seg.offset"
              transform="rotate(-90 65 65)"
            />
          </svg>
          <div class="donut-legend">
            <div class="donut-legend-item"><span class="donut-legend-dot" style="background:var(--teal-500);"></span>Verified ({{ verifiedDocsCount }})</div>
            <div class="donut-legend-item"><span class="donut-legend-dot" style="background:#f59e0b;"></span>Pending ({{ pendingDocsCount }})</div>
            <div class="donut-legend-item"><span class="donut-legend-dot" style="background:#ef4444;"></span>Rejected ({{ rejectedDocsCount }})</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Documents + Activity -->
    <div class="grid grid-2col">
      <div class="card" style="padding:1.4rem 1.5rem;">
        <p class="section-label" style="margin-bottom:1rem;">My Documents</p>
        <div v-if="documents.length === 0" class="empty-state">
          <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2.5h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-17a1 1 0 0 1 1-1z"/></svg>
          <p>No documents on file.</p>
          <p class="empty-hint">Documents you submitted during registration will appear here.</p>
        </div>
        <div v-else class="doc-list">
          <div v-for="doc in documents" :key="doc.id" class="doc-row">
            <div class="doc-info">
              <div class="avatar">{{ docTypeLabel(doc.doc_type).slice(0, 2).toUpperCase() }}</div>
              <div>
                <p class="doc-type">{{ docTypeLabel(doc.doc_type) }}</p>
                <p class="doc-date">Submitted {{ formatDate(doc.created_at) }}</p>
              </div>
            </div>
            <span class="badge" :class="statusBadgeClass(doc.status)">{{ doc.status }}</span>
          </div>
        </div>
      </div>

      <div class="card" style="padding:1.4rem 1.5rem;">
        <p class="section-label" style="margin-bottom:1rem;">Live Store Activity Log</p>
        <div v-if="activityLog.length === 0" class="empty-state">
          <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>
          <p>No activity yet.</p>
          <p class="empty-hint">Account status changes will show up here.</p>
        </div>
        <div v-else class="timeline">
          <div v-for="(item, idx) in activityLog" :key="item.id" class="timeline-item">
            <div class="timeline-dot-wrap">
              <span class="timeline-dot"></span>
              <span v-if="idx < activityLog.length - 1" class="timeline-line"></span>
            </div>
            <div>
              <p class="timeline-text">Status changed to <span style="text-transform:capitalize;">{{ item.new_status }}</span></p>
              <p class="timeline-time">{{ formatDateTime(item.created_at) }}<span v-if="item.reason"> — {{ item.reason }}</span></p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useSeller } from '../composables/useSeller';

const {
  profile,
  address,
  sellerDetails,
  documents,
  activityLog,
  verifiedDocsCount,
  pendingDocsCount,
  totalDocsCount,
  refreshAll,
  formatDate,
  formatDateTime,
  docTypeLabel,
  statusBadgeClass,
} = useSeller();

const isRefreshing = ref(false);

async function refresh() {
  isRefreshing.value = true;
  try {
    await refreshAll();
  } finally {
    isRefreshing.value = false;
  }
}

function goTo(section) {
  window.dispatchEvent(new CustomEvent('seller-nav', { detail: section }));
}

const accountStatusColor = computed(() => {
  const s = profile.value?.account_status;
  if (s === 'active') return 'var(--teal-500)';
  if (s === 'suspended') return '#f59e0b';
  if (s === 'deactivated') return '#ef4444';
  return '#94a3b8';
});

const hasProfileInfo = computed(() =>
  Boolean(profile.value?.first_name && profile.value?.last_name && profile.value?.birthday && profile.value?.contact_no)
);
const hasAddress = computed(() => Boolean(address.value?.province_name && address.value?.municipality_name && address.value?.barangay));
const hasBusinessInfo = computed(() => Boolean(sellerDetails.value?.business_name && sellerDetails.value?.line_of_business));

const rejectedDocsCount = computed(() => documents.value.filter(d => d.status === 'rejected').length);

const circumference = 2 * Math.PI * 54;

const donutSegments = computed(() => {
  const total = totalDocsCount.value || 1;
  const parts = [
    { value: verifiedDocsCount.value, color: 'var(--teal-500, #14b8a6)' },
    { value: pendingDocsCount.value, color: '#f59e0b' },
    { value: rejectedDocsCount.value, color: '#ef4444' },
  ].filter(p => p.value > 0);

  let offsetAcc = 0;
  return parts.map(p => {
    const dash = (p.value / total) * circumference;
    const seg = { color: p.color, dash, offset: -offsetAcc };
    offsetAcc += dash;
    return seg;
  });
});
</script>