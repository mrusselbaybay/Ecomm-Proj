// resources/js/buyer/composables/useProductReviews.js
//
// Lazy, paginated, filterable reader for a single product's public
// reviews (GET /api/products/{id}/reviews — added in ProductController).
// Only one product's reviews are viewed at a time (the cart drawer), so
// this is a module-scoped singleton like useBuyerProducts.js.
//
// - Nothing is fetched until open(productId) is called (i.e. the buyer
//   actually opens the drawer) — the cart's initial load never pulls
//   review bodies for every line item.
// - First page + summary per (product, filter) is cached, so reopening a
//   drawer or flipping back to a filter is instant; a background refresh
//   still runs to catch new reviews.
import { computed, ref } from 'vue';

const PER_PAGE = 5;

const productId = ref(null);
const summary = ref(null); // { average, total, breakdown: {5..1}, with_images }
const items = ref([]);
const meta = ref({ current_page: 0, last_page: 1, total: 0, per_page: PER_PAGE });
const activeFilter = ref('all'); // 'all' | '5'..'1' | 'images'
const isLoading = ref(false);
const isLoadingMore = ref(false);
const error = ref('');

// productId -> { summary, byFilter: { [filter]: { items, meta } } }
const cache = new Map();

function buildQuery(page) {
    const params = new URLSearchParams({
        per_page: String(PER_PAGE),
        page: String(page),
    });

    if (/^[1-5]$/.test(activeFilter.value)) {
        params.set('rating', activeFilter.value);
    }

    if (activeFilter.value === 'images') {
        params.set('has_images', '1');
    }

    return params.toString();
}

async function fetchPage(page) {
    const response = await fetch(
        `/api/products/${encodeURIComponent(productId.value)}/reviews?${buildQuery(page)}`,
        { headers: { Accept: 'application/json' } },
    );
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Could not load reviews.');
    }

    return body;
}

function writeCache() {
    const entry = cache.get(productId.value) || { summary: null, byFilter: {} };

    entry.summary = summary.value;
    entry.byFilter[activeFilter.value] = { items: items.value, meta: meta.value };
    cache.set(productId.value, entry);
}

async function load({ reset = true } = {}) {
    if (!productId.value) {
        return;
    }

    if (reset) {
        isLoading.value = true;
    } else {
        isLoadingMore.value = true;
    }

    error.value = '';

    try {
        const nextPage = reset ? 1 : meta.value.current_page + 1;
        const body = await fetchPage(nextPage);

        summary.value = body.summary || summary.value;
        meta.value = body.meta || meta.value;
        items.value = reset ? (body.data || []) : [...items.value, ...(body.data || [])];

        writeCache();
    } catch (err) {
        error.value = err?.message || 'Could not load reviews.';

        if (reset && items.value.length === 0) {
            items.value = [];
        }
    } finally {
        isLoading.value = false;
        isLoadingMore.value = false;
    }
}

function hydrateFromCache(filter) {
    const entry = cache.get(productId.value);

    if (entry?.byFilter?.[filter]) {
        summary.value = entry.summary;
        items.value = entry.byFilter[filter].items;
        meta.value = entry.byFilter[filter].meta;

        return true;
    }

    return false;
}

function open(id) {
    productId.value = id;
    activeFilter.value = 'all';
    error.value = '';

    if (hydrateFromCache('all')) {
        isLoading.value = false;
        // Still refresh quietly so a newly posted review shows up.
        load({ reset: true });

        return;
    }

    summary.value = null;
    items.value = [];
    meta.value = { current_page: 0, last_page: 1, total: 0, per_page: PER_PAGE };
    load({ reset: true });
}

function setFilter(filter) {
    if (filter === activeFilter.value) {
        return;
    }

    activeFilter.value = filter;

    if (hydrateFromCache(filter)) {
        isLoading.value = false;
        load({ reset: true });

        return;
    }

    items.value = [];
    load({ reset: true });
}

const canLoadMore = computed(
    () => !isLoading.value && meta.value.current_page > 0 && meta.value.current_page < meta.value.last_page,
);

export function useProductReviews() {
    return {
        productId,
        summary,
        items,
        meta,
        activeFilter,
        isLoading,
        isLoadingMore,
        error,
        canLoadMore,

        open,
        setFilter,
        loadMore: () => load({ reset: false }),
        retry: () => load({ reset: true }),
    };
}
