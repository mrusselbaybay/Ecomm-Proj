import { ref, computed, watch } from 'vue';
import { buyerApi } from './useBuyerApi';
import { getSupabase } from './useBuyerSession';

// Cart lives client-side only: there's no cart table by design — checkout
// takes the cart as a payload and CheckoutService re-validates every
// price/stock/seller against the real rows. localStorage is what makes it
// survive a hard refresh (same pattern as favorites below). Persisted
// name/price/image can go stale if a seller edits the product later; that's
// cosmetic, since checkout recalculates regardless.
const CART_STORAGE_KEY = 'nexmart_buyer_cart';

function loadStoredCart() {
    try {
        const stored = JSON.parse(localStorage.getItem(CART_STORAGE_KEY) || '[]');

        return Array.isArray(stored) ? stored : [];
    } catch (err) {
        return [];
    }
}

const cart = ref(loadStoredCart());

// deep: true — quantity and `selected` toggles mutate cart items in place,
// so a shallow watch would miss them.
watch(cart, (value) => {
    try {
        localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(value));
    } catch (err) {
        // Storage unavailable (private browsing etc.) — the cart just
        // won't survive a refresh this session.
    }
}, { deep: true });

// Backed by the Laravel Buyer API (/api/buyer/wishlist ->
// App\Http\Controllers\Buyer\WishlistController, buyer_wishlist_items
// table). localStorage is kept only as an offline cache and as the store
// for a signed-out visitor's favorites, which are merged up to the server
// by loadWishlist() once they sign in.
const FAVORITES_STORAGE_KEY = 'nexmart_buyer_favorites';

function loadStoredFavorites() {
    try {
        const stored = JSON.parse(localStorage.getItem(FAVORITES_STORAGE_KEY) || '[]');

        return Array.isArray(stored) ? stored : [];
    } catch (err) {
        return [];
    }
}

const favorites = ref(loadStoredFavorites());

function persistFavorites() {
    try {
        localStorage.setItem(FAVORITES_STORAGE_KEY, JSON.stringify(favorites.value));
    } catch (err) {
        // Storage unavailable (private browsing etc.) — favorites just
        // won't survive a refresh this session; nothing to recover from
        // mid-click.
    }
}

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
*/

/**
 * `variant` is either null/undefined (simple product — no options) or a
 * variant object as returned by ProductController@transform / the
 * selectedVariant computed in ProductDetails.vue: { id, sku, price,
 * image, option_values, stock, status }. Its id/sku/price and a
 * human-readable label built from option_values are carried through the
 * cart into the checkout payload; everything is re-validated against the
 * real row server-side in CheckoutService regardless.
 */
function addToCart(product, variant, quantity) {
    if (!product) {
        return;
    }

    const variantId = variant?.id || null;

    const existingItem = cart.value.find(item =>
        item.productId === product.id &&
        item.variantId === variantId
    );

    if (existingItem) {
        existingItem.quantity += quantity;

        return;
    }

    const variantLabel = variant?.option_values
        ? Object.entries(variant.option_values).map(([k, v]) => `${k}: ${v}`).join(', ')
        : null;

    cart.value.push({
        cartId: Date.now() + Math.random(),
        productId: product.id,
        variantId,
        sku: variant?.sku || product.sku || null,
        name: product.name,
        price: Number(variant?.price ?? product.price),
        category: product.category,
        variation: variantLabel,
        quantity: quantity,
        seller: product.seller || 'NEXMART Seller',
        image: variant?.image?.url ||
            (Array.isArray(product.images) ? product.images[0] || null : null),
        selected: true
    });
}

function removeFromCart(cartId) {
    cart.value = cart.value.filter(item => item.cartId !== cartId);
}

function increaseCartQuantity(cartId) {
    const item = cart.value.find(item => item.cartId === cartId);

    if (item) {
        item.quantity++;
    }
}

function decreaseCartQuantity(cartId) {
    const item = cart.value.find(item => item.cartId === cartId);

    if (item && item.quantity > 1) {
        item.quantity--;
    }
}

function toggleCartItem(cartId) {
    const item = cart.value.find(item => item.cartId === cartId);

    if (item) {
        item.selected = !item.selected;
    }
}

function toggleSellerItems(seller, selected) {
    cart.value
        .filter(item => item.seller === seller)
        .forEach(item => {
            item.selected = selected;
        });
}

/*
|--------------------------------------------------------------------------
| Cart Computed Values
|--------------------------------------------------------------------------
*/

