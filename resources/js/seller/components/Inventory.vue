<!-- resources/js/seller/components/Inventory.vue -->
<template>
  <div class="inventory-page">
    <!-- ============================================================
         TOOLBAR
         Confirmed via SellerLayout.vue: it already renders the shared
         content-header (title/breadcrumb/notifications/profile) around
         every section, so Inventory.vue doesn't render its own. Its
         header search box is generic/unwired though (no v-model), so
         the working "Search SKU, model..." field lives here instead.
         ============================================================ -->
    <div class="card inventory-toolbar">
      <div class="toolbar-left">
        <button
          class="notif-btn"
          :class="{ active: bulkSelectMode }"
          title="Bulk select"
          @click="toggleBulkSelectMode"
        >
          <svg width="19" height="19" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="14" height="14" rx="3"/><path v-if="bulkSelectMode" d="M6.5 10l2.5 2.5L14 7.5"/></svg>
        </button>
        <span class="toolbar-label">Inventory Actions</span>
        <div class="toolbar-buttons">
          <button class="chip-btn danger" :disabled="!selectedIds.size" @click="showDeleteModal = true; deleteTarget = 'bulk'">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h12M8 6V4h4v2M6 6l.7 9a1 1 0 0 0 1 1h4.6a1 1 0 0 0 1-1L14 6"/></svg>
            Delete Selected
          </button>
          <button class="chip-btn" :disabled="!selectedIds.size" @click="openMoveModal">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6a1 1 0 0 1 1-1h4l1.5 2H16a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6Z"/></svg>
            Move Category
          </button>
        </div>
      </div>
      <div class="toolbar-right">
        <div class="header-search inventory-search">
          <span class="search-icon">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="9" r="6.5"/><path d="M18 18l-3.8-3.8"/></svg>
          </span>
          <input type="text" v-model="searchQuery" placeholder="Search SKU, model..." />
        </div>
        <button class="btn-primary" @click="openNewProductSheet">
          <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="8"/><path d="M10 6v8M6 10h8"/></svg>
          Add New Product
        </button>
      </div>
    </div>

    <!-- ============================================================
         FILTERS + GRID
         ============================================================ -->
    <div class="inventory-layout">
      <aside class="inventory-filters">
        <div class="filter-group">
          <h4 class="filter-heading">Product Categories</h4>
          <div v-if="categories.length === 0" class="filter-empty">No categories yet.</div>
          <label v-for="cat in categories" :key="cat.name" class="filter-category" :class="{ active: selectedCategories.includes(cat.name) }">
            <input type="checkbox" :value="cat.name" v-model="selectedCategories" />
            <span>{{ cat.name }}</span>
            <span class="filter-count">{{ cat.count }}</span>
          </label>
        </div>

        <div class="filter-group">
          <h4 class="filter-heading">Stock Status</h4>
          <label v-for="opt in stockStatusOptions" :key="opt.value" class="filter-check">
            <span class="check-box" :class="{ checked: selectedStockStatuses.includes(opt.value) }">
              <svg width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3"><path d="M4 10l4 4 8-8"/></svg>
            </span>
            <input type="checkbox" :value="opt.value" v-model="selectedStockStatuses" style="display:none;" />
            {{ opt.label }}
          </label>
        </div>

        <div class="filter-group">
          <h4 class="filter-heading">Price Range</h4>
          <div class="price-range">
            <div class="price-track">
              <div
                class="price-track-fill"
                :style="{ left: priceMinPct + '%', right: (100 - priceMaxPct) + '%' }"
              ></div>
            </div>
            <input type="range" class="price-slider" min="0" max="1500" step="10" v-model.number="priceMin" @input="clampMin" />
            <input type="range" class="price-slider" min="0" max="1500" step="10" v-model.number="priceMax" @input="clampMax" />
            <div class="price-labels">
              <span>${{ priceMin }}</span>
              <span>${{ priceMax }}</span>
            </div>
          </div>
        </div>
      </aside>

      <div class="inventory-grid-wrap">
        <!-- Loading -->
        <div v-if="isLoadingProducts" class="empty-state" style="padding:4rem 1rem;">
          <div class="loading-spinner"></div>
          <p style="margin-top:1rem;">Loading products…</p>
        </div>

        <!-- Products table not set up yet -->
        <div v-else-if="tableMissing" class="card empty-state" style="padding:3rem 1.5rem;">
          <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>
          <p style="font-weight:700;color:#1e293b;">Inventory isn't set up yet</p>
          <p class="empty-hint">This page is wired to a <code>products</code> table that doesn't exist in the database yet. Once it's created, this screen will load and manage real listings automatically — no code changes needed.</p>
        </div>

        <!-- Real error -->
        <div v-else-if="loadError" class="card empty-state" style="padding:3rem 1.5rem;">
          <p style="font-weight:700;color:#b91c1c;">Couldn't load your products</p>
          <p class="empty-hint">{{ loadError }}</p>
          <button class="btn-outline" style="margin-top:1rem;" @click="loadProducts">Try again</button>
        </div>

        <!-- No products at all -->
        <div v-else-if="products.length === 0" class="card empty-state" style="padding:3rem 1.5rem;">
          <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>
          <p style="font-weight:700;color:#1e293b;">No products yet</p>
          <p class="empty-hint">Add your first listing to start building your catalog.</p>
          <button class="btn-primary" style="margin-top:1rem;" @click="openNewProductSheet">Add New Product</button>
        </div>

        <!-- No results for current filters -->
        <div v-else-if="filteredProducts.length === 0" class="card empty-state" style="padding:3rem 1.5rem;">
          <p style="font-weight:700;color:#1e293b;">No products match these filters</p>
          <p class="empty-hint">Try clearing your search or filters.</p>
          <button class="btn-outline" style="margin-top:1rem;" @click="clearFilters">Clear filters</button>
        </div>

        <!-- Grid -->
        <template v-else>
          <div class="product-grid">
            <div v-for="product in pagedProducts" :key="product.id" class="product-card">
              <div class="product-card-image">
                <img v-if="product.images?.[0]?.url" :src="product.images[0].url" :alt="product.name" />
                <div v-else class="product-card-image-placeholder">
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                </div>
                <span class="product-badge" :class="statusBadgeClass(product)">{{ statusLabel(product) }}</span>

                <label v-if="bulkSelectMode" class="product-select-box">
                  <input type="checkbox" :checked="selectedIds.has(product.id)" @change="toggleSelected(product.id)" />
                </label>

                <div class="product-hover-actions">
                  <button title="Edit" @click="openEditProductSheet(product)">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13.5 3.5a1.5 1.5 0 0 1 2 2L6 15l-3 1 1-3 9.5-9.5Z"/></svg>
                  </button>
                  <button title="Delete" @click="showDeleteModal = true; deleteTarget = product.id">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h12M8 6V4h4v2M6 6l.7 9a1 1 0 0 0 1 1h4.6a1 1 0 0 0 1-1L14 6"/></svg>
                  </button>
                </div>
              </div>

              <div class="product-card-body">
                <p class="product-category-path">{{ product.category || 'Uncategorized' }}</p>
                <h5 class="product-name">{{ product.name }}</h5>
                <p class="product-sku">SKU: {{ product.sku || '—' }}</p>

                <div class="product-price-row">
                  <div>
                    <span class="product-price">{{ formatPrice(product.price) }}</span>
                    <span v-if="product.compare_price" class="product-compare-price">{{ formatPrice(product.compare_price) }}</span>
                  </div>
                  <div class="product-stock-info">
                    <p class="product-stock-label" :class="{ alert: stockStatusOf(product) === 'low_stock' }">
                      {{ stockStatusOf(product) === 'low_stock' ? 'Stock Alert' : 'Stock Level' }}
                    </p>
                    <p class="product-stock-qty">{{ product.stock ?? 0 }} Qty</p>
                  </div>
                </div>

                <div class="product-stock-bar">
                  <div :class="stockBarClass(product)" :style="{ width: stockBarWidth(product) }"></div>
                </div>
              </div>
            </div>
          </div>

          <div class="pagination">
            <p class="pagination-label">{{ paginationLabel }}</p>
            <div class="pagination-controls">
              <button class="page-btn" :disabled="currentPage === 1" @click="currentPage--">Previous</button>
              <button
                v-for="p in totalPages"
                :key="p"
                class="page-btn"
                :class="{ active: p === currentPage }"
                @click="currentPage = p"
              >{{ p }}</button>
              <button class="page-btn" :disabled="currentPage === totalPages" @click="currentPage++">Next</button>
            </div>
          </div>
        </template>
      </div>

      <!-- ============================================================
           PRODUCT PROFILE SHEET
           ============================================================ -->
      <aside v-if="sheetOpen" class="product-sheet">
        <div class="product-sheet-header">
          <div>
            <h3>Product Profile Sheet</h3>
            <p>{{ isNewProduct ? 'Create a new listing' : 'Edit product details & availability' }}</p>
          </div>
          <button class="modal-close" @click="closeSheet">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15"/></svg>
          </button>
        </div>

        <div class="product-sheet-body custom-scrollbar">
          <div>
            <label class="field-label">Product Name <span style="color:#dc2626;">*</span></label>
            <input type="text" class="field-input" v-model="form.name" placeholder="e.g. ProSound Wireless Headphones" />
          </div>

          <div>
            <label class="field-label">Description</label>
            <textarea class="field-input" rows="3" v-model="form.description" style="resize:vertical;"></textarea>
          </div>

          <div>
            <label class="field-label">Category <span style="color:#dc2626;">*</span></label>
            <input type="text" class="field-input" v-model="form.category" list="category-options" placeholder="e.g. Electronics > Audio" />
            <datalist id="category-options">
              <option v-for="cat in categories" :key="cat.name" :value="cat.name" />
            </datalist>
          </div>

          <div class="sheet-field-row">
            <div>
              <label class="field-label">Price <span style="color:#dc2626;">*</span></label>
              <div class="currency-input">
                <span>$</span>
                <input type="number" step="0.01" min="0" class="field-input" v-model.number="form.price" />
              </div>
            </div>
            <div>
              <label class="field-label">Compare Price</label>
              <div class="currency-input">
                <span>$</span>
                <input type="number" step="0.01" min="0" class="field-input" v-model.number="form.compare_price" />
              </div>
            </div>
          </div>

          <div class="sheet-field-row">
            <div>
              <label class="field-label">Promo Code</label>
              <input type="text" class="field-input" v-model="form.promo_code" style="text-transform:uppercase;" />
            </div>
            <div>
              <label class="field-label">Stock <span style="color:#dc2626;">*</span></label>
              <input type="number" min="0" step="1" class="field-input" v-model.number="form.stock" />
            </div>
          </div>

          <div>
            <label class="field-label">Product Images <span style="color:#dc2626;">*</span></label>
            <label class="image-dropzone">
              <input type="file" accept="image/*" multiple @change="handleImageUpload" style="display:none;" />
              <svg width="22" height="22" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 13V5M6.5 8.5 10 5l3.5 3.5"/><path d="M4 13v2a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-2"/></svg>
              <p class="dz-title">Click to upload or drag &amp; drop</p>
              <p class="dz-sub">PNG, JPG up to 5MB</p>
            </label>
            <!--
              NOTE: no Supabase Storage bucket for product images was confirmed
              to exist. New uploads are read as data URLs for instant preview
              and are included in the saved `images` array as-is. Swap the
              handleImageUpload function below for a real
              `supabase.storage.from('product-images').upload(...)` call once
              a bucket is set up — everything else here is unaffected.
            -->
            <div v-if="form.images.length" class="image-thumb-grid">
              <div v-for="(img, idx) in form.images" :key="idx" class="image-thumb">
                <img :src="img.url" />
                <button @click="form.images.splice(idx, 1)">
                  <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 5l10 10M15 5 5 15"/></svg>
                </button>
              </div>
              <label class="image-thumb-add">
                <input type="file" accept="image/*" multiple @change="handleImageUpload" style="display:none;" />
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 4v12M4 10h12"/></svg>
              </label>
            </div>
          </div>

          <p v-if="saveError" class="save-msg error">{{ saveError }}</p>
        </div>

        <div class="product-sheet-footer">
          <button class="btn-outline" style="border:none;background:none;" @click="closeSheet" :disabled="isSaving">Cancel Changes</button>
          <button class="btn-primary" @click="handleSave" :disabled="isSaving || !formIsValid">
            {{ isSaving ? 'Saving…' : 'Save Changes' }}
          </button>
        </div>
      </aside>
    </div>

    <!-- ============================================================
         DELETE CONFIRM MODAL (reuses existing .modal-* classes)
         ============================================================ -->
    <div v-if="showDeleteModal" class="modal-overlay" @click.self="showDeleteModal = false">
      <div class="modal-panel">
        <div class="modal-header">
          <h3>Delete {{ deleteTarget === 'bulk' ? `${selectedIds.size} products` : 'product' }}?</h3>
          <button class="modal-close" @click="showDeleteModal = false">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15"/></svg>
          </button>
        </div>
        <p class="modal-desc">This can't be undone. The listing{{ deleteTarget === 'bulk' ? 's' : '' }} will be permanently removed from your store.</p>
        <div class="modal-actions">
          <button class="btn-outline" style="flex:1;" @click="showDeleteModal = false">Cancel</button>
          <button class="btn-danger" style="flex:1;" @click="confirmDelete">Delete</button>
        </div>
      </div>
    </div>

    <!-- ============================================================
         MOVE CATEGORY MODAL
         ============================================================ -->
    <div v-if="showMoveModal" class="modal-overlay" @click.self="showMoveModal = false">
      <div class="modal-panel">
        <div class="modal-header">
          <h3>Move {{ selectedIds.size }} products</h3>
          <button class="modal-close" @click="showMoveModal = false">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15"/></svg>
          </button>
        </div>
        <label class="field-label">New category</label>
        <input type="text" class="field-input" v-model="moveCategoryInput" list="category-options" placeholder="e.g. Accessories > Photography" />
        <div class="modal-actions">
          <button class="btn-outline" style="flex:1;" @click="showMoveModal = false">Cancel</button>
          <button class="btn-primary" style="flex:1;" :disabled="!moveCategoryInput.trim()" @click="confirmMove">Move</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useSellerProducts } from '../composables/useSellerProducts';

