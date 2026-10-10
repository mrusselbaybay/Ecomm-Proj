// resources/js/buyer/composables/useBuyerProducts.js
//
// Backed by the public Laravel product catalog (App\Http\Controllers\
// ProductController + GET /api/products), not Supabase directly — see
// that controller's docblock for why. No auth required: browsing is
// public, same as the previous hardcoded-array behavior in Dashboard.vue.
//
// The in-memory list is the source for the storefront, category pages,
// deals, the wishlist and product pages opened from them, so it must not
// go stale for the whole visit:
//   - refreshProducts() reloads it quietly (no loading state), and only
//     the newest request may replace the list;
//   - productsLoadedAt lets a view decide it's old enough to refresh when
//     the buyer comes back to it (Dashboard.vue);
//   - a review written in this tab updates the matching product at once
//     (useReviewSync.js), and a response that was already in flight can't
//     put the old rating back.
import { ref } from 'vue';
import { applyStats, onReviewChange, withLatestStats } from './useReviewSync';
import { authHeaders } from './useBuyerSession';

const products = ref([]);
const isLoadingProducts = ref(true);
const loadError = ref('');
const productsLoadedAt = ref(0);

// Shared across every caller of this composable (module scope), so two
// components mounting at once — or a component re-triggering a load — reuse
// the same request instead of firing duplicates at /api/products.
let inFlight = null;
let requestSeq = 0;
let lastParams = {};

function catalogUrl(params) {
    const query = new URLSearchParams(
        Object.fromEntries(
            Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''),
        ),
    ).toString();

    return `/api/products${query ? `?${query}` : ''}`;
}

async function requestCatalog(params) {
    let headers = { Accept: 'application/json' };
    try {
        const authenticated = await authHeaders();
        headers.Authorization = authenticated.Authorization;
    } catch {
        // The catalog is public; signed-out browsing is expected.
    }
    const response = await fetch(catalogUrl(params), {
        headers,
    });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Could not load products.');
    }

    return body.data || [];
}

async function fetchProducts(params, { quiet = false } = {}) {
    const seq = ++requestSeq;
    const startedAt = Date.now();

    lastParams = params;

    if (!quiet) {
        isLoadingProducts.value = true;
        loadError.value = '';
    }

    try {
        const rows = await requestCatalog(params);

        // A newer load started meanwhile: its answer is the one to keep.
        if (seq !== requestSeq) {
            return;
        }

        products.value = rows.map(product => withLatestStats(product, startedAt));
        productsLoadedAt.value = Date.now();
        loadError.value = '';
    } catch (err) {
        if (seq !== requestSeq) {
            return;
        }

        console.error('Error loading products:', err);

        // A failed background refresh keeps the list the buyer is looking at.
        if (!quiet) {
            loadError.value = err?.message || 'Something went wrong while loading products.';
            products.value = [];
        }
    } finally {
        if (seq === requestSeq) {
            isLoadingProducts.value = false;
        }
    }
}

async function loadProducts(params = {}) {
    if (!inFlight) {
        inFlight = fetchProducts(params).finally(() => {
            inFlight = null;
        });
    }

    return inFlight;
}

/**
 * Reloads the list in the background with the last parameters. Skipped
 * while a load is already running (that one is fresh).
 */
function refreshProducts() {
    if (inFlight) {
        return inFlight;
    }

    inFlight = fetchProducts(lastParams, { quiet: true }).finally(() => {
        inFlight = null;
    });

    return inFlight;
}

onReviewChange((stats) => {
    products.value.forEach(product => applyStats(product, stats));
});

async function getProductById(id) {
    // Prefer whatever's already in memory to avoid a network round-trip
    // when navigating from the grid into a product's detail page.
    const cached = products.value.find((p) => p.id === id);

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(`/api/products/${encodeURIComponent(id)}`, {
            headers: await optionalCatalogHeaders(),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            return null;
        }

        return body.data || null;
    } catch (err) {
        console.error('Error loading product:', err);

        return null;
    }
}

async function optionalCatalogHeaders() {
    try {
        const headers = await authHeaders();
        return { Accept: 'application/json', Authorization: headers.Authorization };
    } catch {
        return { Accept: 'application/json' };
    }
}

export function useBuyerProducts() {
    return {
        products,
        isLoadingProducts,
        loadError,
        productsLoadedAt,
        loadProducts,
        refreshProducts,
        getProductById,
    };
}