const selectedItems = computed(() => {
    return cart.value.filter(item => item.selected);
});

const selectedItemCount = computed(() => {
    return selectedItems.value.reduce(
        (total, item) => total + item.quantity,
        0
    );
});

const cartSubtotal = computed(() => {
    return selectedItems.value.reduce(
        (total, item) => total + (item.price * item.quantity),
        0
    );
});

const cartItemCount = computed(() => {
    return cart.value.reduce(
        (total, item) => total + item.quantity,
        0
    );
});

const sellers = computed(() => {
    return [...new Set(cart.value.map(item => item.seller))];
});

const isCartEmpty = computed(() => {
    return cart.value.length === 0;
});

/*
|--------------------------------------------------------------------------
| Select All
|--------------------------------------------------------------------------
*/

const allItemsSelected = computed(() => {
    return cart.value.length > 0 &&
        cart.value.every(item => item.selected);
});

function toggleSelectAll(selected) {
    cart.value.forEach(item => {
        item.selected = selected;
    });
}

/*
|--------------------------------------------------------------------------
| Favorites
|--------------------------------------------------------------------------
*/

let wishlistLoaded = false;

function isFavorite(productId) {
    return favorites.value.includes(productId);
}

/**
 * Pulls the signed-in buyer's saved wishlist and merges it with anything
 * they favorited while signed out (pushing those up to the server). Safe
 * to call when signed out — it just 401s and leaves the local list alone.
 */
async function loadWishlist({ force = false } = {}) {
    if (wishlistLoaded && !force) {
        return;
    }

    try {
        const serverIds = await apiFetch('/buyer/wishlist');
        const serverSet = new Set(serverIds);
        const localOnly = favorites.value.filter(id => !serverSet.has(id));

        favorites.value = [...serverIds, ...localOnly];
        persistFavorites();
        wishlistLoaded = true;

        // Adopt favorites made while signed out.
        for (const productId of localOnly) {
            apiFetch('/buyer/wishlist', {
                method: 'POST',
                body: JSON.stringify({ product_id: productId }),
            }).catch(() => {});
        }
    } catch (err) {
        // Signed out or transient — keep the local list as-is.
    }
}

function toggleFavorite(productId) {
    const wasFavorite = isFavorite(productId);

    if (wasFavorite) {
        favorites.value = favorites.value.filter(id => id !== productId);
    } else {
        favorites.value.push(productId);
    }

    persistFavorites();

    const request = wasFavorite
        ? apiFetch(`/buyer/wishlist/${encodeURIComponent(productId)}`, { method: 'DELETE' })
        : apiFetch('/buyer/wishlist', {
            method: 'POST',
            body: JSON.stringify({ product_id: productId }),
        });

    request.catch(err => {
        // 401 => signed out, favorites stay local-only (merged in on next
        // sign-in via loadWishlist). Any other failure: roll back so the
        // heart reflects what's actually stored.
        if (err?.status && err.status !== 401) {
            if (wasFavorite) {
                favorites.value.push(productId);
            } else {
                favorites.value = favorites.value.filter(id => id !== productId);
            }

            persistFavorites();
        }
    });
}

const favoriteCount = computed(() => {
    return favorites.value.length;
});

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
|
| Backed by the Laravel Buyer API (routes/buyer.php + App\Http\Controllers\
| Buyer\CheckoutController / OrderController), the same pattern the seller
| side already uses for order status changes: every request forwards the
| current Supabase access token as a Bearer header (see useBuyerSession.js).
|
| Status values are the canonical set already defined on the `orders`
| table / Order::STATUSES (database/migrations/2026_08_19_100000_create_
| orders_table.php, app/Models/Order.php) — NOT an invented buyer-only
| vocabulary — so a status set here always matches what the seller side
| sees and can transition.
|
*/

const ORDER_STATUSES = {
    TO_SHIP: 'New',
    PROCESSING: 'Processing',
    IN_TRANSIT: 'In Transit',
    OUT_FOR_DELIVERY: 'In Transit', // no separate DB state yet — same as IN_TRANSIT
    DELIVERED: 'Delivered',
    CANCELLED: 'Cancelled',
    RETURNED: 'Cancelled', // no returns subsystem yet — see note on submitReturnRequest()
};

const orders = ref([]);
const isLoadingOrders = ref(false);
const ordersLoadError = ref('');
const isPlacingOrder = ref(false);
const checkoutError = ref('');

