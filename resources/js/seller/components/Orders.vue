<!-- resources/js/seller/components/Orders.vue -->
<template>
  <div class="order-page">
    <!-- New Order Alert -->
    <div v-if="newOrdersCount > 0" class="order-alert">
      <div class="order-alert-left">
        <span class="order-alert-dot"></span>
        <p>You have {{ newOrdersCount }} New Purchase Order{{ newOrdersCount === 1 ? '' : 's' }} awaiting approval!</p>
      </div>
      <button class="btn-primary btn-sm" @click="activeFilter = 'new'">View New</button>
    </div>

    <!-- Toolbar: filters + search -->
    <div class="order-toolbar">
      <div class="order-filter-tabs">
        <button
          v-for="tab in filterTabs"
          :key="tab.id"
          class="order-filter-tab"
          :class="{ active: activeFilter === tab.id }"
          @click="activeFilter = tab.id"
        >
          {{ tab.label }}<span v-if="tab.count !== null"> ({{ tab.count }})</span>
        </button>
      </div>
      <div class="header-search order-search">
        <span class="search-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg>
        </span>
        <input v-model="searchQuery" type="text" placeholder="Search Order ID, name…" />
      </div>
    </div>

    <p v-if="loadError" class="order-sample-note">{{ loadError }}</p>

    <!-- Split View -->
    <div class="order-split">
      <!-- Left: Order List -->
      <div class="order-list-panel card">
        <div class="order-list-head">
          <h3>Active Purchase Orders</h3>
        </div>
        <div class="order-list-scroll">
          <table class="order-table">
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Date</th>
                <th class="text-right">Amount</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="filteredOrders.length === 0">
                <td colspan="5" class="order-empty-row">No orders match this filter.</td>
              </tr>
              <tr
                v-for="order in filteredOrders"
                :key="order.id"
                class="order-row"
                :class="{ active: order.id === selectedOrderId }"
                @click="selectedOrderId = order.id"
              >
                <td class="order-id">
                  <a href="#" class="order-id-link" @click.stop.prevent="openDetails(order.id)">{{ order.id }}</a>
                </td>
                <td class="customer">{{ order.customer }}</td>
                <td class="order-date">{{ order.date }}</td>
                <td class="amount text-right">{{ formatCurrency(order.total) }}</td>
                <td><span class="badge" :class="statusBadgeClass(order.status)">{{ order.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="order-list-footer">
          <span>{{ filteredOrders.length }} order{{ filteredOrders.length === 1 ? '' : 's' }}</span>
        </div>
      </div>

      <!-- Right: Order Detail Preview -->
      <div class="order-detail-panel card">
        <template v-if="selectedOrder">
          <div class="order-detail-head">
            <div>
              <div class="order-detail-title-row">
                <h3>Order {{ selectedOrder.id }} Profile</h3>
                <span class="badge" :class="statusBadgeClass(selectedOrder.status)">{{ selectedOrder.status }}</span>
              </div>
              <p class="order-detail-sub">Placed on {{ selectedOrder.date }} at {{ selectedOrder.time }}</p>
            </div>
            <button class="btn-outline btn-sm" @click="openDetails(selectedOrder.id)">View Full Details</button>
          </div>

          <div class="order-detail-body">
            <div class="order-detail-grid">
              <div>
                <h4 class="order-section-label">Delivery Address</h4>
                <p class="order-address-name">{{ selectedOrder.customer }}</p>
                <p class="order-address-text">{{ formatAddress(selectedOrder.address) }}</p>
              </div>
              <div>
                <h4 class="order-section-label">Payment Details</h4>
                <div class="order-payment-row">
                  <span>Subtotal</span><strong>{{ formatCurrency(selectedOrder.subtotal) }}</strong>
                </div>
                <div class="order-payment-row">
                  <span>Shipping</span>
                  <strong :class="{ 'order-shipping-free': !selectedOrder.shippingFee }">
                    {{ selectedOrder.shippingFee ? formatCurrency(selectedOrder.shippingFee) : 'Free' }}
                  </strong>
                </div>
                <div class="order-payment-row order-payment-total">
                  <span>Total Paid</span><strong>{{ formatCurrency(selectedOrder.total) }}</strong>
                </div>
              </div>
            </div>

            <div>
              <h4 class="order-section-label">Items ({{ selectedOrder.items.length }})</h4>
              <div v-for="(item, idx) in selectedOrder.items" :key="idx" class="order-item-card">
                <div class="order-item-thumb">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg>
                </div>
                <div class="order-item-info">
                  <p class="order-item-name">{{ item.name }}</p>
                  <p class="order-item-meta">Qty: {{ item.qty }} • {{ item.variant }}</p>
                </div>
                <p class="order-item-price">{{ formatCurrency(item.price) }}</p>
              </div>
            </div>

            <div>
              <h4 class="order-section-label">Timeline Tracking</h4>
              <div class="timeline">
                <div v-for="(step, idx) in selectedOrder.timeline" :key="idx" class="timeline-item">
                  <div class="timeline-dot-wrap">
                    <span class="timeline-dot" :class="{ pending: !step.done }"></span>
                    <span v-if="idx < selectedOrder.timeline.length - 1" class="timeline-line"></span>
                  </div>
                  <div>
                    <p class="timeline-text" :class="{ muted: !step.done }">{{ step.label }}</p>
                    <p class="timeline-time">{{ step.time }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="order-footer-actions">
            <button
              class="btn-text-danger"
              :disabled="selectedOrder.status === 'Cancelled' || selectedOrder.status === 'Delivered'"
              @click="rejectOrder(selectedOrder.id)"
            >
              Reject Order
            </button>
            <div class="order-footer-right">
              <button class="btn-outline" @click="printInvoice">Print Invoice</button>
              <button
                class="btn-primary"
                :disabled="selectedOrder.status !== 'New'"
                @click="acceptOrder(selectedOrder.id)"
              >
                Accept Order
              </button>
            </div>
          </div>
        </template>

        <div v-else class="empty-state order-detail-empty">
          <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 2h6v3H9zM8 10h8M8 14h8M8 18h5"/></svg>
          <p>Select an order from the list to view its details.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useOrders } from '../composables/useOrders';

const {
  orders: mockOrders,
  loadError,
  newOrdersCount,
  loadOrders,
  statusBadgeClass,
  formatCurrency,
  acceptOrder,
  rejectOrder,
} = useOrders();

const activeFilter = ref('all');
const searchQuery = ref('');
const selectedOrderId = ref(null);

onMounted(async () => {
  await loadOrders();
  selectedOrderId.value = mockOrders.value[0]?.id ?? null;
});

// Keep a selection once orders arrive/refresh, in case the page loaded
// before the first fetch resolved.
watch(mockOrders, (list) => {
  if (!selectedOrderId.value && list.length) {
    selectedOrderId.value = list[0].id;
  }
});

const filterTabs = computed(() => {
  const counts = { new: 0, processing: 0, 'in transit': 0, delivered: 0, cancelled: 0 };

  for (const o of mockOrders.value) {
    const key = o.status.toLowerCase();

    if (key in counts) {
counts[key] += 1;
}
  }

  return [
    { id: 'all', label: 'All', count: null },
    { id: 'new', label: 'New', count: counts.new },
    { id: 'processing', label: 'Processing', count: counts.processing },
    { id: 'shipped', label: 'Shipped', count: counts['in transit'] },
    { id: 'delivered', label: 'Delivered', count: counts.delivered },
    { id: 'cancelled', label: 'Cancelled', count: counts.cancelled },
  ];
});

const statusFilterMap = {
  new: 'New',
  processing: 'Processing',
  shipped: 'In Transit',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
};

const filteredOrders = computed(() => {
  let list = mockOrders.value;

  if (activeFilter.value !== 'all') {
    const target = statusFilterMap[activeFilter.value];
    list = list.filter(o => o.status === target);
  }

  const q = searchQuery.value.trim().toLowerCase();

  if (q) {
    list = list.filter(o =>
      o.id.toLowerCase().includes(q) || o.customer.toLowerCase().includes(q)
    );
  }

  return list;
});

const selectedOrder = computed(() =>
  mockOrders.value.find(o => o.id === selectedOrderId.value) || null
);

function formatAddress(addr) {
  if (!addr) {
return '—';
}

  return [addr.street, [addr.barangay, addr.municipality].filter(Boolean).join(', '), addr.province, addr.country]
    .filter(Boolean)
    .join(', ');
}

function printInvoice() {
  window.print();
}

// Navigates to the dedicated Order Details page (/seller/orders/{id})
// via the same seller-nav event SellerLayout already listens for.
function openDetails(id) {
  window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section: 'orderDetails', orderId: id } }));
}
</script>