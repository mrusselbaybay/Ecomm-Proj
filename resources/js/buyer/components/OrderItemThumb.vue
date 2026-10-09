<script setup>
/*
| An order line's photo in a fixed square: the image as sent by the orders
| API (variant photo, else product photo), scaled to fit without cropping
| (object-fit: contain) so tall or wide photos keep their shape. With no
| photo, or one whose URL fails to load, the item's category icon shows
| instead — never a broken-image box.
*/
import { ref, watch } from 'vue';
import { metaFor } from '../composables/useCategoryMeta';

const props = defineProps({
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
        validator: value => ['md', 'lg'].includes(value)
    }
});

const failed = ref(false);
const loaded = ref(false);

watch(() => props.src, () => {
    failed.value = false;
    loaded.value = false;
});
</script>

<template>
    <span
        class="ord-thumb"
        :class="[`is-${size}`, `accent-${metaFor(category).accent}`, { 'has-image': src && !failed, 'is-loaded': loaded }]"
    >
        <img
            v-if="src && !failed"
            :src="src"
            alt=""
            loading="lazy"
            decoding="async"
            @load="loaded = true"
            @error="failed = true"
        >
        <span
            v-else
            class="ord-thumb-icon"
            aria-hidden="true"
            v-html="metaFor(category).icon"
        ></span>
    </span>
</template>
