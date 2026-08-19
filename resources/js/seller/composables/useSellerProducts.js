// resources/js/seller/composables/useSellerProducts.js
//
// NOTE ON SCHEMA: as of this writing there is no `products` table in the
// project's Supabase schema (see the schema dump this composable was built
// against). Everything below is written to work the moment such a table
// exists, using the shape described in the accompanying
// `products_table_reference.sql`. Until then, every query below fails with
// Postgres error 42P01 ("relation does not exist"), which is caught and
// surfaced as `tableMissing = true` rather than thrown — so the Inventory
// page renders a clear "not set up yet" empty state instead of crashing.
import { ref, computed } from 'vue';
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
const selectedCategories = ref([]); // [] = all
const selectedStockStatuses = ref([]); // [] = all -> 'in_stock' | 'low_stock' | 'out_of_stock'
const priceMin = ref(0);
const priceMax = ref(1500);

const currentPage = ref(1);
const perPage = 9; // 3-column grid x 3 rows, matches the reference's card grid

const selectedIds = ref(new Set());

const LOW_STOCK_THRESHOLD = 10; // qty at/under this (and above 0) counts as "Low Stock"

// ---- derived ----

function stockStatusOf(product) {
  const qty = Number(product.stock ?? 0);
  if (qty <= 0) return 'out_of_stock';
  if (qty <= LOW_STOCK_THRESHOLD) return 'low_stock';
  return 'in_stock';
}

const categories = computed(() => {
  const counts = new Map();
  for (const p of products.value) {
    const cat = p.category || 'Uncategorized';
    counts.set(cat, (counts.get(cat) || 0) + 1);
  }
  return Array.from(counts.entries()).map(([name, count]) => ({ name, count }));
});

const filteredProducts = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  return products.value.filter((p) => {
    if (q) {
      const haystack = `${p.name || ''} ${p.sku || ''} ${p.model || ''}`.toLowerCase();
      if (!haystack.includes(q)) return false;
    }
    if (selectedCategories.value.length && !selectedCategories.value.includes(p.category || 'Uncategorized')) {
      return false;
    }
    if (selectedStockStatuses.value.length && !selectedStockStatuses.value.includes(stockStatusOf(p))) {
      return false;
    }
    const price = Number(p.price ?? 0);
    if (price < priceMin.value || price > priceMax.value) return false;
    return true;
  });
});

const totalCount = computed(() => filteredProducts.value.length);
const totalPages = computed(() => Math.max(1, Math.ceil(totalCount.value / perPage)));

const pagedProducts = computed(() => {
  const start = (currentPage.value - 1) * perPage;
  return filteredProducts.value.slice(start, start + perPage);
});

const paginationLabel = computed(() => {
  if (totalCount.value === 0) return 'Showing 0 of 0 products';
  const start = (currentPage.value - 1) * perPage + 1;
  const end = Math.min(currentPage.value * perPage, totalCount.value);
  return `Showing ${start}-${end} of ${totalCount.value} products`;
});

// ---- data loading ----

async function loadProducts() {
  if (!sellerUser.value) return;
  isLoadingProducts.value = true;
  loadError.value = '';
  tableMissing.value = false;
  try {
    const supabase = getSupabase();
    const { data, error } = await supabase
      .from('products')
      .select('*')
      .eq('seller_id', sellerUser.value.id)
      .order('created_at', { ascending: false });

    if (error) {
      // 42P01 = undefined_table. Treat as "not set up yet", not a hard failure.
      if (error.code === '42P01') {
        tableMissing.value = true;
        products.value = [];
      } else {
        throw error;
      }
    } else {
      products.value = data || [];
    }
  } catch (err) {
    console.error('Error loading seller products:', err);
    loadError.value = err?.message || 'Something went wrong while loading your products.';
    products.value = [];
  } finally {
    isLoadingProducts.value = false;
  }
}

// ---- CRUD ----

