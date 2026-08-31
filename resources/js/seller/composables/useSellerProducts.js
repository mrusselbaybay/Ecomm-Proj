// resources/js/seller/composables/useSellerProducts.js
//
// Backed by the Laravel Seller Product API (routes/seller.php +
// App\Http\Controllers\Seller\SellerProductController), not raw Supabase
// writes — category/status/price/stock/variant data all have to be
// validated and enforced server-side (see SellerProductService), which
// isn't something a client-side Supabase insert/update can safely do for
// combinatorial variant data (duplicate SKU/combination checks). This
// follows the exact same Bearer-token pattern useOrders.js already uses.
import { ref, computed, watch } from 'vue';
import { getSupabase } from './useSeller';
import { useSeller } from './useSeller';

const { sellerUser } = useSeller();

// ---- shared state across all inventory components ----
const products = ref([]);
const isLoadingProducts = ref(true);
const tableMissing = ref(false);
const loadError = ref('');

const isSaving = ref(false);
const saveError = ref('');

const searchQuery = ref('');
const selectedStockStatuses = ref([]); // [] = all -> 'in_stock' | 'low_stock' | 'out_of_stock'
const priceMin = ref(0);
const priceMax = ref(1500);

const currentPage = ref(1);
const perPage = 9; // 3-column grid x 3 rows, matches the reference's card grid

const selectedIds = ref(new Set());

const LOW_STOCK_THRESHOLD = 10; // qty at/under this (and above 0) counts as "Low Stock"
const PRODUCT_CACHE_TTL_MS = 60 * 1000;

// Module-level request/cache state. The refs above are already shared across
// inventory component mounts, so do not throw that useful data away and show
// a blocking spinner every time the seller returns to the page.
let productsRequest = null;
let productsRequestSellerId = null;
let loadedSellerId = null;
let productsLoadedAt = 0;

// ---- auth / fetch helpers (mirrors useOrders.js) ----

async function authHeaders() {
    const supabase = getSupabase();
    const {
        data: { session },
    } = await supabase.auth.getSession();
    const token = session?.access_token;

    if (!token) {
        throw new Error('Not signed in.');
    }

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
    };
}

async function apiFetch(path, options = {}) {
    const headers = await authHeaders();
    const response = await fetch(`/api/seller${path}`, { ...options, headers });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Request failed.');
    }

    return body.data;
}

// Reasons a seller can give for a manual stock adjustment — must match
// App\Services\InventoryService::MANUAL_REASONS.
const ADJUST_REASONS = [
    { value: 'restock', label: 'Restock' },
    { value: 'returned_item', label: 'Returned item' },
    { value: 'damaged', label: 'Damaged item' },
    { value: 'incorrect_count', label: 'Incorrect count' },
    { value: 'lost_item', label: 'Lost item' },
    { value: 'other', label: 'Other' },
];

// ---- derived ----

// A variant product's own "stock" isn't tracked directly — it's the sum
// of its ACTIVE variants' individual stock. Prefers the server's
// `effective_stock` (authoritative, mirrors InventoryService), then falls
// back to summing locally, then to product.stock for a simple product.
function effectiveStock(product) {
    if (typeof product.effective_stock === 'number') {
        return product.effective_stock;
    }

    if (product.has_variants && Array.isArray(product.variants) && product.variants.length) {
        return product.variants
            .filter((v) => (v.status ?? 'active') === 'active')
            .reduce((sum, v) => sum + Number(v.stock ?? 0), 0);
    }

    return Number(product.stock ?? 0);
}

function stockStatusOf(product) {
    // Server-computed status wins (it knows the per-product/variant
    // threshold); the local calc is only a fallback.
    if (product.stock_status) {
        return product.stock_status;
    }

    const qty = effectiveStock(product);
    const threshold = Number(product.effective_low_stock_threshold ?? LOW_STOCK_THRESHOLD);

    if (qty <= 0) {
        return 'out_of_stock';
    }

    if (qty <= threshold) {
        return 'low_stock';
    }

    return 'in_stock';
}

const filteredProducts = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();

    return products.value.filter((p) => {
        if (q) {
            const haystack =
                `${p.name || ''} ${p.sku || ''} ${p.model || ''}`.toLowerCase();

            if (!haystack.includes(q)) {
                return false;
            }
        }

        if (
            selectedStockStatuses.value.length &&
            !selectedStockStatuses.value.includes(stockStatusOf(p))
        ) {
            return false;
        }

        const price = Number(p.price ?? 0);

        if (price < priceMin.value || price > priceMax.value) {
            return false;
        }

        return true;
    });
});