// Thin alias kept so the call sites below read unchanged — the shared
// implementation now lives in useBuyerApi.js.
const apiFetch = buyerApi;

async function loadOrders() {
    isLoadingOrders.value = true;
    ordersLoadError.value = '';

    try {
        orders.value = await apiFetch('/buyer/orders');
    } catch (err) {
        console.error('Error loading orders:', err);
        ordersLoadError.value = err?.message || 'Something went wrong while loading your orders.';
        orders.value = [];
    } finally {
        isLoadingOrders.value = false;
    }
}

async function getOrderById(orderId) {
    try {
        return await apiFetch(`/buyer/orders/${encodeURIComponent(orderId)}`);
    } catch (err) {
        console.error('Error loading order:', err);

        return null;
    }
}

/**
 * Submits a checkout payload (the same shape Checkout.vue already builds
 * in placeOrder()'s orderPayload) to the backend, which re-validates
 * price/stock server-side and creates one real order per seller. Refreshes
 * the orders list on success so "My Orders" reflects the new order(s)
 * without a full reload.
 */
async function placeOrder(payload) {
    isPlacingOrder.value = true;
    checkoutError.value = '';

    try {
        const createdOrders = await apiFetch('/buyer/checkout', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        await loadOrders();

        return createdOrders;
    } catch (err) {
        console.error('Error placing order:', err);
        checkoutError.value = err?.message || 'Could not place your order.';

        throw err;
    } finally {
        isPlacingOrder.value = false;
    }
}

/**
 * Buyer-initiated cancellation \u2014 POST /api/buyer/orders/{id}/cancel
 * (App\Http\Controllers\Buyer\OrderController@cancel). The server only
 * allows it while the order is still "New", restores stock, and writes
 * the same order_status_history entry the seller backend expects.
 * Returns the updated order on success, null on failure (message left in
 * checkoutError).
 *
 * @param {string} orderId  the display id, e.g. "#SN-40412"
 * @param {string} [reason]
 */
async function cancelOrder(orderId, reason) {
    checkoutError.value = '';

    const number = String(orderId || '').replace(/^#/, '');

    try {
        const updated = await apiFetch(`/buyer/orders/${encodeURIComponent(number)}/cancel`, {
            method: 'POST',
            body: JSON.stringify({ reason: reason || null }),
        });

        await loadOrders();

        return updated;
    } catch (err) {
        console.error('Error cancelling order:', err);
        checkoutError.value = err?.message || 'Could not cancel this order.';

        return null;
    }
}

/*
|--------------------------------------------------------------------------
| Reviews
|--------------------------------------------------------------------------
|
| Backed by Buyer\ReviewController (routes/buyer.php) and the real
| `reviews` table — this used to be a local-only stub that didn't persist
| anything ("there is no reviews table/endpoint yet"), which was stale:
| the table already existed on the real database, there just wasn't an
| endpoint wired up to it yet. See ReviewController's docblock.
|
*/

const reviews = ref([]);
const isLoadingReviews = ref(false);
const reviewsLoadError = ref('');

async function loadReviews() {
    isLoadingReviews.value = true;
    reviewsLoadError.value = '';

    try {
        reviews.value = await apiFetch('/buyer/reviews');
    } catch (err) {
        console.error('Error loading reviews:', err);
        reviewsLoadError.value = err?.message || 'Something went wrong while loading your reviews.';
        reviews.value = [];
    } finally {
        isLoadingReviews.value = false;
    }
}

/**
 * `orderItemId` is the real order_items.id (see OrderController's item
 * transform) — not the orderId/itemIndex pair the old stub took, which
 * had no way to identify a specific line item to a real endpoint.
 * Returns the created review on success, or null on failure (the caller
 * is responsible for surfacing the thrown error's message).
 */
async function submitReview(orderItemId, review) {
    try {
        const created = await apiFetch('/buyer/reviews', {
            method: 'POST',
            body: JSON.stringify({
                order_item_id: orderItemId,
                rating: review.rating,
                comment: review.comment || null,
            }),
        });

        return created;
    } catch (err) {
        console.error('Error submitting review:', err);

        throw err;
    }
}

async function updateReview(reviewId, review) {
    try {
        const updated = await apiFetch(`/buyer/reviews/${encodeURIComponent(reviewId)}`, {
            method: 'PUT',
            body: JSON.stringify({
                rating: review.rating,
                comment: review.comment || null,
            }),
        });

        const index = reviews.value.findIndex(r => r.id === reviewId);

        if (index !== -1) {
            reviews.value[index] = updated;
        }

        return updated;
    } catch (err) {
        console.error('Error updating review:', err);

        throw err;
    }
}

async function deleteReview(reviewId) {
    try {
        await apiFetch(`/buyer/reviews/${encodeURIComponent(reviewId)}`, {
            method: 'DELETE',
        });

        reviews.value = reviews.value.filter(r => r.id !== reviewId);

        return true;
    } catch (err) {
        console.error('Error deleting review:', err);

        throw err;
    }
}

/*
|--------------------------------------------------------------------------
| Returns / refunds
|--------------------------------------------------------------------------
|
| Backed by Buyer\ReturnController (POST /api/buyer/returns) and the
| order_return_requests table.
|
| Evidence images: each file is uploaded to the Supabase Storage bucket
| `return-evidence` (path: <buyer-uid>/<random>.<ext>) and the resulting
| public URL is what's stored on the request. If the bucket / policy
| isn't set up yet the upload fails and we fall back to an inline data:
| URL for that file — same behavior as before, just no longer the only
| option. Either way ReturnController stores plain strings and the
| frontend binds them straight to <img :src>.
|
| To activate real storage: create a PUBLIC bucket `return-evidence` and
| add a Storage policy letting `authenticated` INSERT into
| `return-evidence/{auth.uid()}/*`.
|
*/

const RETURN_EVIDENCE_BUCKET = 'return-evidence';

function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(reader.error || new Error('Could not read file.'));
        reader.readAsDataURL(file);
    });
}

