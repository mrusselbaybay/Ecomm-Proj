// Seller-funded product coupons on the buyer side (Buyer\CouponController).
// The wallet is module-level so the product page, My Coupons and checkout
// share one copy and one fetch.
import { computed, ref } from 'vue';
import { buyerApi } from './useBuyerApi';

const wallet = ref([]);
const isLoadingWallet = ref(false);
const walletError = ref('');
let walletLoaded = false;
let walletPromise = null;

function loadWallet({ force = false } = {}) {
    if (walletPromise && !force) {
        return walletPromise;
    }
    if (walletLoaded && !force) {
        return Promise.resolve(wallet.value);
    }

    isLoadingWallet.value = true;
    walletError.value = '';

    walletPromise = buyerApi('/buyer/coupons')
        .then((data) => {
            wallet.value = data || [];
            walletLoaded = true;

            return wallet.value;
        })
        .catch((err) => {
            walletError.value = err?.message || 'Could not load your coupons.';

            return wallet.value;
        })
        .finally(() => {
            isLoadingWallet.value = false;
            walletPromise = null;
        });

    return walletPromise;
}

const claimedCouponIds = computed(() => new Set(wallet.value.map((entry) => entry.couponId)));

async function claim(couponId) {
    const entry = await buyerApi(`/buyer/coupons/${encodeURIComponent(couponId)}/claim`, { method: 'POST' });
    wallet.value = [entry, ...wallet.value.filter((e) => e.couponId !== couponId)];

    return entry;
}

// Public: claimable coupons for one product, keyed by product id for the session.
const productCouponCache = new Map();

async function fetchProductCoupons(productId, { force = false } = {}) {
    if (!force && productCouponCache.has(productId)) {
        return productCouponCache.get(productId);
    }

    const data = (await buyerApi(`/products/${encodeURIComponent(productId)}/coupons`)) || [];
    productCouponCache.set(productId, data);

    return data;
}

/**
 * Usable wallet coupons per checkout line, best first, with server-side
 * discounts. lines: [{ key, product_id, variant_id }]
 */
function quote(lines) {
    return buyerApi('/buyer/coupons/quote', {
        method: 'POST',
        body: JSON.stringify({ lines }),
    });
}

/** Marks wallet entries used after a successful checkout (no refetch needed). */
function markUsed(ids) {
    const used = new Set(ids);
    const now = new Date().toISOString();
    wallet.value = wallet.value.map((e) => (used.has(e.id) ? { ...e, status: 'used', usedAt: now } : e));
}

export function couponExpiryLabel(iso) {
    return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function useBuyerCoupons() {
    return {
        wallet,
        isLoadingWallet,
        walletError,
        claimedCouponIds,
        loadWallet,
        claim,
        fetchProductCoupons,
        quote,
        markUsed,
    };
}
