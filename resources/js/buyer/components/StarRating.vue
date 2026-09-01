<script setup>
/*
| StarRating — read-only star display shared by the cart line items and
| the reviews drawer, so ratings look identical in both places. Rounds to
| the nearest whole star for the fill; the exact value is in the
| aria-label (and optionally shown as text).
*/
import { computed } from 'vue';

const props = defineProps({
    rating: { type: Number, default: null },
    size: { type: Number, default: 16 },
    count: { type: Number, default: null },
    showValue: { type: Boolean, default: false },
});

const filled = computed(() => Math.round(props.rating || 0));

const ariaLabel = computed(() => {
    if (props.rating == null) {
        return 'No rating yet';
    }

    const base = `Rated ${props.rating.toFixed(1)} out of 5`;

    return props.count != null ? `${base} from ${props.count} reviews` : base;
});
</script>

<template>
    <span
        class="star-rating"
        role="img"
        :aria-label="ariaLabel"
    >
        <span
            class="star-rating-stars"
            aria-hidden="true"
        >
            <svg
                v-for="star in 5"
                :key="star"
                :width="size"
                :height="size"
                viewBox="0 0 24 24"
                :fill="star <= filled ? 'currentColor' : 'none'"
                stroke="currentColor"
                stroke-width="2"
                stroke-linejoin="round"
            >
                <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.123 2.123 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
            </svg>
        </span>

        <span
            v-if="showValue && rating != null"
            class="star-rating-value"
        >
            {{ rating.toFixed(1) }}
        </span>

        <span
            v-if="count != null"
            class="star-rating-count"
        >
            <template v-if="rating == null">No reviews yet</template>
            <template v-else>({{ count }})</template>
        </span>
    </span>
</template>

<style scoped>
.star-rating {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.star-rating-stars {
    display: inline-flex;
    color: #f59e0b;
}

.star-rating-value {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
}

.star-rating-count {
    font-size: 12.5px;
    color: #64748b;
}
</style>
