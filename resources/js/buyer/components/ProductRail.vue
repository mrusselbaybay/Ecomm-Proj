<script setup>
/*
| ProductRail — a titled, horizontally scrolling row of ProductCards for the
| homepage discovery sections (New arrivals, Top rated ...). Native scroll is
| the single source of truth: prev/next call scrollBy() on the real element
| and their disabled state is read back from its scroll position.
*/
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import ProductCard from './ProductCard.vue';
import { vReveal } from '../composables/useReveal';

const props = defineProps({
    title: {
        type: String,
        required: true
    },
    subtitle: {
        type: String,
        default: ''
    },
    products: {
        type: Array,
        default: () => []
    },
    actionLabel: {
        type: String,
        default: ''
    },
    eager: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits([
    'view-product',
    'action'
]);

const track = ref(null);
const canScrollPrev = ref(false);
const canScrollNext = ref(false);

function updateScrollState() {
    const el = track.value;

    if (!el) {
        return;
    }

    canScrollPrev.value = el.scrollLeft > 4;
    canScrollNext.value = el.scrollLeft < el.scrollWidth - el.clientWidth - 4;
}

function scrollPage(direction) {
    const el = track.value;

    if (!el) {
        return;
    }

    el.scrollBy({ left: direction * el.clientWidth * 0.85, behavior: 'smooth' });
}

watch(() => props.products, () => nextTick(updateScrollState));

onMounted(() => {
    nextTick(updateScrollState);
    window.addEventListener('resize', updateScrollState);
});

onUnmounted(() => {
    window.removeEventListener('resize', updateScrollState);
});
</script>

<template>

    <section
        v-if="products.length > 0"
        v-reveal
        class="rail"
        :aria-label="title"
    >

        <div class="section-head">
            <div>
                <h2 class="section-title">{{ title }}</h2>
                <p
                    v-if="subtitle"
                    class="section-sub"
                >{{ subtitle }}</p>
            </div>

            <div class="section-head-actions">
                <button
                    v-if="actionLabel"
                    type="button"
                    class="link-btn"
                    @click="emit('action')"
                >
                    {{ actionLabel }}
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
                <div class="rail-nav">
                    <button
                        type="button"
                        class="icon-btn is-outline"
                        :aria-label="`Scroll ${title} back`"
                        :disabled="!canScrollPrev"
                        @click="scrollPage(-1)"
                    >
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                    </button>
                    <button
                        type="button"
                        class="icon-btn is-outline"
                        :aria-label="`Scroll ${title} forward`"
                        :disabled="!canScrollNext"
                        @click="scrollPage(1)"
                    >
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                    </button>
                </div>
            </div>
        </div>

        <ul
            ref="track"
            class="rail-track"
            @scroll.passive="updateScrollState"
        >
            <li
                v-for="product in products"
                :key="product.id"
                class="rail-item"
            >
                <ProductCard
                    :product="product"
                    :lazy="!eager"
                    @view="emit('view-product', $event)"
                />
            </li>
        </ul>

    </section>

</template>
