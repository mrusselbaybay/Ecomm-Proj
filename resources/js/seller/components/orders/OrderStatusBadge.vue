<!-- resources/js/seller/components/orders/OrderStatusBadge.vue -->
<!--
  One place that turns a stored order status ('New', 'In Transit', …) into
  a labelled + icon'd badge. Used by the order list, the mobile cards, the
  preview drawer and the details page so they never drift apart.

  Never colour-alone: every badge carries a text label AND a shape/icon.
-->
<template>
    <span class="badge order-status-badge" :class="badgeClass" :title="label">
        <svg
            class="order-status-badge-icon"
            width="12"
            height="12"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.4"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path :d="iconPath" />
        </svg>
        <span>{{ label }}</span>
    </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    // Stored status value (Order::STATUSES). 'New' renders as "Pending",
    // 'In Transit' as "Shipped" — matching Order::STATUS_LABELS.
    status: { type: String, default: '' },
    size: { type: String, default: 'md' }, // 'sm' | 'md'
});

const LABELS = {
    New: 'Pending',
    Confirmed: 'Confirmed',
    Processing: 'Processing',
    Packed: 'Packed',
    'Ready for Pickup': 'Ready for Pickup',
    'In Transit': 'Shipped',
    Delivered: 'Delivered',
    Cancelled: 'Cancelled',
    Rejected: 'Rejected',
    Refunded: 'Refunded',
};

// badge-* colour + a distinct glyph per status so the state reads without
// relying on hue.
const META = {
    New: { cls: 'badge-sky', icon: 'M12 7v5l3 2' }, // clock
    Confirmed: { cls: 'badge-sky', icon: 'M20 6 9 17l-5-5' }, // check
    Processing: { cls: 'badge-amber', icon: 'M4 12a8 8 0 0 1 8-8M20 12a8 8 0 0 1-8 8' }, // spinner arc
    Packed: { cls: 'badge-amber', icon: 'M3 8 12 3l9 5-9 5-9-5Zm0 0v8l9 5 9-5V8' }, // box
    'Ready for Pickup': { cls: 'badge-amber', icon: 'm4 13 4 4 12-12' }, // ready check
    'In Transit': { cls: 'badge-sky', icon: 'M1 6h13v9H1zM14 9h4l3 3v3h-7' }, // truck
    Delivered: { cls: 'badge-emerald', icon: 'M12 3a9 9 0 1 0 9 9M8 12l3 3 6-7' }, // check circle
    Cancelled: { cls: 'badge-red', icon: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18M9 9l6 6M15 9l-6 6' }, // x circle
    Rejected: { cls: 'badge-red', icon: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18M6 6l12 12' }, // slash circle
    Refunded: { cls: 'badge-slate', icon: 'M3 10a9 9 0 1 1 2 6M3 16v-6h6' }, // undo
};

const label = computed(() => LABELS[props.status] || props.status || '—');
const badgeClass = computed(() => [
    (META[props.status] || { cls: 'badge-slate' }).cls,
    { 'order-status-badge-sm': props.size === 'sm' },
]);
const iconPath = computed(() => (META[props.status] || { icon: 'M12 8v4M12 16h.01' }).icon);
</script>
