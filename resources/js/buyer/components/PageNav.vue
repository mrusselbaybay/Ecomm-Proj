<script setup>
/*
| Numbered pagination: first, last, and the pages around the current one,
| with gaps marked by an ellipsis. Same markup and classes as the category
| page's pagination (CategoryListing.vue) so both look and behave alike.
*/
import { computed } from 'vue';

const props = defineProps({
    page: {
        type: Number,
        required: true
    },
    totalPages: {
        type: Number,
        required: true
    },
    label: {
        type: String,
        default: 'Pages'
    }
});

const emit = defineEmits(['change']);

const pageNumbers = computed(() => {
    const total = props.totalPages;
    const current = props.page;

    return [...new Set([1, total, current - 1, current, current + 1])]
        .filter(n => n >= 1 && n <= total)
        .sort((a, b) => a - b);
});

function go(n) {
    if (n >= 1 && n <= props.totalPages && n !== props.page) {
        emit('change', n);
    }
}
</script>

<template>
    <nav
        v-if="totalPages > 1"
        class="cat-pagination"
        :aria-label="label"
    >
        <button
            type="button"
            class="icon-btn is-outline"
            aria-label="Previous page"
            :disabled="page === 1"
            @click="go(page - 1)"
        >
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
        </button>

        <template
            v-for="(n, index) in pageNumbers"
            :key="n"
        >
            <span
                v-if="index > 0 && n - pageNumbers[index - 1] > 1"
                class="page-gap"
                aria-hidden="true"
            >&hellip;</span>
            <button
                type="button"
                class="page-btn"
                :class="{ 'is-current': n === page }"
                :aria-current="n === page ? 'page' : undefined"
                :aria-label="`Page ${n}`"
                @click="go(n)"
            >
                {{ n }}
            </button>
        </template>

        <button
            type="button"
            class="icon-btn is-outline"
            aria-label="Next page"
            :disabled="page === totalPages"
            @click="go(page + 1)"
        >
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
        </button>
    </nav>
</template>
