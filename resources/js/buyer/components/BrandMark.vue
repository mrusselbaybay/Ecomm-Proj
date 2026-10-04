<script setup>
/*
| BrandMark — the BuyTheWay logo (shopping bag with a road + wordmark).
| Used by the Header, the mobile drawer and the Footer so the identity is
| drawn in exactly one place.
|
| Source artwork: public/images/new logo.png. The served files are trimmed,
| 96px-tall (2x the largest display size) exports of it:
|   logo-buytheway.webp          full colour, for light backgrounds
|   logo-buytheway-inverse.webp  navy parts turned white, for dark backgrounds
|                                (the footer); the bag's road stays cut out
*/
import { computed } from 'vue';

const props = defineProps({
    inverse: {
        type: Boolean,
        default: false
    },
    compact: {
        type: Boolean,
        default: false
    }
});

// Bound rather than a static attribute so Vite doesn't try to resolve the
// public/ path as a module import.
const logoSrc = computed(() =>
    props.inverse ? '/images/logo-buytheway-inverse.webp' : '/images/logo-buytheway.webp'
);
</script>

<template>
    <span
        class="brand-mark"
        :class="{ 'is-inverse': inverse, 'is-compact': compact }"
    >
        <img
            class="brand-mark-logo"
            :src="logoSrc"
            alt="BuyTheWay"
            width="357"
            height="96"
            decoding="async"
        >
    </span>
</template>
