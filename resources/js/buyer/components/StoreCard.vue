<script setup>
/*
| One store in the directory. The store name is the only link and is
| stretched over the whole row, so there is exactly one way in. It is a
| real href (/buyer?store=...), which keeps open-in-new-tab and copy-link
| working, while a plain click stays inside the buyer app.
|
| Ratings and product counts come straight from StoreController and are
| only shown when real; the photo strip is the store's three newest
| buyer-visible product images.
*/
import { computed } from 'vue';
import StoreLogo from './StoreLogo.vue';
import { productCountLabel } from '../composables/useStores';

const props = defineProps({
    store: {
        type: Object,
        required: true
    },
    href: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['open']);

const hasRating = computed(() => typeof props.store.rating === 'number' && props.store.reviewCount > 0);

function handleClick(event) {
    // Let the browser handle new-tab / new-window clicks.
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
        return;
    }

    event.preventDefault();
    emit('open', props.store);
}
</script>

<template>
    <article class="store-card">
        <StoreLogo
            :name="store.name"
            :src="store.logo || ''"
            :category="store.category || ''"
        />

        <div class="store-card-body">
            <h3 class="store-card-name">
                <a
                    :href="href"
                    class="store-card-link"
                    @click="handleClick"
                >{{ store.name }}</a>
            </h3>

            <p class="store-card-meta">
                <span>{{ store.category }}</span>
                <span v-if="store.location">{{ store.location }}</span>
            </p>

            <p class="store-card-stats">
                <span v-if="store.productCount > 0">{{ productCountLabel(store.productCount) }}</span>
                <span
                    v-else
                    class="is-muted"
                >No listings yet</span>
                <span
                    v-if="hasRating"
                    class="store-card-rating"
                >
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z" /></svg>
                    <span>{{ store.rating.toFixed(1) }}</span>
                    <span class="sr-only">out of 5,</span>
                    <span class="is-muted">({{ store.reviewCount }} {{ store.reviewCount === 1 ? 'review' : 'reviews' }})</span>
                </span>
            </p>
        </div>

        <ul
            v-if="store.previewImages && store.previewImages.length"
            class="store-card-previews"
            aria-hidden="true"
        >
            <li
                v-for="(image, index) in store.previewImages"
                :key="index"
            >
                <img
                    :src="image"
                    alt=""
                    width="72"
                    height="72"
                    loading="lazy"
                    decoding="async"
                    @error="$event.target.closest('li').hidden = true"
                >
            </li>
        </ul>

        <span
            class="store-card-go"
            aria-hidden="true"
        >
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
        </span>
    </article>
</template>
