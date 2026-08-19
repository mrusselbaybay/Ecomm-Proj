<!-- resources/js/seller/components/Dashboard.vue -->
<template>
  <div>
    <!-- Quick Actions Launchpad -->
    <div class="card launchpad">
      <div class="launchpad-head">
        <span class="launchpad-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8Z"/></svg>
        </span>
        <p class="launchpad-label">Quick Actions Launchpad</p>
      </div>
      <div class="launchpad-actions">
        <button class="btn-primary" @click="goTo('inventory')">
          <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="10" r="8"/><path d="M10 6v8M6 10h8"/></svg>
          Add New Product
        </button>
        <button class="btn-outline" @click="goTo('orders')">
          <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="3" width="12" height="15" rx="1.5"/><path d="M7.5 1.5h5v3h-5zM7 9h6M7 12h6M7 15h4"/></svg>
          Manage Active Orders
        </button>
        <button class="btn-blue-outline" @click="goTo('reports')">
          <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 2.5h6l3 3v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1z"/><path d="M8 10h5M8 13h5M12 2.5V6h3.5"/></svg>
          Generate Sales Report
        </button>
      </div>
    </div>

    <!-- Metric Cards -->
    <div class="grid metric-grid">
      <div class="metric-card" title="Placeholder — connect to real sales data once available">
        <div class="metric-card-top">
          <span class="metric-icon emerald"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg></span>
          <span class="metric-chip up">{{ mockMetrics.salesChange }}</span>
        </div>
        <p class="metric-label">Total Sales</p>
        <h4 class="metric-value">{{ mockMetrics.totalSales }}</h4>
      </div>

      <div class="metric-card" title="Placeholder — connect to real revenue data once available">
        <div class="metric-card-top">
          <span class="metric-icon sky"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5.5c0-1.9-2.2-3.5-5-3.5s-5 1.6-5 3.5S9.2 9 12 9s5 1.6 5 3.5-2.2 3.5-5 3.5-5-1.6-5-3.5"/></svg></span>
          <span class="metric-chip up">{{ mockMetrics.revenueChange }}</span>
        </div>
        <p class="metric-label">Total Revenue</p>
        <h4 class="metric-value">{{ mockMetrics.totalRevenue }}</h4>
      </div>

      <div class="metric-card" title="Placeholder — orders module not yet connected">
        <div class="metric-card-top">
          <span class="metric-icon orange"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg></span>
          <span class="metric-chip flat">Tracked</span>
        </div>
        <p class="metric-label">Total Orders</p>
        <h4 class="metric-value">0 Items</h4>
      </div>

      <div class="metric-card">
        <div class="metric-card-top">
          <span class="metric-icon blue"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg></span>
          <span class="metric-chip flat">Stable</span>
        </div>
        <p class="metric-label">Active Products</p>
        <h4 class="metric-value">0 Listed</h4>
      </div>

      <div class="metric-card">
        <div class="metric-card-top">
          <span class="metric-icon amber"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg></span>
          <span class="metric-chip up">{{ pendingDocsCount }} pending</span>
        </div>
        <p class="metric-label">Pending Orders</p>
        <h4 class="metric-value">0 Orders</h4>
      </div>

      <div class="metric-card" title="Placeholder — connect to real inventory stock levels once available">
        <div class="metric-card-top">
          <span class="metric-icon rose"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.3 3.3 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>
          <span class="metric-chip down">{{ mockMetrics.lowStockChange }}</span>
        </div>
        <p class="metric-label">Low Stock</p>
        <h4 class="metric-value">{{ mockMetrics.lowStockCount }} Alerted</h4>
      </div>
    </div>

    <!-- Sales Trend + Order Breakdown -->
    <div class="grid chart-row">
      <div class="card chart-card">
        <div class="chart-card-head">
          <div>
            <p class="chart-title">Sales Performance Trend</p>
            <p class="chart-sub">Revenue growth over the past 7 days <span class="mock-tag">(sample data)</span></p>
          </div>
          <div class="chart-toggle">
            <button class="active" type="button">Weekly</button>
            <button type="button">Monthly</button>
          </div>
        </div>
        <div style="height:220px;width:100%;position:relative;">
          <svg viewBox="0 0 800 200" style="width:100%;height:100%;overflow:visible;">
            <defs>
              <linearGradient id="seller-line-gradient" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#1b9ba8" stop-opacity="0.3"></stop>
                <stop offset="100%" stop-color="#1b9ba8" stop-opacity="0"></stop>
              </linearGradient>
            </defs>
            <path d="M0,150 L100,80 L200,120 L300,140 L400,50 L500,80 L600,160 L800,90" fill="none" stroke="#1b9ba8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M0,150 L100,80 L200,120 L300,140 L400,50 L500,80 L600,160 L800,90 V200 H0 Z" fill="url(#seller-line-gradient)"></path>
            <circle cx="400" cy="50" r="6" fill="#1b9ba8" stroke="white" stroke-width="3"></circle>
          </svg>
        </div>
        <div class="chart-x-labels">
          <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
        </div>
      </div>

      <div class="card chart-card">
        <div class="chart-card-head">
          <p class="chart-title">Order Breakdown</p>
          <span class="chart-live-tag">Sample</span>
        </div>
        <div class="flex items-center justify-center" style="flex-direction:column;">
          <div style="position:relative;width:9.5rem;height:9.5rem;margin-bottom:1.5rem;">
            <svg viewBox="0 0 36 36" style="width:100%;height:100%;transform:rotate(-90deg);">
              <circle cx="18" cy="18" r="15.9" fill="transparent" stroke="#e2e8f0" stroke-width="4"></circle>
              <circle cx="18" cy="18" r="15.9" fill="transparent" stroke="#1b9ba8" stroke-width="4" stroke-dasharray="68 32" stroke-dashoffset="0"></circle>
              <circle cx="18" cy="18" r="15.9" fill="transparent" stroke="#2c5aa0" stroke-width="4" stroke-dasharray="22 78" stroke-dashoffset="-68"></circle>
              <circle cx="18" cy="18" r="15.9" fill="transparent" stroke="#f87171" stroke-width="4" stroke-dasharray="10 90" stroke-dashoffset="-90"></circle>
            </svg>
            <div class="flex items-center justify-center" style="position:absolute;inset:0;flex-direction:column;">
              <span class="donut-center-value">0</span>
              <span class="donut-center-label">Orders</span>
            </div>
          </div>
          <div style="width:100%;">
            <div class="donut-legend-row">
              <div class="flex items-center gap-2"><span class="legend-dot" style="background:#1b9ba8;"></span><span>Delivered</span></div>
              <strong>68%</strong>
            </div>
            <div class="donut-legend-row">
              <div class="flex items-center gap-2"><span class="legend-dot" style="background:#2c5aa0;"></span><span>In Transit</span></div>
              <strong>22%</strong>
            </div>
            <div class="donut-legend-row">
              <div class="flex items-center gap-2"><span class="legend-dot" style="background:#f87171;"></span><span>Processing</span></div>
              <strong>10%</strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Sales Records + Live Store Activity -->
    <div class="grid bottom-grid mb-6">
      <div class="card panel-card">
        <div class="panel-head">
          <h3>Recent Sales Records <span class="mock-tag">(sample data)</span></h3>
          <a href="#" class="panel-link" @click.prevent="goTo('orders')">View All Transactions</a>
        </div>
        <div style="overflow-x:auto;">
          <table class="sales-table">
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Date</th>
                <th>Amount</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in mockSalesRecords" :key="row.id">
                <td class="order-id">{{ row.id }}</td>
                <td class="customer">{{ row.customer }}</td>
                <td class="item-name">{{ row.item }}</td>
                <td>{{ row.date }}</td>
                <td class="amount">{{ row.amount }}</td>
                <td><span class="badge" :class="row.badgeClass">{{ row.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card panel-card">
        <div class="panel-head">
          <h3>Live Store Activity</h3>
          <div class="flex items-center gap-3">
            <button class="notif-btn" title="Refresh status" style="padding:0.3rem;" @click="refresh" :disabled="isRefreshing">
              <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" :style="{ animation: isRefreshing ? 'spin 0.7s linear infinite' : 'none' }"><path d="M3 10a7 7 0 0 1 12-5l1.5 1.5M17 10a7 7 0 0 1-12 5L3.5 13.5"/><path d="M14.5 3v3.5H11M5.5 17v-3.5H9"/></svg>
            </button>
            <span class="live-dot"></span>
          </div>
        </div>
        <div class="activity-panel">
          <div v-if="activityLog.length === 0" class="empty-state" style="padding:1rem 0;">
            <p>No activity yet.</p>
            <p class="empty-hint">Account status changes will show up here.</p>
          </div>
          <div v-for="item in visibleActivityLog" :key="item.id" class="activity-row">
            <div class="activity-icon-badge teal">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
            </div>
            <div class="flex-1">
              <p class="activity-text">Status changed to <span style="text-transform:capitalize;">{{ item.new_status }}</span></p>
              <p class="activity-time">{{ formatDateTime(item.created_at) }}<span v-if="item.reason"> — {{ item.reason }}</span></p>
            </div>
          </div>
          <button v-if="visibleActivityLog.length" class="activity-clear-btn" @click="visibleActivityLog = []">
            Clear Activity Log
          </button>
        </div>
      </div>
    </div>

    <!-- Account & Compliance (existing onboarding functionality, preserved) -->
    <p class="section-label" style="margin-bottom:0.85rem;">Account &amp; Compliance</p>
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

    <!-- Documents -->
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
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
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

// ---------------------------------------------------------------
// MOCK DATA — the seller composable does not yet expose sales,
// revenue, order-status, or stock data. These values are isolated
// placeholders that reproduce the ShopNova reference visuals; swap
// them for real queries (e.g. an `orders`/`sales` table via Supabase)
// once that module ships. Nothing here is written back to the DB.
// ---------------------------------------------------------------
const mockMetrics = ref({
  totalSales: '$0.00',
  salesChange: '+0.0%',
  totalRevenue: '$0.00',
  revenueChange: '+0.0%',
  lowStockCount: 0,
  lowStockChange: '0.0%',
});

// Sample rows only, used to preview the table layout — the panel header
// is labeled "(sample data)" so it's never mistaken for real order history.
const mockSalesRecords = ref([
  { id: '#SN-00001', customer: 'Sample Customer', item: 'Sample product', date: '—', amount: '$0.00', status: 'Delivered', badgeClass: 'badge-emerald' },
  { id: '#SN-00002', customer: 'Sample Customer', item: 'Sample product', date: '—', amount: '$0.00', status: 'In Transit', badgeClass: 'badge-sky' },
  { id: '#SN-00003', customer: 'Sample Customer', item: 'Sample product', date: '—', amount: '$0.00', status: 'Processing', badgeClass: 'badge-amber' },
]);

// Local, non-destructive view of the real activity log so "Clear Activity
// Log" only clears what's on screen — it never mutates the underlying
// composable state or deletes anything server-side.
const visibleActivityLog = ref([]);
watch(activityLog, (val) => {
 visibleActivityLog.value = [...val]; 
}, { immediate: true });

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