const totalCount = computed(() => filteredProducts.value.length);
const totalPages = computed(() =>
    Math.max(1, Math.ceil(totalCount.value / perPage)),
);

const pagedProducts = computed(() => {
    const start = (currentPage.value - 1) * perPage;

    return filteredProducts.value.slice(start, start + perPage);
});

const paginationLabel = computed(() => {
    if (totalCount.value === 0) {
        return 'Showing 0 of 0 products';
    }

    const start = (currentPage.value - 1) * perPage + 1;
    const end = Math.min(currentPage.value * perPage, totalCount.value);

    return `Showing ${start}-${end} of ${totalCount.value} products`;
});

// ---- category config (specifications + variant option types) ----
// Single source of truth lives server-side (App\Support\CategoryFieldConfig)
// keyed by the seller's own line_of_business; fetched once and cached here
// so the product form can render itself without a second, hand-kept copy
// of the same rules in JS.
const categoryConfig = ref(null); // { category, specifications, variant_options }
const isLoadingCategoryConfig = ref(false);
const categoryConfigError = ref('');

async function loadCategoryConfig() {
    if (categoryConfig.value || isLoadingCategoryConfig.value) {
        return;
    }

    isLoadingCategoryConfig.value = true;
    categoryConfigError.value = '';

    try {
        categoryConfig.value = await apiFetch('/category-config');
    } catch (err) {
        console.error('Error loading category config:', err);
        categoryConfigError.value =
            err?.message || 'Could not load product fields for your category.';
    } finally {
        isLoadingCategoryConfig.value = false;
    }
}

// ---- data loading ----

async function loadProducts({ force = false } = {}) {
    const sellerId = sellerUser.value?.id;

    // useSeller() can still be resolving when Inventory mounts. The watcher
    // below calls this again as soon as the authenticated seller is ready.
    if (!sellerId) {
        return products.value;
    }

    // Never let two mounts/callers send the same products request at once.
    if (productsRequest && productsRequestSellerId === sellerId) {
        return productsRequest;
    }

    const sameSeller = loadedSellerId === sellerId;
    const hasCachedProducts = sameSeller && productsLoadedAt > 0;
    const cacheIsFresh =
        hasCachedProducts &&
        Date.now() - productsLoadedAt < PRODUCT_CACHE_TTL_MS;

    if (!force && cacheIsFresh) {
        isLoadingProducts.value = false;

        return products.value;
    }

    if (!sameSeller) {
        products.value = [];
        selectedIds.value = new Set();
        loadedSellerId = sellerId;
        productsLoadedAt = 0;
    }

    // First load blocks because there is nothing to show. Later refreshes keep
    // the existing cards visible while fresh data is requested in the
    // background, eliminating the repeated full-page loading state.
    const isFirstLoad = productsLoadedAt === 0;
    isLoadingProducts.value = isFirstLoad;
    loadError.value = '';
    tableMissing.value = false;
    productsRequestSellerId = sellerId;

    const request = apiFetch('/products')
        .then((data) => {
            // Ignore a response from an account that signed out/switched while
            // the request was running.
            if (sellerUser.value?.id !== sellerId) {
                return products.value;
            }

            products.value = Array.isArray(data) ? data : [];
            loadedSellerId = sellerId;
            productsLoadedAt = Date.now();

            return products.value;
        })
        .catch((err) => {
            console.error('Error loading seller products:', err);

            if (sellerUser.value?.id === sellerId) {
                // Keep previously loaded products on a background-refresh
                // failure. Only the true first load should result in an empty
                // inventory error state.
                if (isFirstLoad) {
                    loadError.value =
                        err?.message ||
                        'Something went wrong while loading your products.';
                    products.value = [];
                }
            }

            return products.value;
        })
        .finally(() => {
            if (productsRequest === request) {
                productsRequest = null;
                productsRequestSellerId = null;
            }

            if (sellerUser.value?.id === sellerId) {
                isLoadingProducts.value = false;
            }
        });

    productsRequest = request;

    return request;
}

