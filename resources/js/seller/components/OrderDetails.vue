<!-- resources/js/seller/components/OrderDetails.vue -->
<template>
  <div class="order-detail-page">
    <!-- Loading -->
    <div v-if="isLoading" class="order-detail-loading">
      <div class="loading-spinner"></div>
      <p>Loading order…</p>
    </div>

    <!-- Not found -->
    <div v-else-if="!order" class="card order-not-found">
      <div class="empty-state">
        <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg>
        <p>Order not found.</p>
        <p class="empty-hint">This order may have been removed, or the link is incorrect.</p>
        <button class="btn-primary" style="margin-top:1rem;" @click="backToOrders">Back to Orders</button>
      </div>
    </div>

    <!-- Loaded -->
    <template v-else>
      <!-- Header -->
      <header class="order-detail-page-header">
        <div>
          <div class="order-detail-title-row">
            <h2 class="order-detail-page-title">Order {{ order.id }}</h2>
            <span class="badge" :class="statusBadgeClass(order.status)">{{ order.status }}</span>
          </div>
          <p class="order-detail-page-sub">Placed on {{ order.date }} at {{ order.time }}</p>
        </div>

        <div class="order-detail-page-actions">
          <button class="btn-outline" @click="printInvoice">
            <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 2.5h8l4 4v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-15z"/></svg>
            Print Invoice
          </button>
          <button v-if="order.shipping.trackingNumber" class="btn-outline" @click="trackPackage">
            <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="1" y="5" width="12" height="9" rx="1"/><path d="M13 8h3l3 3v3h-6z"/></svg>
            Track Package
          </button>
          <button class="btn-primary" @click="contactBuyer">
            <svg class="icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2" y="4" width="16" height="12" rx="2"/><path d="m3 5 7 6 7-6"/></svg>
            Contact Buyer
          </button>
        </div>
      </header>

      <div class="order-detail-page-grid">
        <!-- Left column -->
        <div class="order-detail-page-left">
          <!-- Order Information -->
          <div class="card order-detail-page-card">
            <h3 class="order-section-label">Order Information</h3>
            <div class="order-info-grid">
              <div>
                <p class="order-info-label">Customer</p>
                <div class="order-customer-row">
                  <div class="order-customer-avatar">{{ customerInitials }}</div>
                  <div>
                    <p class="order-customer-name">{{ order.customer }}</p>
                    <p class="order-customer-email">{{ order.email || 'No email on file' }}</p>
                  </div>
                </div>
              </div>
              <div>
                <p class="order-info-label">Placed On</p>
                <p class="order-info-value">{{ order.date }}</p>
                <p class="order-info-sub">{{ order.time }}</p>
              </div>
              <div>
                <p class="order-info-label">Payment Method</p>
                <p class="order-info-value">{{ order.paymentMethod || 'Not on file' }}</p>
                <p class="order-info-sub">{{ order.paymentStatus || '—' }}</p>
              </div>
            </div>
          </div>

          <!-- Items -->
          <div class="card order-detail-page-card order-items-card">
            <h3 class="order-section-label">Items List ({{ order.items.length }})</h3>
            <div class="order-item-card order-item-card-lg" v-for="(item, idx) in order.items" :key="idx">
              <div class="order-item-thumb order-item-thumb-lg">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg>
              </div>
              <div class="order-item-info">
                <div class="order-item-info-top">
                  <div>
                    <p class="order-item-name-lg">{{ item.name }}</p>
                    <p class="order-item-category">{{ item.category ? `Category: ${item.category}` : '' }}</p>
                    <div class="order-item-meta-row">
                      <span v-if="item.variant">Variant: <strong>{{ item.variant }}</strong></span>
                      <span v-if="item.sku">SKU: <strong>{{ item.sku }}</strong></span>
                    </div>
                  </div>
                  <div class="order-item-price-block">
                    <p class="order-item-price-label">Price</p>
                    <p class="order-item-price-lg">{{ formatCurrency(item.price) }}</p>
                    <p class="order-item-qty">Qty: {{ item.qty }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Address + Shipping -->
          <div class="order-detail-grid">
            <div class="card order-detail-page-card">
              <h3 class="order-section-label">Delivery Address</h3>
              <div class="order-info-icon-row">
                <div class="order-info-icon sky">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 22s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                </div>
                <div>
                  <p class="order-address-name">{{ order.address.recipient }}</p>
                  <p class="order-address-text">{{ formatAddress(order.address) }}</p>
                  <p class="order-address-phone" v-if="order.phone">Phone: {{ order.phone }}</p>
                </div>
              </div>
            </div>
            <div class="card order-detail-page-card">
              <h3 class="order-section-label">Shipping Method</h3>
              <div class="order-info-icon-row">
                <div class="order-info-icon emerald">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8Z"/></svg>
                </div>
                <div>
                  <p class="order-address-name">{{ order.shipping.method || 'Not specified' }}</p>
                  <p class="order-address-text" v-if="order.shipping.handlingTime || order.shipping.carrier || order.shipping.service">
                    <template v-if="order.shipping.handlingTime">Handling Time: {{ order.shipping.handlingTime }}<br></template>
                    <template v-if="order.shipping.carrier">Carrier: {{ order.shipping.carrier }}<br></template>
                    <template v-if="order.shipping.service">Service: {{ order.shipping.service }}</template>
                  </p>
                  <a v-if="order.shipping.trackingNumber" href="#" class="order-tracking-link" @click.prevent="trackPackage">#{{ order.shipping.trackingNumber }}</a>
                  <p v-else class="order-address-sub">No tracking number yet</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right column -->
        <div class="order-detail-page-right">
          <!-- Payment -->
          <div class="card order-detail-page-card order-payment-card">
            <h3 class="order-section-label">Payment Breakdown</h3>
            <div class="order-payment-row">
              <span>Order Subtotal</span><strong>{{ formatCurrency(order.subtotal) }}</strong>
            </div>
            <div class="order-payment-row">
              <span>Shipping</span>
              <strong :class="{ 'order-shipping-free': !order.shippingFee }">
                {{ order.shippingFee ? formatCurrency(order.shippingFee) : 'Free' }}
              </strong>
            </div>
            <div class="order-payment-row" v-if="order.tax">
              <span>Tax</span><strong>{{ formatCurrency(order.tax) }}</strong>
            </div>
            <div class="order-payment-row" v-if="order.discount">
              <span>Discount</span><strong class="order-discount-value">-{{ formatCurrency(order.discount) }}</strong>
            </div>
            <div class="order-payment-row order-payment-total">
              <span>Total Paid</span><strong>{{ formatCurrency(order.total) }}</strong>
            </div>
            <div class="order-payment-footer">
              <p>Payment status: {{ order.paymentStatus || 'Unknown' }}</p>
            </div>
          </div>

          <!-- Timeline -->
          <div class="card order-detail-page-card">
            <h3 class="order-section-label">Order Progression</h3>
            <div class="timeline timeline-reverse">
              <div v-for="(step, idx) in reversedTimeline" :key="idx" class="timeline-item">
                <div class="timeline-dot-wrap">
                  <span class="timeline-dot" :class="{ pending: !step.done }"></span>
                  <span v-if="idx < reversedTimeline.length - 1" class="timeline-line"></span>
                </div>
                <div>
                  <p class="timeline-text" :class="{ muted: !step.done }">{{ step.label }}</p>
                  <p class="timeline-time" :class="{ italic: !step.done }">{{ step.time }}</p>
                  <p v-if="step.detail" class="timeline-detail">{{ step.detail }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer actions -->
      <div class="order-detail-page-footer">
        <a href="#" class="order-back-link" @click.prevent="backToOrders">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
          Back to All Orders
        </a>
        <div class="order-detail-page-footer-right">
          <button
            v-if="canCancel"
            class="btn-danger-soft"
            @click="handleCancel"
          >
            Cancel Order
          </button>
          <button
            v-if="canPrepare"
            class="btn-primary"
            @click="proceedToPacking"
          >
            Proceed to Packing
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useOrders } from '../composables/useOrders';

const props = defineProps({
  orderId: { type: String, default: null },
});

const { getOrderById, statusBadgeClass, formatCurrency, cancelOrder } = useOrders();

const isLoading = ref(true);
const order = ref(null);

async function loadOrder() {
  isLoading.value = true;
  order.value = props.orderId ? await getOrderById(props.orderId) : null;
  isLoading.value = false;
}

onMounted(loadOrder);

const customerInitials = computed(() => {
  if (!order.value?.customer) {
return '?';
}

  return order.value.customer
    .split(' ')
    .map(p => p[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();
});

const reversedTimeline = computed(() => order.value ? [...order.value.timeline].reverse() : []);

const canCancel = computed(() =>
  order.value && !['Delivered', 'Cancelled'].includes(order.value.status)
);
const canPrepare = computed(() =>
  order.value && order.value.status === 'Processing'
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

function trackPackage() {
  // No dedicated tracking view exists yet — surface the number for now.
  if (order.value?.shipping?.trackingNumber) {
    window.alert(`Tracking number: ${order.value.shipping.trackingNumber}\n(A full tracking view isn't wired up yet.)`);
  }
}

function contactBuyer() {
  goTo('messages');
}

async function handleCancel() {
  if (!order.value) {
return;
}

  if (!window.confirm(`Cancel order ${order.value.id}? This cannot be undone.`)) {
return;
}

  await cancelOrder(order.value.id);
  await loadOrder();
}

function proceedToPacking() {
  goTo('prepareOrders');
}

function goTo(section) {
  window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section } }));
}

function backToOrders() {
  goTo('orders');
}
</script>