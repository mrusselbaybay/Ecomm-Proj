// resources/js/buyer/composables/useBuyerProducts.js
//
// Backed by the public Laravel product catalog (App\Http\Controllers\
// ProductController + GET /api/products), not Supabase directly — see
// that controller's docblock for why. No auth required: browsing is
// public, same as the previous hardcoded-array behavior in Dashboard.vue.
import { ref } from 'vue';

const products = ref([]);
const isLoadingProducts = ref(true);
const loadError = ref('');

// Shared across every caller of this composable (module scope), so two
// components mounting at once — or a component re-triggering a load — reuse
// the same request instead of firing duplicates at /api/products.
let inFlight = null;

async function fetchProducts(params) {
    isLoadingProducts.value = true;
    loadError.value = '';

    try {
        const query = new URLSearchParams(
            Object.fromEntries(
                Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''),
            ),
        ).toString();

        const response = await fetch(`/api/products${query ? `?${query}` : ''}`, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(body.message || 'Could not load products.');
        }

        products.value = body.data || [];
    } catch (err) {
        console.error('Error loading products:', err);
        loadError.value = err?.message || 'Something went wrong while loading products.';
        products.value = [];
    } finally {
        isLoadingProducts.value = false;
    }
}

async function loadProducts(params = {}) {
    if (!inFlight) {
        inFlight = fetchProducts(params).finally(() => {
            inFlight = null;
        });
    }

    return inFlight;
}

async function getProductById(id) {
    // Prefer whatever's already in memory to avoid a network round-trip
    // when navigating from the grid into a product's detail page.
    const cached = products.value.find((p) => p.id === id);

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(`/api/products/${encodeURIComponent(id)}`, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            return null;
        }

        return body.data || null;
    } catch (err) {
        console.error('Error loading product:', err);

        return null;
    }
}

export function useBuyerProducts() {
    return {
        products,
        isLoadingProducts,
        loadError,
        loadProducts,
        getProductById,
    };
}