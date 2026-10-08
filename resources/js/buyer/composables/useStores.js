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
//
// A review written in this tab (useReviewSync.js) patches the product
// rows cached here in place and drops cached store responses, whose
// average only the server can recompute, so revisiting either shows the
// new numbers.
import { applyStats, onReviewChange } from './useReviewSync';

const cache = new Map();
const MAX_CACHED = 40;

onReviewChange((stats) => {
    for (const [url, body] of [...cache.entries()]) {
        if (url.startsWith('/api/stores')) {
            cache.delete(url);
        } else if (Array.isArray(body?.data)) {
            body.data.forEach(product => applyStats(product, stats));
        } else if (body?.data && typeof body.data === 'object') {
            applyStats(body.data, stats);
        }
    }
});

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

export function productsEndpoint(params) {
    return `/api/products${queryString(params)}`;
}

export function relatedProductsEndpoint(params) {
    return `/api/products/related${queryString(params)}`;
}

export function storeProductsEndpoint(sellerId, params) {
    return `/api/products${queryString({ seller_id: sellerId, ...params })}`;
}

export function cachedResponse(url) {
    return cache.get(url) || null;
}

// Longest a catalogue request may take before the page gives up and offers
// a retry (the server's own limit is 30 seconds).
export const REQUEST_TIMEOUT_MS = 20000;

/**
 * GETs a JSON endpoint, caching the parsed body. Throws Error(message) with
 * `status` on a non-2xx response, and an Error named TimeoutError when no
 * answer arrives within `timeout` ms. Aborting through `signal` (a newer
 * request replacing this one) throws AbortError.
 */
export async function fetchJson(url, { signal, timeout = REQUEST_TIMEOUT_MS } = {}) {
    const controller = new AbortController();
    let timedOut = false;

    const timer = setTimeout(() => {
        timedOut = true;
        controller.abort();
    }, timeout);

    const forwardAbort = () => controller.abort();

    if (signal?.aborted) {
        controller.abort();
    }

    signal?.addEventListener('abort', forwardAbort, { once: true });

    let response;
    let body;

    try {
        response = await fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal
        });
        body = await response.json().catch(() => ({}));
    } catch (err) {
        if (timedOut) {
            const error = new Error('This is taking longer than it should. Check your connection and try again.');
            error.name = 'TimeoutError';

            throw error;
        }

        throw err;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', forwardAbort);
    }

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