const {
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
  totalPages,
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
} = useSellerProducts();

onMounted(loadProducts);

const stockStatusOptions = [
  { value: 'in_stock', label: 'In Stock' },
  { value: 'low_stock', label: 'Low Stock' },
  { value: 'out_of_stock', label: 'Out of Stock' },
];

// Price slider: two native <input type="range"> stacked, clamped so the
// low handle can't pass the high handle. Avoids pulling in a slider
// dependency for a single dual-thumb control.
function clampMin() {
  if (priceMin.value > priceMax.value) priceMin.value = priceMax.value;
}
function clampMax() {
  if (priceMax.value < priceMin.value) priceMax.value = priceMin.value;
}
const priceMinPct = computed(() => (priceMin.value / 1500) * 100);
const priceMaxPct = computed(() => (priceMax.value / 1500) * 100);

function clearFilters() {
  searchQuery.value = '';
  selectedCategories.value = [];
  selectedStockStatuses.value = [];
  priceMin.value = 0;
  priceMax.value = 1500;
}

// Reset to page 1 whenever the result set changes underneath the user.
watch(filteredProducts, () => { currentPage.value = 1; });

// ---- bulk select ----
const bulkSelectMode = ref(false);
function toggleBulkSelectMode() {
  bulkSelectMode.value = !bulkSelectMode.value;
  if (!bulkSelectMode.value) clearSelection();
}

