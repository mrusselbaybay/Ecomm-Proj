import { ref, computed, watch } from 'vue';
import { buyerApi } from './useBuyerApi';
import { getSupabase } from './useBuyerSession';
import { useToasts } from './useToasts';

const { success: toastSuccess, error: toastError, warning: toastWarning, info: toastInfo } = useToasts();

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

// Line-item statuses set by validateCartAgainstCatalog(). Everything in
// BLOCKING_STATUSES stops that line from being checked out; 'price_changed'
// is a heads-up only (CheckoutService recalculates from the DB anyway).
const BLOCKING_STATUSES = ['unavailable', 'out_of_stock', 'variant_unavailable', 'insufficient_stock'];

function isBlocked(item) {
    return BLOCKING_STATUSES.includes(item.status);
}

// What the buyer will actually be charged for this line: the re-checked
// server price once we know it differs, otherwise the stored snapshot.
function effectivePrice(item) {
    return item.status === 'price_changed' && item.serverPrice != null
        ? Number(item.serverPrice)
        : Number(item.price);
}

// Live purchasable stock for a product/variant pair at add-to-cart time.
// null => unknown (simple product whose API row had no numeric stock);
// treated as "no client-side ceiling", still enforced server-side.
function stockCeiling(product, variant) {
    if (variant) {
        return Number.isFinite(Number(variant.stock)) ? Number(variant.stock) : 0;
    }

    return typeof product.stock === 'number' ? product.stock : null;
}

function snapshotFrom(item, product, variant) {
    item.name = product.name ?? item.name;
    item.price = Number(variant?.price ?? product.price ?? item.price);
    item.category = product.category ?? item.category;
    item.seller = product.seller || item.seller || 'NEXMART Seller';
    item.image = item.image
        || variant?.image?.url
        || (Array.isArray(product.images) ? product.images[0] || null : null);
    item.oldPrice = product.oldPrice ?? null;
    item.rating = typeof product.rating === 'number' ? product.rating : (item.rating ?? null);
    item.reviewCount = Number(product.reviewCount) || 0;
    item.maxStock = stockCeiling(product, variant);
}

/**
 * `variant` is either null/undefined (simple product — no options) or a
 * variant object as returned by ProductController@transform / the
 * selectedVariant computed in ProductDetails.vue: { id, sku, price,
 * image, option_values, stock, status }. Its id/sku/price and a
 * human-readable label built from option_values are carried through the
 * cart into the checkout payload; everything is re-validated against the
 * real row server-side in CheckoutService regardless.
 *
 * Owns its own success/limit toast (every add-to-cart entry point calls
 * through here, so putting the messaging here avoids each caller
 * re-implementing it). Returns { ok, capped } so a caller can still do
 * extra UI if it wants.
 */
function addToCart(product, variant, quantity) {
    if (!product) {
        return { ok: false };
    }

    if (variant && variant.status && variant.status !== 'active') {
        toastError('This variant is currently unavailable.');

        return { ok: false };
    }

    const ceiling = stockCeiling(product, variant);

    if (ceiling === 0) {
        toastError('This item is out of stock.');

        return { ok: false };
    }

    const desired = Math.max(1, Math.floor(Number(quantity)) || 1);
    const variantId = variant?.id || null;
    const existingItem = cart.value.find((item) =>
        item.productId === product.id && item.variantId === variantId,
    );

    if (existingItem) {
        const requested = existingItem.quantity + desired;

        snapshotFrom(existingItem, product, variant);

        if (ceiling != null && requested > ceiling) {
            if (existingItem.quantity >= ceiling) {
                toastInfo(`You already have the maximum available (${ceiling}) in your cart.`);

                return { ok: false, capped: true };
            }

            existingItem.quantity = ceiling;

            if (existingItem.status === 'insufficient_stock') {
                existingItem.status = 'ok';
            }

            toastWarning(`Only ${ceiling} available — added the maximum to your cart.`);

            return { ok: true, capped: true };
        }

        existingItem.quantity = requested;

        if (existingItem.status === 'insufficient_stock' && (ceiling == null || requested <= ceiling)) {
            existingItem.status = 'ok';
        }

        toastSuccess('Added to cart.');

        return { ok: true };
    }

    const quantityToAdd = ceiling != null ? Math.min(desired, ceiling) : desired;
    const capped = quantityToAdd < desired;

    const variantLabel = variant?.option_values
        ? Object.entries(variant.option_values).map(([k, v]) => `${k}: ${v}`).join(', ')
        : null;

    const item = {
        cartId: Date.now() + Math.random(),
        productId: product.id,
        variantId,
        sku: variant?.sku || product.sku || null,
        name: product.name,
        price: Number(variant?.price ?? product.price),
        category: product.category,
        variation: variantLabel,
        quantity: quantityToAdd,
        seller: product.seller || 'NEXMART Seller',
        image: variant?.image?.url
            || (Array.isArray(product.images) ? product.images[0] || null : null),
        oldPrice: product.oldPrice ?? null,
        rating: typeof product.rating === 'number' ? product.rating : null,
        reviewCount: Number(product.reviewCount) || 0,
        maxStock: ceiling,
        serverPrice: null,
        status: 'ok',
        selected: true,
    };

    cart.value.push(item);

    if (capped) {
        toastWarning(`Only ${ceiling} available — added the maximum to your cart.`);
    } else {
        toastSuccess('Added to cart.');
    }

    return { ok: true, capped };
}

