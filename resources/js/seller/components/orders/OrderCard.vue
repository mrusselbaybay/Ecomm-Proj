<!-- resources/js/seller/components/orders/OrderCard.vue -->
<!--
  Mobile / narrow-screen representation of one order row. Same
  information as a table row (order #, buyer, date, product summary,
  total, payment status, order status, delivery method, primary action),
  stacked so nothing overflows. Tapping the card opens the preview;
  the order number opens the full details page.
-->
<template>
    <article class="order-card" :class="{ active: selected }" @click="$emit('select')">
        <div class="order-card-top">
            <button
                type="button"
                class="order-card-number"
                @click.stop="$emit('open')"
            >
                {{ order.id }}
            </button>
            <OrderStatusBadge :status="order.status" size="sm" />
        </div>

        <p class="order-card-buyer">{{ order.customer || 'Unknown buyer' }}</p>
        <p class="order-card-products">{{ productSummary }}</p>

        <div class="order-card-meta">
            <span>{{ order.date || '—' }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ deliveryMethod }}</span>
        </div>

        <div class="order-card-bottom">
            <div class="order-card-money">
                <span class="order-card-total">{{ formatCurrency(order.total) }}</span>
                <span class="badge" :class="paymentBadgeClass">{{ order.paymentStatus || 'Unpaid' }}</span>
            </div>
            <button
                v-if="primaryAction"
                type="button"
                class="btn-primary btn-sm"
                :disabled="busy"
                @click.stop="$emit('action', primaryAction.value)"
            >
                {{ busy ? 'Working…' : primaryAction.label }}
            </button>
            <button
                v-else
                type="button"
                class="btn-outline btn-sm"
                @click.stop="$emit('open')"
            >
                View
            </button>
        </div>

        <p v-if="order.returnStatus" class="order-card-return">
            {{ returnLabel }}
        </p>
    </article>
</template>

<script setup>
import { computed } from 'vue';
import OrderStatusBadge from './OrderStatusBadge.vue';

const props = defineProps({
    order: { type: Object, required: true },
    primaryAction: { type: Object, default: null }, // { value, label } | null
    busy: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
});

defineEmits(['open', 'select', 'action']);

function formatCurrency(value) {
    return `₱${Number(value ?? 0).toFixed(2)}`;
}

const productSummary = computed(() => {
    const items = props.order.items || [];

    if (!items.length) {
        return 'No items';
    }

    const first = items[0].name || 'Item';

    return items.length === 1 ? first : `${first} +${items.length - 1} more`;
});

const deliveryMethod = computed(
    () => props.order.shippingService || props.order.shippingCarrier || 'Standard delivery',
);

const paymentBadgeClass = computed(() => {
    const map = { Paid: 'badge-emerald', Refunded: 'badge-slate', Unpaid: 'badge-amber' };

    return map[props.order.paymentStatus] || 'badge-amber';
});

const RETURN_LABELS = {
    requested: 'Return requested',
    approved: 'Return approved',
    returned: 'Returned / refunded',
    rejected: 'Return rejected',
};

const returnLabel = computed(() => RETURN_LABELS[props.order.returnStatus] || '');
</script>