// ---- delete modal ----
const showDeleteModal = ref(false);
const deleteTarget = ref(null); // product id, or 'bulk'
async function confirmDelete() {
  try {
    if (deleteTarget.value === 'bulk') {
      await deleteSelected();
      bulkSelectMode.value = false;
    } else {
      await deleteProduct(deleteTarget.value);
    }
  } finally {
    showDeleteModal.value = false;
    deleteTarget.value = null;
  }
}

// ---- move category modal ----
const showMoveModal = ref(false);
const moveCategoryInput = ref('');
function openMoveModal() {
  moveCategoryInput.value = '';
  showMoveModal.value = true;
}
async function confirmMove() {
  await moveSelectedToCategory(moveCategoryInput.value.trim());
  bulkSelectMode.value = false;
  showMoveModal.value = false;
}

// ---- product profile sheet ----
const activeProductId = ref(null); // null = closed, 'new' = creating, else product id
const sheetOpen = computed(() => activeProductId.value !== null);
const isNewProduct = computed(() => activeProductId.value === 'new');

const blankForm = () => ({
  name: '',
  description: '',
  category: '',
  price: null,
  compare_price: null,
  promo_code: '',
  stock: null,
  images: [],
});
const form = reactive(blankForm());

const formIsValid = computed(() =>
  form.name.trim() && form.category.trim() && form.price !== null && form.price !== '' && form.stock !== null && form.stock !== ''
);

function openNewProductSheet() {
  Object.assign(form, blankForm());
  activeProductId.value = 'new';
}

function openEditProductSheet(product) {
  Object.assign(form, {
    name: product.name || '',
    description: product.description || '',
    category: product.category || '',
    price: product.price ?? null,
    compare_price: product.compare_price ?? null,
    promo_code: product.promo_code || '',
    stock: product.stock ?? null,
    images: Array.isArray(product.images) ? [...product.images] : [],
  });
  activeProductId.value = product.id;
}

function closeSheet() {
  activeProductId.value = null;
  saveError.value = '';
}

function handleImageUpload(e) {
  const files = Array.from(e.target.files || []);
  for (const file of files) {
    const reader = new FileReader();
    reader.onload = () => {
      form.images.push({ url: reader.result, isNew: true });
    };
    reader.readAsDataURL(file);
  }
  e.target.value = '';
}

async function handleSave() {
  if (!formIsValid.value) return;
  const payload = { ...form };
  try {
    if (isNewProduct.value) {
      await createProduct(payload);
    } else {
      await updateProduct(activeProductId.value, payload);
    }
    closeSheet();
  } catch {
    // saveError is already set by the composable; keep the sheet open
    // so the seller can retry without losing their edits.
  }
}
</script>