// Silent by design: also called by Dashboard.handleOrderPlaced() to drop
// the lines that were just purchased. The Cart page shows its own
// "Item removed" toast after its confirm dialog.
function removeFromCart(cartId) {
    cart.value = cart.value.filter((item) => item.cartId !== cartId);
}

function removeCartItems(cartIds) {
    const ids = new Set(cartIds);
    cart.value = cart.value.filter((item) => !ids.has(item.cartId));
}

function removeUnavailableItems() {
    const removed = cart.value.filter((item) => isBlocked(item)).length;
    cart.value = cart.value.filter((item) => !isBlocked(item));

    return removed;
}

function clearCart() {
    cart.value = [];
}

/**
 * Single quantity setter the +/- buttons and the typed input all go
 * through. Clamps to [1, maxStock], clears a now-satisfied
 * insufficient_stock flag, and warns (once, de-duped) when the buyer hits
 * the ceiling. Returns the value actually applied.
 */
function setCartQuantity(cartId, quantity) {
    const item = cart.value.find((entry) => entry.cartId === cartId);

    if (!item) {
        return { quantity: 0, capped: false };
    }

    let next = Math.floor(Number(quantity));

    if (!Number.isFinite(next)) {
        return { quantity: item.quantity, capped: false };
    }

    if (next < 1) {
        next = 1;
    }

    let capped = false;

    if (item.maxStock != null && next > item.maxStock) {
        next = item.maxStock;
        capped = true;
    }

    item.quantity = next;

    if (item.status === 'insufficient_stock' && item.maxStock != null && next <= item.maxStock) {
        item.status = 'ok';
    }

    if (capped) {
        toastWarning(`Only ${item.maxStock} available.`);
    }

    return { quantity: next, capped };
}

function increaseCartQuantity(cartId) {
    const item = cart.value.find((entry) => entry.cartId === cartId);

    if (item) {
        setCartQuantity(cartId, item.quantity + 1);
    }
}

function decreaseCartQuantity(cartId) {
    const item = cart.value.find((entry) => entry.cartId === cartId);

    if (item && item.quantity > 1) {
        setCartQuantity(cartId, item.quantity - 1);
    }
}

function toggleCartItem(cartId) {
    const item = cart.value.find((entry) => entry.cartId === cartId);

    if (item) {
        item.selected = !item.selected;
    }
}

function toggleSellerItems(seller, selected) {
    cart.value
        .filter((item) => item.seller === seller)
        .forEach((item) => {
            item.selected = selected;
        });
}

function deselectBlockedItems() {
    cart.value.forEach((item) => {
        if (isBlocked(item)) {
            item.selected = false;
        }
    });
}

/*
|--------------------------------------------------------------------------
| Cart validation against the live catalog
|--------------------------------------------------------------------------
|
| The cart is a localStorage snapshot (see the top of this file), so its
| name/price/stock/availability can all have gone stale since it was
| built. This re-fetches each distinct product from the public catalog
| (GET /api/products/{id}) and tags every line with a status the Cart page
| surfaces inline — WITHOUT ever removing anything automatically. Called
| when the Cart page mounts.
|
*/

const isValidatingCart = ref(false);
const cartValidatedAt = ref(0);