async function uploadEvidenceFile(file) {
    try {
        const supabase = getSupabase();
        const { data: { user } } = await supabase.auth.getUser();

        if (!user) {
            throw new Error('not signed in');
        }

        const ext = (file.name?.split('.').pop() || 'jpg').toLowerCase().replace(/[^a-z0-9]/g, '');
        const path = `${user.id}/${Date.now()}-${Math.random().toString(36).slice(2, 10)}.${ext}`;

        const { error } = await supabase.storage
            .from(RETURN_EVIDENCE_BUCKET)
            .upload(path, file, { contentType: file.type || 'image/jpeg', upsert: false });

        if (error) {
            throw error;
        }

        return supabase.storage.from(RETURN_EVIDENCE_BUCKET).getPublicUrl(path).data.publicUrl;
    } catch (err) {
        // Bucket / policy not set up (or offline) — keep the buyer's
        // submission working by inlining the image instead.
        console.warn('return-evidence upload failed, falling back to inline image:', err?.message || err);

        return readFileAsDataUrl(file);
    }
}

/**
 * @param {string} orderItemId  real order_items.id (see OrderController's item transform)
 * @param {{ requestType: string, quantity: number, reason: string, details: string, evidence: File[] }} request
 * @returns {Promise<object>} the created return request
 */
async function submitReturnRequest(orderItemId, request) {
    const evidence = await Promise.all(
        (request.evidence || []).map(file => uploadEvidenceFile(file)),
    );

    try {
        const created = await apiFetch('/buyer/returns', {
            method: 'POST',
            body: JSON.stringify({
                order_item_id: orderItemId,
                request_type: request.requestType,
                reason: request.reason,
                details: request.details,
                quantity: request.quantity,
                evidence,
            }),
        });

        await loadOrders();

        return created;
    } catch (err) {
        console.error('Error submitting return request:', err);

        throw err;
    }
}

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

export function useBuyer() {
    // Merge the server-side wishlist in once per page load (no-op when
    // signed out).
    loadWishlist();

    return {
        cart,
        sellers,
        favorites,

        selectedItems,
        selectedItemCount,
        cartSubtotal,
        cartItemCount,
        isCartEmpty,
        allItemsSelected,
        favoriteCount,

        addToCart,
        removeFromCart,
        increaseCartQuantity,
        decreaseCartQuantity,
        toggleCartItem,
        toggleSellerItems,
        toggleSelectAll,

        isFavorite,
        toggleFavorite,
        loadWishlist,

        ORDER_STATUSES,
        orders,
        isLoadingOrders,
        ordersLoadError,
        isPlacingOrder,
        checkoutError,
        loadOrders,
        getOrderById,
        placeOrder,
        cancelOrder,

        reviews,
        isLoadingReviews,
        reviewsLoadError,
        loadReviews,
        submitReview,
        updateReview,
        deleteReview,

        submitReturnRequest
    };
}