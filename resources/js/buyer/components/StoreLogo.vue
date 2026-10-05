<script setup>
/*
| A store's logo: the seller's own picture when they've uploaded one,
| otherwise a monogram tinted with their line of business's accent (the
| same pairs category tiles use). The box size is fixed in CSS, so the
| image or fallback never shifts the layout, and a broken image URL
| quietly falls back to the monogram.
*/
import { computed, ref, watch } from 'vue';
import { metaFor } from '../composables/useCategoryMeta';

const props = defineProps({
    name: {
        type: String,
        default: ''
    },
    src: {
        type: String,
        default: ''
    },
    category: {
        type: String,
        default: ''
    },
    size: {
        type: String,
        default: 'md',
        validator: value => ['sm', 'md', 'lg'].includes(value)
    },
    // Directory rows below the fold load lazily; the store page header
    // loads its logo eagerly.
    lazy: {
        type: Boolean,
        default: true
    }
});

const failed = ref(false);

watch(() => props.src, () => {
    failed.value = false;
});

const showImage = computed(() => Boolean(props.src) && !failed.value);

const monogram = computed(() => {
    const words = props.name.trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return 'S';
    }

    const letters = words.length > 1 ? words[0][0] + words[1][0] : words[0].slice(0, 2);

    return letters.toUpperCase();
});

const pixels = computed(() => ({ sm: 48, md: 56, lg: 88 })[props.size]);
</script>

<template>
    <span
        class="store-logo"
        :class="[`is-${size}`, showImage ? 'has-image' : `accent-${metaFor(category).accent}`]"
    >
        <img
            v-if="showImage"
            :src="src"
            alt=""
            :width="pixels"
            :height="pixels"
            :loading="lazy ? 'lazy' : 'eager'"
            decoding="async"
            @error="failed = true"
        >
        <span
            v-else
            class="store-logo-monogram"
            aria-hidden="true"
        >{{ monogram }}</span>
    </span>
</template>