async function validateCartAgainstCatalog() {
    if (cart.value.length === 0) {
        cartValidatedAt.value = Date.now();

        return;
    }

    isValidatingCart.value = true;

    try {
        const ids = [...new Set(cart.value.map((item) => item.productId))];

        const entries = await Promise.all(
            ids.map(async (id) => {
                try {
                    const response = await fetch(`/api/products/${encodeURIComponent(id)}`, {
                        headers: { Accept: 'application/json' },
                    });

                    if (response.status === 404) {
                        return [id, null];
                    }

                    if (!response.ok) {
                        return [id, undefined];
                    }

                    const body = await response.json().catch(() => ({}));

                    return [id, body.data || undefined];
                } catch {
                    return [id, undefined];
                }
            }),
        );

        const map = new Map(entries);

        cart.value.forEach((item) => {
            const product = map.get(item.productId);

            if (product === undefined) {
                return; // couldn't check — leave the line exactly as it was
            }

            if (product === null || (product.status && product.status !== 'active')) {
                item.status = 'unavailable';
                item.maxStock = 0;

                return;
            }

            item.name = product.name ?? item.name;
            item.seller = product.seller || item.seller;
            item.rating = typeof product.rating === 'number' ? product.rating : item.rating;
            item.reviewCount = Number(product.reviewCount) || 0;
            item.oldPrice = product.oldPrice ?? null;

            if (item.variantId) {
                const variant = (product.variants || []).find((v) => v.id === item.variantId);

                if (!variant || variant.status !== 'active') {
                    item.status = 'variant_unavailable';
                    item.maxStock = 0;

                    return;
                }

                item.maxStock = Number(variant.stock) || 0;
                item.serverPrice = Number(variant.price ?? product.price);
                item.image = item.image || variant.image?.url || null;
            } else {
                item.maxStock = typeof product.stock === 'number' ? product.stock : null;
                item.serverPrice = Number(product.price);
                item.image = item.image
                    || (Array.isArray(product.images) ? product.images[0] || null : product.image || null);
            }

            if (item.maxStock === 0) {
                item.status = 'out_of_stock';
            } else if (item.maxStock != null && item.quantity > item.maxStock) {
                item.status = 'insufficient_stock';
            } else if (item.serverPrice != null && Math.abs(item.serverPrice - Number(item.price)) > 0.009) {
                item.status = 'price_changed';
            } else {
                item.status = 'ok';
            }
        });
    } finally {
        isValidatingCart.value = false;
        cartValidatedAt.value = Date.now();
    }
}

/*
|--------------------------------------------------------------------------
| Cart Computed Values
|--------------------------------------------------------------------------
*/

const selectedItems = computed(() => {
    return cart.value.filter((item) => item.selected);
});

// Selected lines that are actually purchasable — the basis for the
// summary totals and the checkout payload.
const selectedValidItems = computed(() => {
    return selectedItems.value.filter((item) => !isBlocked(item));
});

const selectedBlockedItems = computed(() => {
    return selectedItems.value.filter((item) => isBlocked(item));
});

const selectedItemCount = computed(() => {
    return selectedValidItems.value.reduce((total, item) => total + item.quantity, 0);
});

const cartSubtotal = computed(() => {
    return selectedValidItems.value.reduce(
        (total, item) => total + effectivePrice(item) * item.quantity,
        0,
    );
});

const cartItemCount = computed(() => {
    return cart.value.reduce((total, item) => total + item.quantity, 0);
});

const sellers = computed(() => {
    return [...new Set(cart.value.map((item) => item.seller))];
});

const isCartEmpty = computed(() => {
    return cart.value.length === 0;
});

const cartHasIssues = computed(() => {
    return cart.value.some((item) => item.status && item.status !== 'ok');
});

// '' when checkout is allowed; otherwise a plain-language reason the Cart
// page shows next to the (disabled) checkout button.
const checkoutBlockReason = computed(() => {
    if (selectedItems.value.length === 0) {
        return 'Select at least one item to check out.';
    }

    if (selectedValidItems.value.length === 0) {
        return 'The items you selected are unavailable. Adjust your selection to continue.';
    }

    if (selectedBlockedItems.value.length > 0) {
        const count = selectedBlockedItems.value.length;

        return `${count} selected ${count === 1 ? 'item needs' : 'items need'} attention before you can check out.`;
    }

    return '';
});

/*
|--------------------------------------------------------------------------
| Select All
|--------------------------------------------------------------------------
*/

const allItemsSelected = computed(() => {
    return cart.value.length > 0 && cart.value.every((item) => item.selected);
});

function toggleSelectAll(selected) {
    cart.value.forEach((item) => {
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
        selectedValidItems,
        selectedBlockedItems,
        selectedItemCount,
        cartSubtotal,
        cartItemCount,
        isCartEmpty,
        allItemsSelected,
        cartHasIssues,
        checkoutBlockReason,
        favoriteCount,

        effectivePrice,

        addToCart,
        removeFromCart,
        removeCartItems,
        removeUnavailableItems,
        clearCart,
        setCartQuantity,
        increaseCartQuantity,
        decreaseCartQuantity,
        toggleCartItem,
        toggleSellerItems,
        toggleSelectAll,
        deselectBlockedItems,

        isValidatingCart,
        cartValidatedAt,
        validateCartAgainstCatalog,

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