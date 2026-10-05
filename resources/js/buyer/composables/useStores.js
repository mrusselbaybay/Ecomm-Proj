// resources/js/buyer/composables/useStores.js
//
// Public store directory + store pages, backed by App\Http\Controllers\
// StoreController (GET /api/stores, GET /api/stores/{id}) and the
// store-scoped product catalog (GET /api/products?seller_id=...). Browsing
// is public, so no bearer token is sent.
//
// Every response is kept in a small per-URL cache for the page's lifetime.
// Coming back to the directory or a store (breadcrumb, browser Back, the
// product page's back button) renders the cached results immediately, so
// Dashboard.vue can restore the scroll position, while a fresh copy loads
// in the background.

const cache = new Map();
const MAX_CACHED = 40;

function queryString(params) {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params || {})) {
        for (const item of Array.isArray(value) ? value : [value]) {
            if (item !== undefined && item !== null && item !== '' && item !== false) {
                search.append(key, item === true ? '1' : String(item));
            }
        }
    }

    const query = search.toString();

    return query ? `?${query}` : '';
}

export function storesEndpoint(params) {
    return `/api/stores${queryString(params)}`;
}

export function storeEndpoint(id) {
    return `/api/stores/${encodeURIComponent(id)}`;
}

export function storeProductsEndpoint(sellerId, params) {
    return `/api/products${queryString({ seller_id: sellerId, ...params })}`;
}

export function cachedResponse(url) {
    return cache.get(url) || null;
}

/**
 * GETs a JSON endpoint, caching the parsed body. Throws Error(message) with
 * `status` on a non-2xx response; an aborted request throws AbortError.
 */
export async function fetchJson(url, { signal } = {}) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal
    });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(body.message || 'Something went wrong. Please try again.');
        error.status = response.status;

        throw error;
    }

    cache.delete(url);
    cache.set(url, body);

    if (cache.size > MAX_CACHED) {
        cache.delete(cache.keys().next().value);
    }

    return body;
}

/**
 * One "latest request wins" channel. Starting a request aborts the one
 * before it, and a response that arrives after a newer request started is
 * reported as stale instead of replacing newer results.
 *
 * run(url) resolves to { body } or { stale: true }; real failures throw.
 */
export function createLatestRequest() {
    let controller = null;
    let sequence = 0;

    async function run(url) {
        controller?.abort();
        controller = new AbortController();

        const id = ++sequence;

        try {
            const body = await fetchJson(url, { signal: controller.signal });

            return id === sequence ? { body } : { stale: true };
        } catch (err) {
            if (err?.name === 'AbortError' || id !== sequence) {
                return { stale: true };
            }

            throw err;
        }
    }

    function cancel() {
        sequence += 1;
        controller?.abort();
        controller = null;
    }

    return { run, cancel };
}

/** "On BuyTheWay since March 2026"-style month label, or ''. */
export function joinedLabel(iso) {
    const date = iso ? new Date(iso) : null;

    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
}

export function productCountLabel(count) {
    const total = Number(count) || 0;

    return `${total} ${total === 1 ? 'product' : 'products'}`;
}
