// resources/js/buyer/composables/useReviewSync.js
//
// Keeps every in-memory copy of a product's rating in step after the buyer
// writes, edits or deletes a review.
//
// The buyer review API returns the product's new public numbers with each
// change ({ productId, rating, reviewCount } — App\Support\ReviewStats,
// counted from the same rows as every card, product page and store).
// publishReviewChange() hands them to every module that holds product
// copies (the catalogue, the cart, cached catalogue responses, the
// product page's review cache, the open product page), each registered
// through onReviewChange(). Pages that list reviews watch reviewsVersion
// and refetch.
//
// Responses already in flight when the change happened can still arrive
// afterwards with the old numbers; withLatestStats() lets a loader put the
// newer numbers back on before using such a response.
import { ref } from 'vue';

/** Bumped on every review change in this tab. */
export const reviewsVersion = ref(0);

// productId -> { rating, reviewCount, at }
const latest = new Map();
const listeners = new Set();

/**
 * Registers a handler for review changes. Returns the unregister function.
 *
 * @param {(stats: { productId: string, rating: number|null, reviewCount: number }) => void} handler
 */
export function onReviewChange(handler) {
    listeners.add(handler);

    return () => listeners.delete(handler);
}

/**
 * @param {{ productId: string, rating: number|null, reviewCount: number }|null|undefined} stats
 */
export function publishReviewChange(stats) {
    if (!stats?.productId) {
        return;
    }

    const clean = {
        productId: stats.productId,
        rating: typeof stats.rating === 'number' ? stats.rating : null,
        reviewCount: Number(stats.reviewCount) || 0
    };

    latest.set(clean.productId, { ...clean, at: Date.now() });
    reviewsVersion.value += 1;

    listeners.forEach((handler) => {
        try {
            handler(clean);
        } catch (err) {
            console.error('Review sync handler failed:', err);
        }
    });
}

/**
 * Copies the rating and count onto a product-like object in place.
 */
export function applyStats(target, stats) {
    if (target && stats && target.id === stats.productId) {
        target.rating = stats.rating;
        target.reviewCount = stats.reviewCount;
    }

    return target;
}

/**
 * For a product that came from a request started at `requestedAt` (ms):
 * if this tab changed that product's reviews after the request began, the
 * response predates the change, so the newer numbers win.
 */
export function withLatestStats(product, requestedAt) {
    const known = product?.id ? latest.get(product.id) : null;

    if (known && known.at > requestedAt) {
        product.rating = known.rating;
        product.reviewCount = known.reviewCount;
    }

    return product;
}
