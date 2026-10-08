<script setup>
/*
|--------------------------------------------------------------------------
| SearchStoreResults — "Stores matching …" on the search results page
|--------------------------------------------------------------------------
|
| Stores whose name matches the header search, from GET /api/stores (the
| same visibility rules as the store directory). Only the first few are
| shown — best name match first (exact, prefix, whole word, ...; product
| count breaks ties) — each with a clear Visit store action; "View all matching stores"
| opens the store directory filtered by the same words. Product filters on
| the page never apply here.
|
| Every number shown comes from the store API: the product count, and a
| rating only when the store's products have reviews (labelled as such —
| it is an average of product reviews, not a separate store rating).
| Follower counts aren't part of the store list API, so none are shown.
|
| The section isn't rendered when no store matches. It reports
| { status, total } upwards so the page can show one combined "no results"
| message. A cached answer renders immediately (so coming back from a
| store or product restores the page height and scroll position), and a
| latest-request-wins channel stops a slow older answer replacing a newer
| one.
|
*/
import { onUnmounted, ref, watch } from 'vue';
import StoreLogo from './StoreLogo.vue';
import { cachedResponse, createLatestRequest, productCountLabel, storesEndpoint } from '../composables/useStores';
import { searchStores } from '../composables/useStoreBrowseState';

const props = defineProps({
    query: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['open-store', 'state']);

const SHOWN = 3;

function endpoint() {
    return storesEndpoint({ search: props.query, sort: 'relevance', per_page: SHOWN, page: 1 });
}

const stores = ref([]);
const total = ref(0);
const status = ref('loading');

const request = createLatestRequest();

function apply(body) {
    stores.value = body.data || [];
    total.value = body.meta?.total ?? stores.value.length;
    status.value = 'ready';
}

function report() {
    emit('state', { status: status.value, total: total.value });
}

async function load() {
    const cached = cachedResponse(endpoint());

    if (cached) {
        apply(cached);
    } else {
        status.value = 'loading';
    }

    report();

    try {
        const result = await request.run(endpoint());

        if (result.stale) {
            return;
        }

        apply(result.body);
    } catch {
        if (!cached) {
            status.value = 'error';
        }
    }

    report();
}

watch(() => props.query, load, { immediate: true });

onUnmounted(() => request.cancel());

function href(store) {
    return `${window.location.pathname}?store=${encodeURIComponent(store.id)}`;
}

function open(event, store) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
        return;
    }

    event.preventDefault();
    emit('open-store', store);
}

function hasRating(store) {
    return typeof store.rating === 'number' && store.reviewCount > 0;
}
</script>

<template>
    <section
        v-if="status !== 'ready' || total > 0"
        class="results-section srch-stores"
        aria-labelledby="srch-stores-title"
        :aria-busy="status === 'loading'"
    >
        <div class="srch-section-head">
            <h2
                id="srch-stores-title"
                class="srch-section-title"
            >
                Stores matching &ldquo;{{ query }}&rdquo;
            </h2>
            <button
                v-if="status === 'ready' && total > SHOWN"
                type="button"
                class="link-btn srch-view-all"
                @click="searchStores(query)"
            >
                View all {{ total }} matching stores
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
            </button>
        </div>

        <ul
            v-if="status === 'loading'"
            class="srch-store-list"
            aria-hidden="true"
        >
            <li class="srch-store is-skeleton">
                <span class="skeleton store-logo is-sm"></span>
                <span class="srch-store-text">
                    <span class="skeleton is-line"></span>
                    <span class="skeleton is-line is-short"></span>
                </span>
            </li>
        </ul>

        <p
            v-else-if="status === 'error'"
            class="results-section-error"
            role="alert"
        >
            Couldn&rsquo;t load matching stores.
            <button
                type="button"
                class="link-btn"
                @click="load"
            >
                Try again
            </button>
        </p>

        <ul
            v-else
            class="srch-store-list"
        >
            <li
                v-for="store in stores"
                :key="store.id"
                class="srch-store"
            >
                <StoreLogo
                    size="sm"
                    :name="store.name"
                    :src="store.logo || ''"
                    :category="store.category || ''"
                />
                <div class="srch-store-text">
                    <a
                        :href="href(store)"
                        class="srch-store-name"
                        @click="open($event, store)"
                    >{{ store.name }}</a>
                    <p class="srch-store-meta">
                        <span v-if="store.category">{{ store.category }}</span>
                        <span v-if="store.productCount > 0">{{ productCountLabel(store.productCount) }}</span>
                        <span
                            v-if="hasRating(store)"
                            class="srch-store-rating"
                        >
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z" /></svg>
                            <span>
                                {{ store.rating.toFixed(1) }}<span class="sr-only"> out of 5</span>
                                <span class="srch-store-rating-src">from {{ store.reviewCount }} product {{ store.reviewCount === 1 ? 'review' : 'reviews' }}</span>
                            </span>
                        </span>
                    </p>
                </div>
                <a
                    :href="href(store)"
                    class="btn btn-secondary srch-store-visit"
                    :aria-label="`Visit ${store.name}`"
                    @click="open($event, store)"
                >
                    Visit store
                </a>
            </li>
        </ul>
    </section>
</template>
