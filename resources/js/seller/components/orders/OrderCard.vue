<!-- resources/js/seller/components/orders/OrderCard.vue -->
<!--
  One kanban card: a generic product-icon thumbnail, order number,
  product summary, total and date. Not a real photo — see the $with
  comment in SellerOrderController::index(): a products query that
  selects `images` filtered by WHERE id IN (...) hangs indefinitely
  against this Supabase instance, so no product image is fetched for
  this list at all, confirmed via direct `php artisan tinker` testing.
  No buyer name, status badge, or action button either — those (plus
  Cancel/Reject) live on the full Order Details page, opened by
  clicking anywhere on the card.
-->
<template>
    <article class="order-card" @click="$emit('select')">
        <div class="order-card-top">
            <span class="order-card-thumb">
                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    aria-hidden="true"
                >
                    <rect x="3" y="3" width="18" height="18" rx="2" />
                    <circle cx="8.5" cy="8.5" r="1.5" />
                    <path d="m21 15-5-5L5 21" />
                </svg>
            </span>
            <button
                type="button"
                class="order-card-number"
                @click.stop="$emit('open')"
            >
                {{ order.id }}
            </button>
        </div>

        <p class="order-card-products">{{ productSummary }}</p>

        <div class="order-card-bottom">
            <span class="order-card-total">{{ formatCurrency(order.total) }}</span>
            <span class="order-card-date">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="16" rx="2" />
                    <path d="M8 3v4M16 3v4M3 10h18" />
                </svg>
                {{ order.date || '—' }}
            </span>
        </div>
    </article>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    order: { type: Object, required: true },
});

defineEmits(['open', 'select']);

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
</script>