// Reliably start the initial request even when sellerUser becomes available
// after Inventory's onMounted hook has already run.
watch(
    () => sellerUser.value?.id,
    (sellerId, previousSellerId) => {
        if (!sellerId) {
            if (previousSellerId) {
                products.value = [];
                selectedIds.value = new Set();
                loadedSellerId = null;
                productsLoadedAt = 0;
                isLoadingProducts.value = true;
            }

            return;
        }

        void loadProducts();
    },
    { immediate: true },
);

// ---- CRUD ----

/**
 * `payload` may include `options` (array of {name, values[]}) and
 * `variants` (array of {option_values: {Name: value}, sku, price,
 * stock, image, status}) for a product with variants. Category and
 * status are never read from `payload` here — the backend always
 * derives category from the seller's own line_of_business and forces
 * status to 'pending_review', regardless of anything sent.
 */
async function createProduct(payload) {
    isSaving.value = true;
    saveError.value = '';

    try {
        const data = await apiFetch('/products', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        products.value.unshift(data);
        productsLoadedAt = Date.now();

        return data;
    } catch (err) {
        console.error('Error creating product:', err);
        saveError.value = err?.message || 'Could not create the product.';

        throw err;
    } finally {
        isSaving.value = false;
    }
}

async function updateProduct(id, payload) {
    isSaving.value = true;
    saveError.value = '';

    try {
        const data = await apiFetch(`/products/${encodeURIComponent(id)}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
        });

        const idx = products.value.findIndex((p) => p.id === id);

        if (idx !== -1) {
            products.value[idx] = data;
        }

        productsLoadedAt = Date.now();

        return data;
    } catch (err) {
        console.error('Error updating product:', err);
        saveError.value = err?.message || 'Could not save changes.';

        throw err;
    } finally {
        isSaving.value = false;
    }
}

/**
 * Soft-archives the product instead of deleting the row, so the seller's
 * "Delete" action reuses the same 'archived' status the product-status
 * contract already defines for an admin-removed listing. The row (and
 * its order history) is preserved; the product just stops being
 * purchasable and is tagged "Archived" in the Inventory grid.
 */
async function deleteProduct(id) {
    try {
        const data = await apiFetch(`/products/${encodeURIComponent(id)}`, {
            method: 'DELETE',
        });

        const idx = products.value.findIndex((p) => p.id === id);

        if (idx !== -1) {
            products.value[idx] = data;
        }

        selectedIds.value.delete(id);
        productsLoadedAt = Date.now();
    } catch (err) {
        console.error('Error archiving product:', err);
        loadError.value = err?.message || 'Could not delete the product.';

        throw err;
    }
}

/**
 * Manual stock adjustment. `delta` is signed (+ to add, - to remove);
 * the server reads the real current stock itself and rejects anything
 * that would go negative. On success it patches the local product /
 * variant stock + stock_status from the response so the grid updates
 * without a full reload.
 */
async function adjustStock({ productId, variantId = null, delta, reason, note = null }) {
    const result = await apiFetch(`/products/${encodeURIComponent(productId)}/stock-adjustments`, {
        method: 'POST',
        body: JSON.stringify({ variant_id: variantId, delta, reason, note }),
    });

    const stock = result?.stock;
    const idx = products.value.findIndex((p) => p.id === productId);

    if (idx !== -1 && stock) {
        const product = { ...products.value[idx] };

        product.effective_stock = stock.productStock;
        product.stock_status = stock.productStockStatus;
        product.is_out_of_stock = stock.productIsOutOfStock;

        if (!product.has_variants) {
            product.stock = stock.productStock;
        } else if (stock.variantId && Array.isArray(product.variants)) {
            product.stock = stock.productStock;
            product.variants = product.variants.map((v) =>
                v.id === stock.variantId
                    ? {
                          ...v,
                          stock: stock.variantStock,
                          stock_status: stock.variantStockStatus,
                          is_out_of_stock: stock.variantIsOutOfStock,
                      }
                    : v,
            );
        }

        products.value[idx] = product;
        productsLoadedAt = Date.now();
    }

    return result;
}

// The product list is sent without full images (they're inline base64 and
// were bloating the response). The edit sheet needs every image, so it
// pulls the complete product here and merges it into the cached copy.
async function getProduct(id) {
    const data = await apiFetch(`/products/${encodeURIComponent(id)}`);
    const idx = products.value.findIndex((p) => p.id === id);

    if (idx !== -1) {
        products.value[idx] = data;
    }

    return data;
}

async function loadMovements(productId, { variantId = null, page = 1 } = {}) {
    const params = new URLSearchParams({ page: String(page) });

    if (variantId) {
        params.set('variant_id', variantId);
    }

    const headers = await authHeaders();
    const response = await fetch(
        `/api/seller/products/${encodeURIComponent(productId)}/stock-movements?${params}`,
        { headers },
    );
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Could not load stock history.');
    }

    return { data: body.data ?? [], meta: body.meta ?? {} };
}

async function deleteSelected() {
    const ids = Array.from(selectedIds.value);

    if (!ids.length) {
        return;
    }

    try {
        // No bulk-archive endpoint — archive each selected product
        // individually through the same validated endpoint deleteProduct()
        // uses, rather than adding a second, less-checked code path.
        const results = await Promise.all(
            ids.map((id) =>
                apiFetch(`/products/${encodeURIComponent(id)}`, { method: 'DELETE' }),
            ),
        );

        const archivedById = new Map(results.map((p) => [p.id, p]));

        products.value = products.value.map((p) =>
            archivedById.has(p.id) ? archivedById.get(p.id) : p,
        );
        selectedIds.value.clear();
        productsLoadedAt = Date.now();
    } catch (err) {
        console.error('Error bulk-archiving products:', err);
        loadError.value =
            err?.message || 'Could not delete the selected products.';

        throw err;
    }
}

// ---- selection helpers ----

function toggleSelected(id) {
    if (selectedIds.value.has(id)) {
        selectedIds.value.delete(id);
    } else {
        selectedIds.value.add(id);
    }

    // force reactivity for Set mutation
    selectedIds.value = new Set(selectedIds.value);
}

function clearSelection() {
    selectedIds.value = new Set();
}

// ---- formatting helpers (mirrors useSeller.js conventions) ----

function formatPrice(value) {
    const n = Number(value ?? 0);

    return `₱${n.toFixed(2)}`;
}

// Approval status (product.status) always takes priority over stock level:
// a product awaiting or removed from admin review isn't purchasable yet,
// so its tag should say so regardless of how much stock it has. Only once
// a product is 'active' does the tag fall back to reflecting stock level
// (Out of Stock / Low Stock / Active), same as before.
function statusBadgeClass(product) {
    if (product.status === 'pending_review') {
        return 'badge-sky';
    }

    if (product.status === 'archived') {
        return 'badge-slate';
    }

    const s = stockStatusOf(product);

    if (s === 'out_of_stock') {
        return 'badge-red';
    }

    if (s === 'low_stock') {
        return 'badge-amber';
    }

    return 'badge-emerald';
}

function statusLabel(product) {
    if (product.status === 'pending_review') {
        return 'Pending Review';
    }

    if (product.status === 'archived') {
        return 'Archived';
    }

    const s = stockStatusOf(product);

    if (s === 'out_of_stock') {
        return 'Out of Stock';
    }

    if (s === 'low_stock') {
        return 'Low Stock';
    }

    return 'Active';
}

function stockBarClass(product) {
    const s = stockStatusOf(product);

    if (s === 'out_of_stock') {
        return 'red';
    }

    if (s === 'low_stock') {
        return 'amber';
    }

    return 'emerald';
}

function stockBarWidth(product, maxStock = 200) {
    const qty = effectiveStock(product);

    return `${Math.max(4, Math.min(100, Math.round((qty / maxStock) * 100)))}%`;
}

export function useSellerProducts() {
    return {
        products,
        isLoadingProducts,
        tableMissing,
        loadError,
        isSaving,
        saveError,

        searchQuery,
        selectedStockStatuses,
        priceMin,
        priceMax,

        currentPage,
        perPage,
        totalPages,
        totalCount,
        paginationLabel,
        pagedProducts,
        filteredProducts,

        selectedIds,
        toggleSelected,
        clearSelection,

        loadProducts,
        getProduct,
        createProduct,
        updateProduct,
        deleteProduct,
        deleteSelected,
        adjustStock,
        loadMovements,
        ADJUST_REASONS,

        categoryConfig,
        isLoadingCategoryConfig,
        categoryConfigError,
        loadCategoryConfig,

        effectiveStock,
        stockStatusOf,
        formatPrice,
        statusBadgeClass,
        statusLabel,
        stockBarClass,
        stockBarWidth,
    };
}
