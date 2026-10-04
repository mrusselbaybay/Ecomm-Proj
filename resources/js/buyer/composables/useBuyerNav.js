// resources/js/buyer/composables/useBuyerNav.js
//
// Cross-page navigation requests. Every buyer page renders inside
// Dashboard.vue, which owns the view switch, but the shared Header is
// embedded by a dozen pages that each re-emit only the events they care
// about. Rather than threading "open wishlist" / "open orders" through all
// of them, the Header (or anything else) calls requestBuyerView() and
// Dashboard watches the request and runs its own open*() function.
import { ref } from 'vue';

const navRequest = ref(null);

let seq = 0;

/**
 * @param {'home'|'cart'|'account'|'orders'|'wishlist'|'reviews'|'addresses'|'payments'|'product'|'category'|'deals'} view
 * @param {object|null} payload  the product for 'product', the category name for 'category'
 */
export function requestBuyerView(view, payload = null) {
    seq += 1;
    navRequest.value = { view, payload, seq };
}

export function useBuyerNav() {
    return {
        navRequest,
        requestBuyerView,
    };
}