async function createProduct(payload) {
  isSaving.value = true;
  saveError.value = '';
  try {
    const supabase = getSupabase();
    const { data, error } = await supabase
      .from('products')
      .insert({
        seller_id: sellerUser.value.id,
        name: payload.name,
        description: payload.description,
        category: payload.category,
        sku: payload.sku,
        price: payload.price,
        compare_price: payload.compare_price || null,
        promo_code: payload.promo_code || null,
        stock: payload.stock,
        images: payload.images || [],
        status: payload.status || 'active',
      })
      .select()
      .single();
    if (error) throw error;
    products.value.unshift(data);
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
    const supabase = getSupabase();
    const { data, error } = await supabase
      .from('products')
      .update({
        name: payload.name,
        description: payload.description,
        category: payload.category,
        price: payload.price,
        compare_price: payload.compare_price || null,
        promo_code: payload.promo_code || null,
        stock: payload.stock,
        images: payload.images,
        updated_at: new Date().toISOString(),
      })
      .eq('id', id)
      .eq('seller_id', sellerUser.value.id)
      .select()
      .single();
    if (error) throw error;
    const idx = products.value.findIndex((p) => p.id === id);
    if (idx !== -1) products.value[idx] = data;
    return data;
  } catch (err) {
    console.error('Error updating product:', err);
    saveError.value = err?.message || 'Could not save changes.';
    throw err;
  } finally {
    isSaving.value = false;
  }
}

async function deleteProduct(id) {
  try {
    const supabase = getSupabase();
    const { error } = await supabase
      .from('products')
      .delete()
      .eq('id', id)
      .eq('seller_id', sellerUser.value.id);
    if (error) throw error;
    products.value = products.value.filter((p) => p.id !== id);
    selectedIds.value.delete(id);
  } catch (err) {
    console.error('Error deleting product:', err);
    loadError.value = err?.message || 'Could not delete the product.';
    throw err;
  }
}

async function deleteSelected() {
  const ids = Array.from(selectedIds.value);
  if (!ids.length) return;
  try {
    const supabase = getSupabase();
    const { error } = await supabase
      .from('products')
      .delete()
      .in('id', ids)
      .eq('seller_id', sellerUser.value.id);
    if (error) throw error;
    products.value = products.value.filter((p) => !ids.includes(p.id));
    selectedIds.value.clear();
  } catch (err) {
    console.error('Error bulk-deleting products:', err);
    loadError.value = err?.message || 'Could not delete the selected products.';
    throw err;
  }
}

async function moveSelectedToCategory(category) {
  const ids = Array.from(selectedIds.value);
  if (!ids.length || !category) return;
  try {
    const supabase = getSupabase();
    const { error } = await supabase
      .from('products')
      .update({ category, updated_at: new Date().toISOString() })
      .in('id', ids)
      .eq('seller_id', sellerUser.value.id);
    if (error) throw error;
    products.value = products.value.map((p) => (ids.includes(p.id) ? { ...p, category } : p));
    selectedIds.value.clear();
  } catch (err) {
    console.error('Error moving products to category:', err);
    loadError.value = err?.message || 'Could not move the selected products.';
    throw err;
  }
}

// ---- selection helpers ----

function toggleSelected(id) {
  if (selectedIds.value.has(id)) selectedIds.value.delete(id);
  else selectedIds.value.add(id);
  // force reactivity for Set mutation
  selectedIds.value = new Set(selectedIds.value);
}

function clearSelection() {
  selectedIds.value = new Set();
}

// ---- formatting helpers (mirrors useSeller.js conventions) ----

function formatPrice(value) {
  const n = Number(value ?? 0);
  return `$${n.toFixed(2)}`;
}

function statusBadgeClass(product) {
  if (product.status === 'inactive') return 'badge-slate';
  const s = stockStatusOf(product);
  if (s === 'out_of_stock') return 'badge-red';
  if (s === 'low_stock') return 'badge-amber';
  return 'badge-emerald';
}

function statusLabel(product) {
  if (product.status === 'inactive') return 'Inactive';
  const s = stockStatusOf(product);
  if (s === 'out_of_stock') return 'Out of Stock';
  if (s === 'low_stock') return 'Low Stock';
  return 'Active';
}

function stockBarClass(product) {
  const s = stockStatusOf(product);
  if (s === 'out_of_stock') return 'red';
  if (s === 'low_stock') return 'amber';
  return 'emerald';
}

function stockBarWidth(product, maxStock = 200) {
  const qty = Number(product.stock ?? 0);
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
    selectedCategories,
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
    categories,

    selectedIds,
    toggleSelected,
    clearSelection,

    loadProducts,
    createProduct,
    updateProduct,
    deleteProduct,
    deleteSelected,
    moveSelectedToCategory,

    stockStatusOf,
    formatPrice,
    statusBadgeClass,
    statusLabel,
    stockBarClass,
    stockBarWidth,
  };
}