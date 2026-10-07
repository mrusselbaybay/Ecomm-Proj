// Seller vouchers on the buyer side (Buyer\VoucherController).
// The wallet is module-level so the product page, My Vouchers and checkout
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

    walletPromise = buyerApi('/buyer/vouchers')
        .then((data) => {
            wallet.value = data || [];
            walletLoaded = true;

            return wallet.value;
        })
        .catch((err) => {
            walletError.value = err?.message || 'Could not load your vouchers.';

            return wallet.value;
        })
        .finally(() => {
            isLoadingWallet.value = false;
            walletPromise = null;
        });

    return walletPromise;
}

const claimedVoucherIds = computed(() => new Set(wallet.value.map((entry) => entry.voucherId)));

async function claim(voucherId) {
    const entry = await buyerApi(`/buyer/vouchers/${encodeURIComponent(voucherId)}/claim`, { method: 'POST' });
    wallet.value = [entry, ...wallet.value.filter((e) => e.voucherId !== voucherId)];

    return entry;
}

// Public: claimable vouchers for one product, keyed by product id for the session.
const productVoucherCache = new Map();

async function fetchProductVouchers(productId, { force = false } = {}) {
    if (!force && productVoucherCache.has(productId)) {
        return productVoucherCache.get(productId);
    }

    const data = (await buyerApi(`/products/${encodeURIComponent(productId)}/vouchers`)) || [];
    productVoucherCache.set(productId, data);

    return data;
}

/**
 * { sellers, platform }: applicable seller vouchers per seller order and
 * BuyTheWay vouchers valued on top of the seller picks, each with the best
 * pick. lines: [{ key, product_id, variant_id, quantity }];
 * selected: { sellerId: { discount, shipping } } for the buyer's own picks.
 */
function quote(lines, shippingMethod, { addressId = null, selected = null } = {}) {
    return buyerApi('/buyer/vouchers/quote', {
        method: 'POST',
        body: JSON.stringify({ lines, shipping_method: shippingMethod, address_id: addressId, selected }),
    });
}

/** After checkout the per-buyer usage changed; refetch on next open. */
function invalidateWallet() {
    walletLoaded = false;
}

export function voucherDateLabel(iso) {
    return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function useBuyerVouchers() {
    return {
        wallet,
        isLoadingWallet,
        walletError,
        claimedVoucherIds,
        loadWallet,
        claim,
        fetchProductVouchers,
        quote,
        invalidateWallet,
    };
}
