<!-- resources/js/seller/components/orders/OrderSummaryCards.vue -->
<!--
  Six at-a-glance counts for the order pipeline. Numbers come from
  meta.statusCounts (computed server-side across the whole filtered
  population, never just the current page). Clicking a card sets the
  matching status filter on the list.
-->
<template>
    <div class="order-summary-cards" role="group" aria-label="Order pipeline summary">
        <button
            v-for="card in cards"
            :key="card.key"
            type="button"
            class="order-summary-card"
            :class="[card.tone, { active: activeStatus === card.status }]"
            :aria-pressed="activeStatus === card.status"
            @click="$emit('select', activeStatus === card.status ? '' : card.status)"
        >
            <span class="order-summary-card-icon" aria-hidden="true">
                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path :d="card.icon" />
                </svg>
            </span>
            <span class="order-summary-card-value">{{ card.value }}</span>
            <span class="order-summary-card-label">{{ card.label }}</span>
            <span v-if="card.sub" class="order-summary-card-sub">{{ card.sub }}</span>
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    counts: { type: Object, default: () => ({}) },
    activeStatus: { type: String, default: '' },
});

defineEmits(['select']);

const n = (key) => Number(props.counts?.[key] || 0);

const cards = computed(() => {
    const rejected = n('Rejected');

    return [
        {
            key: 'pending',
            status: 'New',
            label: 'New / Pending',
            value: n('New'),
            tone: 'is-sky',
            icon: 'M12 7v5l3 2M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z',
        },
        {
            key: 'processing',
            status: 'Processing',
            label: 'Processing',
            value: n('Processing'),
            tone: 'is-amber',
            icon: 'M21 12a9 9 0 1 1-3-6.7M21 4v5h-5',
        },
        {
            key: 'ready',
            status: 'Ready for Pickup',
            label: 'Ready for Pickup',
            value: n('Ready for Pickup'),
            tone: 'is-amber',
            icon: 'M3 8 12 3l9 5-9 5-9-5Zm0 0v8l9 5 9-5V8M9 12l2 2 4-4',
        },
        {
            key: 'shipped',
            status: 'In Transit',
            label: 'Shipped',
            value: n('In Transit'),
            tone: 'is-sky',
            icon: 'M1 6h13v9H1zM14 9h4l3 3v3h-7M4 18a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0Zm12 0a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0Z',
        },
        {
            key: 'completed',
            status: 'Delivered',
            label: 'Completed',
            value: n('Delivered'),
            tone: 'is-emerald',
            icon: 'M12 3a9 9 0 1 0 9 9M8 12l3 3 6-7',
        },
        {
            key: 'issues',
            status: 'Cancelled',
            label: 'Cancelled / Issues',
            value: n('Cancelled') + rejected,
            sub: rejected ? `${rejected} rejected` : '',
            tone: 'is-red',
            icon: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18M9 9l6 6M15 9l-6 6',
        },
    ];
});
</script>
