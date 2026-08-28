import { ref, computed } from 'vue';
import { authHeaders } from './useBuyerSession';

const cart = ref([]);

// Persisted client-side only — there's no buyer-scoped favorites table/
// endpoint yet, so this can't follow a buyer across devices or survive
// clearing site data, but at least it survives a normal page refresh
// (previously this was in-memory only and reset on every reload, which
// is a bad experience for a feature specifically about "save this for
// later"). Real per-buyer server-side favorites is a reasonable next step.
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

function isFavorite(productId) {
    return favorites.value.includes(productId);
}

function toggleFavorite(productId) {
    if (isFavorite(productId)) {
        favorites.value = favorites.value.filter(id => id !== productId);
    } else {
        favorites.value.push(productId);
    }

    persistFavorites();
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

async function apiFetch(path, options = {}) {
    const headers = await authHeaders();
    const response = await fetch(`/api${path}`, { ...options, headers });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Request failed.');
    }

    return body.data;
}

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
 * There is intentionally no buyer-facing "cancel" endpoint yet: order
 * status changes are a privileged, auditable action handled by
 * Seller\SellerOrderController (see routes/seller.php), scoped to the
 * seller who owns the order. A buyer-initiated cancellation would need
 * its own endpoint + authorization rule (e.g. only while status is
 * "New") rather than reusing the seller route. Surfaced clearly here so
 * OrderDetails.vue's "Cancel Order" button fails loudly instead of
 * silently pretending to work.
 */
async function cancelOrder() {
    checkoutError.value = 'Cancelling isn\u2019t available yet \u2014 please contact the seller.';

    return null;
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
| Returns
|--------------------------------------------------------------------------
|
| NOT backed by a real subsystem yet — there is no return_requests table
| in the current schema (unlike reviews, which turned out to already have
| one), so this intentionally does not persist anything. It exists only
| so OrderDetails.vue's return-request modal (built before this backend
| pass) doesn't throw when opened. Building the real thing needs a new
| table + endpoint.
|
*/

function submitReturnRequest(orderId, itemIndex, request) {
    console.warn(
        'submitReturnRequest() is a stub — there is no returns table/endpoint yet. Not persisted:',
        { orderId, itemIndex, request },
    );

    return { ...request, orderId, itemIndex, submittedAt: new Date().toISOString() };
}

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

export function useBuyer() {
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