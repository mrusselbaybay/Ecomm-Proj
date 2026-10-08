<script setup>
import { computed } from 'vue';

const props = defineProps({
    amount: { type: [Number, String], required: true },
    currency: { type: String, default: 'PHP' },
});

const formatted = computed(() => new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: props.currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}).format(Number(props.amount) || 0));
</script>

<template>
    <span class="price-tag" :data-currency="currency">{{ formatted }}</span>
</template>
