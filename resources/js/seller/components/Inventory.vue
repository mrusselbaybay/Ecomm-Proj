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
                    <svg
                        width="19"
                        height="19"
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >
                        <rect x="3" y="3" width="14" height="14" rx="3" />
                        <path
                            v-if="bulkSelectMode"
                            d="M6.5 10l2.5 2.5L14 7.5"
                        />
                    </svg>
                </button>
                <span class="toolbar-label">Inventory Actions</span>
                <div class="toolbar-buttons">
                    <button
                        class="chip-btn danger"
                        :disabled="!selectedIds.size"
                        @click="
                            showDeleteModal = true;
                            deleteTarget = 'bulk';
                        "
                    >
                        <svg
                            width="13"
                            height="13"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <rect x="3" y="3.5" width="14" height="3" rx="1" />
                            <path
                                d="M4.5 6.5v7.5a1.5 1.5 0 0 0 1.5 1.5h8a1.5 1.5 0 0 0 1.5-1.5V6.5"
                            />
                            <path d="M8.2 10h3.6" />
                        </svg>
                        Archive Selected
                    </button>
                </div>
            </div>
            <div class="toolbar-right">
                <div class="header-search inventory-search">
                    <span class="search-icon">
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <circle cx="9" cy="9" r="6.5" />
                            <path d="M18 18l-3.8-3.8" />
                        </svg>
                    </span>
                    <input
                        type="text"
                        v-model="searchQuery"
                        placeholder="Search SKU, model..."
                    />
                </div>
                <button class="btn-primary" @click="openNewProductSheet">
                    <svg
                        width="16"
                        height="16"
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <circle cx="10" cy="10" r="8" />
                        <path d="M10 6v8M6 10h8" />
                    </svg>
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
                    <h4 class="filter-heading">Stock Status</h4>
                    <label
                        v-for="opt in stockStatusOptions"
                        :key="opt.value"
                        class="filter-check"
                    >
                        <span
                            class="check-box"
                            :class="{
                                checked: selectedStockStatuses.includes(
                                    opt.value,
                                ),
                            }"
                        >
                            <svg
                                width="11"
                                height="11"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="3"
                            >
                                <path d="M4 10l4 4 8-8" />
                            </svg>
                        </span>
                        <input
                            type="checkbox"
                            :value="opt.value"
                            v-model="selectedStockStatuses"
                            style="display: none"
                        />
                        {{ opt.label }}
                    </label>
                </div>

                <div class="filter-group">
                    <h4 class="filter-heading">Price Range</h4>
                    <div class="price-range">
                        <div
                            class="price-track"
                            ref="priceTrackEl"
                            @pointerdown="onTrackPointerDown"
                        >
                            <div
                                class="price-track-fill"
                                :style="{
                                    left: priceMinPct + '%',
                                    right: 100 - priceMaxPct + '%',
                                }"
                            ></div>
                            <div
                                class="price-thumb"
                                :class="{ dragging: draggingHandle === 'min' }"
                                :style="{ left: priceMinPct + '%' }"
                                role="slider"
                                tabindex="0"
                                aria-label="Minimum price"
                                aria-valuemin="0"
                                :aria-valuemax="priceCeiling"
                                :aria-valuenow="priceMin"
                                @pointerdown.stop="startDrag('min', $event)"
                                @keydown="onThumbKeydown('min', $event)"
                            ></div>
                            <div
                                class="price-thumb"
                                :class="{ dragging: draggingHandle === 'max' }"
                                :style="{ left: priceMaxPct + '%' }"
                                role="slider"
                                tabindex="0"
                                aria-label="Maximum price"
                                aria-valuemin="0"
                                :aria-valuemax="priceCeiling"
                                :aria-valuenow="priceMax"
                                @pointerdown.stop="startDrag('max', $event)"
                                @keydown="onThumbKeydown('max', $event)"
                            ></div>
                        </div>
                        <div class="price-inputs">
                            <div class="price-input-box">
                                <span>₱</span>
                                <input
                                    type="number"
                                    min="0"
                                    :max="priceCeiling"
                                    step="10"
                                    :value="priceMin"
                                    @change="onMinInputChange"
                                />
                            </div>
                            <span class="price-input-sep">–</span>
                            <div class="price-input-box">
                                <span>₱</span>
                                <input
                                    type="number"
                                    min="0"
                                    :max="priceCeiling"
                                    step="10"
                                    :value="priceMax"
                                    @change="onMaxInputChange"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="inventory-grid-wrap">
                <!-- Loading -->
                <div
                    v-if="isLoadingProducts"
                    class="empty-state"
                    style="padding: 4rem 1rem"
                >
                    <div class="loading-spinner"></div>
                    <p style="margin-top: 1rem">Loading products…</p>
                </div>

                <!-- Products table not set up yet -->
                <div
                    v-else-if="tableMissing"
                    class="card empty-state"
                    style="padding: 3rem 1.5rem"
                >
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                    >
                        <path d="m12 2 9 5-9 5-9-5 9-5Z" />
                        <path d="m3 12 9 5 9-5" />
                        <path d="m3 17 9 5 9-5" />
                    </svg>
                    <p style="font-weight: 700; color: #1e293b">
                        Inventory isn't set up yet
                    </p>
                    <p class="empty-hint">
                        This page is wired to a <code>products</code> table that
                        doesn't exist in the database yet. Once it's created,
                        this screen will load and manage real listings
                        automatically — no code changes needed.
                    </p>
                </div>

                <!-- Real error -->
                <div
                    v-else-if="loadError"
                    class="card empty-state"
                    style="padding: 3rem 1.5rem"
                >
                    <p style="font-weight: 700; color: #b91c1c">
                        Couldn't load your products
                    </p>
                    <p class="empty-hint">{{ loadError }}</p>
                    <button
                        class="btn-outline"
                        style="margin-top: 1rem"
                        @click="loadProducts"
                    >
                        Try again
                    </button>
                </div>

                <!-- No products at all -->
                <div
                    v-else-if="products.length === 0"
                    class="card empty-state"
                    style="padding: 3rem 1.5rem"
                >
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                    >
                        <path d="m12 2 9 5-9 5-9-5 9-5Z" />
                        <path d="m3 12 9 5 9-5" />
                        <path d="m3 17 9 5 9-5" />
                    </svg>
                    <p style="font-weight: 700; color: #1e293b">
                        No products yet
                    </p>
                    <p class="empty-hint">
                        Add your first listing to start building your catalog.
                    </p>
                    <button
                        class="btn-primary"
                        style="margin-top: 1rem"
                        @click="openNewProductSheet"
                    >
                        Add New Product
                    </button>
                </div>

                <!-- No results for current filters -->
                <div
                    v-else-if="filteredProducts.length === 0"
                    class="card empty-state"
                    style="padding: 3rem 1.5rem"
                >
                    <p style="font-weight: 700; color: #1e293b">
                        No products match these filters
                    </p>
                    <p class="empty-hint">
                        Try clearing your search or filters.
                    </p>
                    <button
                        class="btn-outline"
                        style="margin-top: 1rem"
                        @click="clearFilters"
                    >
                        Clear filters
                    </button>
                </div>

                <!-- Grid -->
                <template v-else>
                    <div class="product-grid">
                        <div
                            v-for="product in pagedProducts"
                            :key="product.id"
                            class="product-card"
                        >
                            <div class="product-card-image">
                                <img
                                    v-if="product.images?.[0]?.url"
                                    :src="product.images[0].url"
                                    :alt="product.name"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <div
                                    v-else
                                    class="product-card-image-placeholder"
                                >
                                    <svg
                                        width="28"
                                        height="28"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <rect
                                            x="3"
                                            y="3"
                                            width="18"
                                            height="18"
                                            rx="2"
                                        />
                                        <circle cx="8.5" cy="8.5" r="1.5" />
                                        <path d="m21 15-5-5L5 21" />
                                    </svg>
                                </div>
                                <span
                                    class="product-badge"
                                    :class="statusBadgeClass(product)"
                                    >{{ statusLabel(product) }}</span
                                >

                                <label
                                    v-if="bulkSelectMode"
                                    class="product-select-box"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="selectedIds.has(product.id)"
                                        @change="toggleSelected(product.id)"
                                    />
                                </label>

                                <div
                                    v-if="!bulkSelectMode"
                                    class="product-hover-actions"
                                >
                                    <button
                                        title="Edit"
                                        @click="openEditProductSheet(product)"
                                    >
                                        <svg
                                            width="14"
                                            height="14"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <path
                                                d="M13.5 3.5a1.5 1.5 0 0 1 2 2L6 15l-3 1 1-3 9.5-9.5Z"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        title="Archive"
                                        @click="
                                            showDeleteModal = true;
                                            deleteTarget = product.id;
                                        "
                                    >
                                        <svg
                                            width="14"
                                            height="14"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <rect
                                                x="3"
                                                y="3.5"
                                                width="14"
                                                height="3"
                                                rx="1"
                                            />
                                            <path
                                                d="M4.5 6.5v7.5a1.5 1.5 0 0 0 1.5 1.5h8a1.5 1.5 0 0 0 1.5-1.5V6.5"
                                            />
                                            <path d="M8.2 10h3.6" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="product-card-body">
                                <p class="product-category-path">
                                    {{ product.category || 'Uncategorized' }}
                                </p>
                                <h5 class="product-name">{{ product.name }}</h5>
                                <p class="product-sku">
                                    SKU: {{ product.sku || '—' }}
                                </p>

                                <div class="product-price-row">
                                    <div>
                                        <span class="product-price">{{
                                            formatPrice(product.price)
                                        }}</span>
                                        <span
                                            v-if="product.compare_price"
                                            class="product-compare-price"
                                            >{{
                                                formatPrice(
                                                    product.compare_price,
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div class="product-stock-info">
                                        <p
                                            class="product-stock-label"
                                            :class="{
                                                alert:
                                                    stockStatusOf(product) ===
                                                    'low_stock',
                                            }"
                                        >
                                            {{
                                                stockStatusOf(product) ===
                                                'low_stock'
                                                    ? 'Stock Alert'
                                                    : 'Stock Level'
                                            }}
                                        </p>
                                        <p class="product-stock-qty">
                                            {{ product.stock ?? 0 }} Qty
                                        </p>
                                    </div>
                                </div>

                                <div class="product-stock-bar">
                                    <div
                                        :class="stockBarClass(product)"
                                        :style="{
                                            width: stockBarWidth(product),
                                        }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pagination">
                        <p class="pagination-label">{{ paginationLabel }}</p>
                        <div class="pagination-controls">
                            <button
                                class="page-btn"
                                :disabled="currentPage === 1"
                                @click="currentPage--"
                            >
                                Previous
                            </button>
                            <button
                                v-for="p in totalPages"
                                :key="p"
                                class="page-btn"
                                :class="{ active: p === currentPage }"
                                @click="currentPage = p"
                            >
                                {{ p }}
                            </button>
                            <button
                                class="page-btn"
                                :disabled="currentPage === totalPages"
                                @click="currentPage++"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ============================================================
           PRODUCT PROFILE SHEET
           Centered modal (reuses .modal-overlay, the same backdrop the
           Archive-confirm dialog uses) rather than the old sidebar-docked
           panel. Sections are grouped into icon-badged cards for a
           cleaner scan-path; the body scrolls internally
           (product-modal-body, scrollbar hidden but fully scrollable) so
           the header/footer stay put. No fake multi-step wizard: this
           form isn't actually paginated, so a step indicator would just
           be decorative and misleading.
           ============================================================ -->
            <div
                v-if="sheetOpen"
                class="modal-overlay"
                @click.self="handleCancelClick"
            >
                <div class="product-modal">
                    <div class="product-modal-gradient"></div>

                    <div class="product-modal-header">
                        <div>
                            <h2>Product Profile Sheet</h2>
                            <div class="product-modal-status">
                                <span
                                    class="status-dot"
                                    :class="isNewProduct ? 'status-dot-new' : 'status-dot-edit'"
                                ></span>
                                <p>
                                    {{
                                        isNewProduct
                                            ? 'New listing draft'
                                            : 'Editing — saving will resend this listing for review'
                                    }}
                                </p>
                            </div>
                        </div>
                        <button class="modal-close" @click="handleCancelClick">
                            <svg
                                width="18"
                                height="18"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M5 5l10 10M15 5 5 15" />
                            </svg>
                        </button>
                    </div>

                    <div class="product-modal-body custom-scrollbar">
                        <!-- ============================================
                         BASIC INFORMATION
                         ============================================ -->
                        <section class="ps-section">
                            <div class="ps-section-header">
                                <div class="ps-icon-badge ps-icon-teal">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                    >
                                        <path
                                            d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z"
                                        />
                                        <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                                        <path d="M10 10v7.5" />
                                    </svg>
                                </div>
                                <div>
                                    <h3>Basic Information</h3>
                                    <p>The core details buyers see first.</p>
                                </div>
                            </div>

                            <div class="ps-section-card">
                                <div>
                                    <label class="field-label"
                                        >Product Name
                                        <span style="color: #dc2626"
                                            >*</span
                                        ></label
                                    >
                                    <input
                                        type="text"
                                        class="field-input"
                                        v-model="form.name"
                                        placeholder="e.g. ProSound Wireless Headphones"
                                    />
                                </div>

                                <div>
                                    <label class="field-label"
                                        >Category
                                        <span style="color: #dc2626"
                                            >*</span
                                        ></label
                                    >
                                    <div class="ps-category-chip">
                                        <span>{{ form.category || 'Not set' }}</span>
                                    </div>
                                    <p class="field-hint">
                                        Automatically taken from your seller
                                        registration (Line of Business) —
                                        this can't be changed here.
                                    </p>
                                </div>

                                <div>
                                    <label class="field-label"
                                        >Description</label
                                    >
                                    <textarea
                                        class="field-input"
                                        rows="3"
                                        v-model="form.description"
                                        style="resize: vertical"
                                    ></textarea>
                                </div>
                            </div>
                        </section>

                        <!-- ============================================
                         PRICING & INVENTORY
                         ============================================ -->
                        <section class="ps-section">
                            <div class="ps-section-header">
                                <div class="ps-icon-badge ps-icon-emerald">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                    >
                                        <path
                                            d="M11 3H5a2 2 0 0 0-2 2v6l8.6 8.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8L11 3Z"
                                        />
                                        <circle cx="7.5" cy="7.5" r="1" />
                                    </svg>
                                </div>
                                <div>
                                    <h3>Pricing &amp; Inventory</h3>
                                    <p>
                                        Set your selling price and track
                                        stock.
                                    </p>
                                </div>
                            </div>

                            <div class="ps-section-card">
                                <div class="sheet-field-row">
                                    <div>
                                        <label class="field-label"
                                            >Price
                                            <span style="color: #dc2626"
                                                >*</span
                                            ></label
                                        >
                                        <div class="currency-input">
                                            <span>₱</span>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="field-input"
                                                v-model.number="form.price"
                                            />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="field-label"
                                            >Compare Price</label
                                        >
                                        <div class="currency-input">
                                            <span>₱</span>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="field-input"
                                                v-model.number="form.compare_price"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div class="sheet-field-row">
                                    <div>
                                        <label class="field-label"
                                            >Stock
                                            <span style="color: #dc2626"
                                                >*</span
                                            ></label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            class="field-input"
                                            v-model.number="form.stock"
                                        />
                                    </div>
                                    <div>
                                        <label class="field-label"
                                            >Low-Stock Threshold</label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            class="field-input"
                                            v-model.number="form.low_stock_threshold"
                                            placeholder="10"
                                        />
                                        <p class="field-hint">
                                            You'll see a Low Stock warning
                                            once stock falls at or below
                                            this number.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ============================================
                         PRODUCT DETAILS
                         ============================================ -->
                        <section class="ps-section">
                            <div class="ps-section-header">
                                <div class="ps-icon-badge ps-icon-sky">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                    >
                                        <rect
                                            x="3"
                                            y="4"
                                            width="14"
                                            height="12"
                                            rx="2"
                                        />
                                        <path d="M3 8h14" />
                                    </svg>
                                </div>
                                <div>
                                    <h3>Product Details</h3>
                                    <p>Brand, condition, and promo code.</p>
                                </div>
                            </div>

                            <div class="ps-section-card">
                                <div class="sheet-field-row">
                                    <div>
                                        <label class="field-label"
                                            >Brand</label
                                        >
                                        <input
                                            type="text"
                                            class="field-input"
                                            v-model="form.brand"
                                        />
                                    </div>
                                    <div>
                                        <label class="field-label"
                                            >Condition</label
                                        >
                                        <select
                                            class="field-input"
                                            v-model="form.condition"
                                        >
                                            <option value="">
                                                Not specified
                                            </option>
                                            <option value="new">New</option>
                                            <option value="used">Used</option>
                                            <option value="refurbished">
                                                Refurbished
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label class="field-label"
                                        >Promo Code</label
                                    >
                                    <input
                                        type="text"
                                        class="field-input"
                                        v-model="form.promo_code"
                                        style="
                                            text-transform: uppercase;
                                            max-width: 220px;
                                        "
                                        placeholder="Optional"
                                    />
                                </div>
                            </div>
                        </section>

                        <!-- ============================================
                         SPECIFICATIONS
                         Fields are driven entirely by the seller's own
                         category (categoryConfig, from GET /api/seller/
                         category-config) — only fields relevant to that
                         category ever render here, and the backend
                         re-checks every key/value against the same
                         template regardless of what's submitted.
                         ============================================ -->
                        <section
                            v-if="categoryConfig?.specifications?.length"
                            class="ps-section"
                        >
                            <div class="ps-section-header">
                                <div class="ps-icon-badge ps-icon-indigo">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                    >
                                        <path d="M4 5h12M4 10h12M4 15h8" />
                                    </svg>
                                </div>
                                <div>
                                    <h3>Specifications</h3>
                                    <p>
                                        Shown to buyers on the product page
                                        — {{ categoryConfig.category }}.
                                        Everything here is optional.
                                    </p>
                                </div>
                            </div>

                            <div class="ps-section-card">
                                <div class="spec-fields-grid scrollbar-hidden">
                                    <div
                                        v-for="field in categoryConfig.specifications"
                                        :key="field.key"
                                    >
                                        <label class="field-label">{{
                                            field.label
                                        }}</label>

                                        <select
                                            v-if="field.type === 'select'"
                                            class="field-input"
                                            v-model="form.specifications[field.key]"
                                        >
                                            <option value="">
                                                Not specified
                                            </option>
                                            <option
                                                v-for="opt in field.options"
                                                :key="opt"
                                                :value="opt"
                                            >
                                                {{ opt }}
                                            </option>
                                        </select>

                                        <textarea
                                            v-else-if="field.type === 'textarea'"
                                            class="field-input"
                                            rows="2"
                                            style="resize: vertical"
                                            v-model="form.specifications[field.key]"
                                        ></textarea>

                                        <input
                                            v-else-if="field.type === 'date'"
                                            type="date"
                                            class="field-input"
                                            v-model="form.specifications[field.key]"
                                        />

                                        <input
                                            v-else
                                            type="text"
                                            class="field-input"
                                            v-model="form.specifications[field.key]"
                                        />
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ============================================
                         VARIANTS
                         Optional: leave with no options to keep this a
                         simple product using the Price/Stock above.
                         Option types/values are constrained to what's
                         relevant for the seller's own category
                         (categoryConfig) — never free-typed, except the
                         rare field marked free-text in the template
                         (e.g. Model).
                         ============================================ -->
                        <section class="ps-section">
                            <div class="ps-section-header">
                                <div class="ps-icon-badge ps-icon-amber">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                    >
                                        <path
                                            d="M3 7 10 3l7 4-7 4-7-4Z"
                                        />
                                        <path d="M3 7v6l7 4 7-4V7" />
                                    </svg>
                                </div>
                                <div style="flex: 1">
                                    <h3>Product Variants</h3>
                                    <p>
                                        <template v-if="isLoadingCategoryConfig">
                                            Loading the option types
                                            available for your category…
                                        </template>
                                        <template
                                            v-else-if="!availableOptionNames.length && !form.options.length"
                                        >
                                            No variant options are
                                            available for
                                            {{
                                                categoryConfig?.category ||
                                                'your category'
                                            }}
                                            — this will sell as a single
                                            item.
                                        </template>
                                        <template v-else>
                                            Add options like
                                            {{ variantOptionNamesHint }} to
                                            sell multiple variants, or
                                            leave empty for a single item.
                                        </template>
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="btn-outline"
                                    style="padding: 6px 14px; font-size: 12px"
                                    :disabled="isLoadingCategoryConfig || !availableOptionNames.length"
                                    :title="
                                        isLoadingCategoryConfig
                                            ? 'Loading available option types…'
                                            : !availableOptionNames.length && form.options.length
                                                ? 'All available option types for your category have been added'
                                                : ''
                                    "
                                    @click="addOption"
                                >
                                    {{
                                        isLoadingCategoryConfig
                                            ? 'Loading…'
                                            : '+ Add Option'
                                    }}
                                </button>
                            </div>

                            <div class="ps-section-card">
                                <div
                                    v-for="(option, oi) in form.options"
                                    :key="oi"
                                    class="variant-option-row"
                                >
                                    <select
                                        class="field-input"
                                        style="max-width: 160px"
                                        v-model="option.name"
                                        @change="option.values = []"
                                    >
                                        <option value="" disabled>
                                            Choose option type
                                        </option>
                                        <option
                                            v-for="name in availableOptionNamesFor(option.name)"
                                            :key="name"
                                            :value="name"
                                        >
                                            {{ name }}
                                        </option>
                                    </select>
                                    <div
                                        class="variant-option-values scrollbar-hidden"
                                    >
                                        <span
                                            v-for="(val, vi) in option.values"
                                            :key="vi"
                                            class="variant-value-chip"
                                        >
                                            {{ val }}
                                            <button
                                                type="button"
                                                @click="removeOptionValue(oi, vi)"
                                            >
                                                ×
                                            </button>
                                        </span>

                                        <input
                                            v-if="isOptionFreeText(option.name)"
                                            type="text"
                                            class="variant-value-input"
                                            placeholder="Add value, press Enter"
                                            @keydown.enter.prevent="addOptionValue(oi, $event)"
                                        />
                                        <select
                                            v-else-if="option.name"
                                            class="variant-value-input"
                                            style="border: none; outline: none"
                                            @change="addOptionValueFromSelect(oi, $event)"
                                        >
                                            <option value="">
                                                {{
                                                    remainingValuesFor(option).length
                                                        ? '+ Add value'
                                                        : 'All values added'
                                                }}
                                            </option>
                                            <option
                                                v-for="val in remainingValuesFor(option)"
                                                :key="val"
                                                :value="val"
                                            >
                                                {{ val }}
                                            </option>
                                        </select>
                                    </div>
                                    <button
                                        type="button"
                                        class="chip-btn danger"
                                        @click="removeOption(oi)"
                                    >
                                        Remove
                                    </button>
                                </div>

                                <div
                                    v-if="form.variants.length"
                                    class="variant-table-wrap scrollbar-hidden"
                                >
                                    <table class="variant-table">
                                        <thead>
                                            <tr>
                                                <th>Combination</th>
                                                <th>Price</th>
                                                <th>Stock</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="(variant, vi) in form.variants"
                                                :key="vi"
                                            >
                                                <td>
                                                    {{ variantLabel(variant) }}
                                                </td>
                                                <td>
                                                    <input
                                                        type="text"
                                                        class="field-input"
                                                        :value="`₱${form.price || 0}`"
                                                        disabled
                                                        title="Set in Pricing & Inventory above."
                                                    />
                                                </td>
                                                <td>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="1"
                                                        class="field-input"
                                                        v-model.number="variant.stock"
                                                    />
                                                </td>
                                                <td>
                                                    <select
                                                        class="field-input"
                                                        v-model="variant.status"
                                                    >
                                                        <option value="active">
                                                            Active
                                                        </option>
                                                        <option value="unavailable">
                                                            Unavailable
                                                        </option>
                                                    </select>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>

                        <!-- ============================================
                         PRODUCT MEDIA
                         ============================================ -->
                        <section class="ps-section">
                            <div class="ps-section-header">
                                <div class="ps-icon-badge ps-icon-pink">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                    >
                                        <rect
                                            x="3"
                                            y="4"
                                            width="14"
                                            height="12"
                                            rx="2"
                                        />
                                        <circle cx="7.5" cy="8.5" r="1.2" />
                                        <path
                                            d="m4 15 4-4 3 3 3-4 3 4"
                                        />
                                    </svg>
                                </div>
                                <div>
                                    <h3>Product Media</h3>
                                    <p>
                                        Visuals sell — add clear, well-lit
                                        photos.
                                    </p>
                                </div>
                            </div>

                            <div class="ps-section-card">
                                <label class="field-label"
                                    >Product Images
                                    <span style="color: #dc2626"
                                        >*</span
                                    ></label
                                >
                                <label class="image-dropzone">
                                    <input
                                        type="file"
                                        accept="image/*"
                                        multiple
                                        @change="handleImageUpload"
                                        style="display: none"
                                    />
                                    <svg
                                        width="22"
                                        height="22"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                    >
                                        <path d="M10 13V5M6.5 8.5 10 5l3.5 3.5" />
                                        <path
                                            d="M4 13v2a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-2"
                                        />
                                    </svg>
                                    <p class="dz-title">
                                        Click to upload or drag &amp; drop
                                    </p>
                                    <p class="dz-sub">PNG, JPG up to 5MB</p>
                                </label>
                                <!--
                      NOTE: no Supabase Storage bucket for product images was
                      confirmed to exist. New uploads are read as data URLs
                      for instant preview and are included in the saved
                      `images` array as-is. Swap the handleImageUpload
                      function below for a real
                      `supabase.storage.from('product-images').upload(...)`
                      call once a bucket is set up — everything else here is
                      unaffected.
                    -->
                                <div
                                    v-if="form.images.length"
                                    class="image-thumb-grid"
                                >
                                    <div
                                        v-for="(img, idx) in form.images"
                                        :key="idx"
                                        class="image-thumb"
                                    >
                                        <img :src="img.url" />
                                        <button
                                            @click="form.images.splice(idx, 1)"
                                        >
                                            <svg
                                                width="10"
                                                height="10"
                                                viewBox="0 0 20 20"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2.5"
                                            >
                                                <path
                                                    d="M5 5l10 10M15 5 5 15"
                                                />
                                            </svg>
                                        </button>
                                    </div>
                                    <label class="image-thumb-add">
                                        <input
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            @change="handleImageUpload"
                                            style="display: none"
                                        />
                                        <svg
                                            width="16"
                                            height="16"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <path d="M10 4v12M4 10h12" />
                                        </svg>
                                    </label>
                                </div>
                            </div>
                        </section>

                        <p v-if="saveError" class="save-msg error">
                            {{ saveError }}
                        </p>
                    </div>

                    <div class="product-modal-footer">
                        <p
                            v-if="!isSaving && saveDisabledReason"
                            class="product-modal-footer-hint"
                        >
                            {{ saveDisabledReason }}
                        </p>
                        <div class="product-modal-footer-actions">
                            <button
                                class="btn-outline"
                                style="border: none; background: none"
                                @click="handleCancelClick"
                                :disabled="isSaving"
                            >
                                Cancel Changes
                            </button>
                            <button
                                class="btn-primary"
                                @click="handleSaveClick"
                                :disabled="isSaving || !formIsValid"
                                :title="saveDisabledReason"
                            >
                                {{ isSaving ? 'Saving…' : 'Save Changes' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
             DISCARD CHANGES CONFIRM (reuses .modal-* classes; renders on
             top of the product modal's own overlay)
             ============================================================ -->
            <div
                v-if="showDiscardConfirm"
                class="modal-overlay"
                style="z-index: 70"
                @click.self="showDiscardConfirm = false"
            >
                <div class="modal-panel">
                    <div class="modal-header">
                        <h3>Discard changes?</h3>
                        <button
                            class="modal-close"
                            @click="showDiscardConfirm = false"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M5 5l10 10M15 5 5 15" />
                            </svg>
                        </button>
                    </div>
                    <p class="modal-desc">
                        You have unsaved changes on this product. If you
                        leave now, they'll be lost.
                    </p>
                    <div class="modal-actions">
                        <button
                            class="btn-outline"
                            style="flex: 1"
                            @click="showDiscardConfirm = false"
                        >
                            Continue Editing
                        </button>
                        <button
                            class="btn-danger"
                            style="flex: 1"
                            @click="confirmDiscard"
                        >
                            Discard Changes
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============================================================
             SAVE CHANGES CONFIRM (reuses .modal-* classes)
             ============================================================ -->
            <div
                v-if="showSaveConfirm"
                class="modal-overlay"
                style="z-index: 70"
                @click.self="showSaveConfirm = false"
            >
                <div class="modal-panel">
                    <div class="modal-header">
                        <h3>
                            {{
                                isNewProduct
                                    ? 'Create this product?'
                                    : 'Save changes?'
                            }}
                        </h3>
                        <button
                            class="modal-close"
                            @click="showSaveConfirm = false"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M5 5l10 10M15 5 5 15" />
                            </svg>
                        </button>
                    </div>
                    <p class="modal-desc">
                        {{
                            isNewProduct
                                ? "This product will be submitted for admin review before it's visible to buyers."
                                : "Saving will send this listing back for admin review before it's visible to buyers again."
                        }}
                    </p>
                    <div class="modal-actions">
                        <button
                            class="btn-outline"
                            style="flex: 1"
                            @click="showSaveConfirm = false"
                        >
                            Cancel
                        </button>
                        <button
                            class="btn-primary"
                            style="flex: 1"
                            @click="confirmSave"
                        >
                            {{
                                isNewProduct
                                    ? 'Create Product'
                                    : 'Save Changes'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
         DELETE CONFIRM MODAL (reuses existing .modal-* classes)
         ============================================================ -->
        <div
            v-if="showDeleteModal"
            class="modal-overlay"
            @click.self="showDeleteModal = false"
        >
            <div class="modal-panel">
                <div class="modal-header">
                    <h3>
                        Archive
                        {{
                            deleteTarget === 'bulk'
                                ? `${selectedIds.size} products`
                                : 'product'
                        }}?
                    </h3>
                    <button
                        class="modal-close"
                        @click="showDeleteModal = false"
                    >
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M5 5l10 10M15 5 5 15" />
                        </svg>
                    </button>
                </div>
                <p class="modal-desc">
                    The listing{{
                        deleteTarget === 'bulk' ? 's' : ''
                    }}
                    will be removed from your store and hidden from buyers.
                    You can contact support to have it restored later.
                </p>
                <div class="modal-actions">
                    <button
                        class="btn-outline"
                        style="flex: 1"
                        @click="showDeleteModal = false"
                    >
                        Cancel
                    </button>
                    <button
                        class="btn-danger"
                        style="flex: 1"
                        @click="confirmDelete"
                    >
                        Archive
                    </button>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useSellerProducts } from '../composables/useSellerProducts';
import { useSeller } from '../composables/useSeller';

const {
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
    totalPages,
    paginationLabel,
    pagedProducts,
    filteredProducts,

    selectedIds,
    toggleSelected,
    clearSelection,

    loadProducts,
    createProduct,
    updateProduct,
    deleteProduct,
    deleteSelected,

    categoryConfig,
    isLoadingCategoryConfig,
    loadCategoryConfig,

    stockStatusOf,
    formatPrice,
    statusBadgeClass,
    statusLabel,
    stockBarClass,
    stockBarWidth,
} = useSellerProducts();

const { sellerDetails } = useSeller();

onMounted(async () => {
    // loadProducts() is deduplicated and also watches for a late-arriving
    // seller session, so this is safe even if the composable already started
    // the request before this component mounted. Product cards get network
    // priority; the form-only category config loads immediately afterward.
    await loadProducts();
    void loadCategoryConfig();
});

const stockStatusOptions = [
    { value: 'in_stock', label: 'In Stock' },
    { value: 'low_stock', label: 'Low Stock' },
    { value: 'out_of_stock', label: 'Out of Stock' },
];

// ---- price range: custom dual-handle slider ----
// The two thumbs are plain divs positioned by percentage, dragged via
// the Pointer Events API (covers mouse/touch/pen with one code path).
// Replaces an earlier version built from two stacked native
// <input type="range"> elements with a pointer-events CSS hack, which
// was unreliable for the lower (min) handle across browsers — this
// gives full control over hit-testing so both handles are always
// independently draggable, plus click-anywhere-on-the-track-to-jump
// and keyboard support (arrow keys / Home / End) for accessibility.
const PRICE_MIN_BOUND = 0;
const PRICE_STEP = 10;

const priceTrackEl = ref(null);
const draggingHandle = ref(null); // null | 'min' | 'max'

// The slider's upper bound isn't a fixed number — it starts from the
// seller's own catalog (highest product or variant price), rounded up to
// a clean ₱100 step, with a floor so the slider stays usable for a
// brand-new or entirely low-priced catalog. It's a real ref (not just a
// computed from products) because it also needs to EXPAND when a seller
// types a bigger number directly into the Max Price box — see
// onMaxInputChange below. It only ever grows, never auto-shrinks, so it
// can't clamp a typed value back down to some earlier, lower ceiling.
const PRICE_CEILING_FLOOR = 1500;
const priceCeiling = ref(PRICE_CEILING_FLOOR);

const catalogCeiling = computed(() => {
    const prices = products.value.flatMap((p) => {
        const variantPrices = (p.variants || [])
            .map((v) => Number(v.price))
            .filter((n) => Number.isFinite(n));

        return [Number(p.price) || 0, ...variantPrices];
    });

    const highest = prices.length ? Math.max(...prices) : 0;

    return Math.max(PRICE_CEILING_FLOOR, Math.ceil(highest / 100) * 100);
});

// Once the real catalog ceiling is known (products have loaded), push
// the filter's upper handle out to match it — but only the first time,
// so a seller who's already deliberately narrowed the range never gets
// it silently reset out from under them by a later products refresh.
// Later increases to catalogCeiling (e.g. a new pricier product) still
// grow priceCeiling itself, since the slider's range should never be
// narrower than what's actually in the catalog.
let priceMaxInitialized = false;
watch(
    catalogCeiling,
    (ceiling) => {
        priceCeiling.value = Math.max(priceCeiling.value, ceiling);

        if (!priceMaxInitialized) {
            priceMaxInitialized = true;
            priceMax.value = ceiling;
        }
    },
    { immediate: true },
);

const priceMinPct = computed(() => (priceMin.value / priceCeiling.value) * 100);
const priceMaxPct = computed(() => (priceMax.value / priceCeiling.value) * 100);

function clampPrice(value) {
    const stepped = Math.round(value / PRICE_STEP) * PRICE_STEP;

    return Math.min(priceCeiling.value, Math.max(PRICE_MIN_BOUND, stepped));
}

function valueFromClientX(clientX) {
    const rect = priceTrackEl.value?.getBoundingClientRect();

    if (!rect || rect.width === 0) {
        return PRICE_MIN_BOUND;
    }

    const ratio = (clientX - rect.left) / rect.width;

    return clampPrice(ratio * priceCeiling.value);
}

function applyDragValue(handle, value) {
    if (handle === 'min') {
        priceMin.value = Math.min(value, priceMax.value);
    } else {
        priceMax.value = Math.max(value, priceMin.value);
    }
}

function startDrag(handle, event) {
    draggingHandle.value = handle;
    event.target.focus();
    event.preventDefault();

    window.addEventListener('pointermove', onDragMove);
    window.addEventListener('pointerup', stopDrag, { once: true });
}

function onDragMove(event) {
    if (!draggingHandle.value) {
        return;
    }

    applyDragValue(draggingHandle.value, valueFromClientX(event.clientX));
}

function stopDrag() {
    draggingHandle.value = null;
    window.removeEventListener('pointermove', onDragMove);
}

// Clicking anywhere on the track (not directly on a handle, which stops
// propagation via @pointerdown.stop) jumps whichever handle is nearer to
// that spot — a common, expected affordance for range sliders.
function onTrackPointerDown(event) {
    const value = valueFromClientX(event.clientX);
    const handle =
        Math.abs(value - priceMin.value) <= Math.abs(value - priceMax.value)
            ? 'min'
            : 'max';

    applyDragValue(handle, value);
    startDrag(handle, event);
}

// ---- typed price inputs (mirror the slider, same clamp/step rules) ----
// @change (not @input) so the value only commits — and gets clamped —
// once the seller finishes typing (on blur or Enter), rather than
// fighting their keystrokes while a partial number is still being typed.
// :value (one-way) + manual handlers, not v-model, so an out-of-range or
// invalid entry always snaps back to the real, valid current value.
function onMinInputChange(event) {
    const raw = Number(event.target.value);

    applyDragValue('min', Number.isFinite(raw) ? clampPrice(raw) : priceMin.value);
    event.target.value = priceMin.value;
}

function onMaxInputChange(event) {
    const raw = Number(event.target.value);

    // A typed value bigger than the current ceiling should expand the
    // slider's range to fit it, not get silently clamped back down to
    // whatever the ceiling happened to be — that clamping-instead-of-
    // expanding was the bug: typing 50000 was snapping back to 1500.
    if (Number.isFinite(raw) && raw > priceCeiling.value) {
        priceCeiling.value = Math.ceil(raw / 100) * 100;
    }

    applyDragValue('max', Number.isFinite(raw) ? clampPrice(raw) : priceMax.value);
    event.target.value = priceMax.value;
}

function onThumbKeydown(handle, event) {
    const current = handle === 'min' ? priceMin.value : priceMax.value;
    let next = current;

    if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
        next = current - PRICE_STEP;
    } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
        next = current + PRICE_STEP;
    } else if (event.key === 'Home') {
        next = PRICE_MIN_BOUND;
    } else if (event.key === 'End') {
        next = priceCeiling.value;
    } else {
        return;
    }

    event.preventDefault();
    applyDragValue(handle, clampPrice(next));
}

onBeforeUnmount(() => {
    window.removeEventListener('pointermove', onDragMove);
});

function clearFilters() {
    searchQuery.value = '';
    selectedStockStatuses.value = [];
    priceMin.value = PRICE_MIN_BOUND;
    priceMax.value = priceCeiling.value;
}

// Reset to page 1 whenever the result set changes underneath the user.
watch(filteredProducts, () => {
    currentPage.value = 1;
});

// ---- bulk select ----
const bulkSelectMode = ref(false);
function toggleBulkSelectMode() {
    bulkSelectMode.value = !bulkSelectMode.value;

    if (!bulkSelectMode.value) {
        clearSelection();
    }
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

// ---- product profile sheet ----
const activeProductId = ref(null); // null = closed, 'new' = creating, else product id
const sheetOpen = computed(() => activeProductId.value !== null);
const isNewProduct = computed(() => activeProductId.value === 'new');

const blankForm = () => ({
    name: '',
    description: '',
    category: '',
    brand: '',
    condition: '',
    specifications: {}, // { [field.key]: value } — keys from categoryConfig.specifications
    low_stock_threshold: null,
    price: null,
    compare_price: null,
    promo_code: '',
    stock: null,
    images: [],
    options: [], // [{ name, values: [] }] — draft editor state
    variants: [], // [{ option_values: {Name: value}, sku, price, stock, image, status }]
});
const form = reactive(blankForm());

const formIsValid = computed(
    () =>
        form.name.trim() &&
        form.category.trim() &&
        form.price !== null &&
        form.price !== '' &&
        form.stock !== null &&
        form.stock !== '',
);

// Explains *why* Save is disabled instead of leaving sellers to guess at
// a grayed-out button — checked in priority order, most important first.
const saveDisabledReason = computed(() => {
    if (!form.name.trim()) {
        return 'Enter a product name to continue.';
    }
    if (form.price === null || form.price === '') {
        return 'Enter a price to continue.';
    }
    if (form.stock === null || form.stock === '') {
        return 'Enter a stock quantity to continue.';
    }

    return '';
});

// ---- variants: option/value editing ----

function addOption() {
    form.options.push({ name: '', values: [] });
}

function removeOption(index) {
    form.options.splice(index, 1);
}

function addOptionValue(optionIndex, event) {
    const input = event.target;
    const value = input.value.trim();

    if (value && !form.options[optionIndex].values.includes(value)) {
        form.options[optionIndex].values.push(value);
    }

    input.value = '';
}

function removeOptionValue(optionIndex, valueIndex) {
    form.options[optionIndex].values.splice(valueIndex, 1);
}

// ---- category-constrained option types/values ----
// All sourced from categoryConfig (GET /api/seller/category-config),
// never hardcoded here — a different seller category simply gets a
// different config payload and this same code renders accordingly.

function categoryOptionDef(name) {
    return (categoryConfig.value?.variant_options || []).find(
        (o) => o.name === name,
    );
}

const variantOptionNamesHint = computed(() => {
    const names = (categoryConfig.value?.variant_options || []).map((o) => o.name);

    return names.length ? names.slice(0, 3).join(', ') : 'Color or Size';
});

// Option types not yet used by any other row (a seller can't add the
// same type — e.g. two "Color" rows — twice).
const availableOptionNames = computed(() => {
    const used = new Set(form.options.map((o) => o.name).filter(Boolean));

    return (categoryConfig.value?.variant_options || [])
        .map((o) => o.name)
        .filter((name) => !used.has(name));
});

// Same as above but keeps `currentName` selectable in its own row's
// dropdown even though it's "used" (by that row itself).
function availableOptionNamesFor(currentName) {
    const names = availableOptionNames.value;

    return currentName && !names.includes(currentName)
        ? [currentName, ...names]
        : names;
}

function isOptionFreeText(name) {
    const def = categoryOptionDef(name);

    return !!def?.free_text;
}

function remainingValuesFor(option) {
    const def = categoryOptionDef(option.name);

    if (!def || def.free_text) {
        return [];
    }

    return (def.values || []).filter((v) => !option.values.includes(v));
}

function addOptionValueFromSelect(optionIndex, event) {
    const value = event.target.value;

    if (value && !form.options[optionIndex].values.includes(value)) {
        form.options[optionIndex].values.push(value);
    }

    event.target.value = '';
}

function variantLabel(variant) {
    return Object.entries(variant.option_values || {})
        .map(([name, value]) => `${name}: ${value}`)
        .join(', ');
}

function comboKey(optionValues) {
    return Object.keys(optionValues)
        .sort()
        .map((k) => `${k.trim().toLowerCase()}:${String(optionValues[k]).trim().toLowerCase()}`)
        .join('|');
}

// Cartesian product of every option's values, e.g. Color:[Black,White] x
// Size:[S,M] -> 4 combinations. Options with no name or no values yet are
// skipped so a half-filled option row doesn't blow up the table.
function cartesianCombos(options) {
    const usable = options.filter((o) => o.name.trim() && o.values.length > 0);

    if (!usable.length) {
        return [];
    }

    return usable.reduce(
        (combos, option) => {
            const next = [];

            for (const combo of combos) {
                for (const value of option.values) {
                    next.push({ ...combo, [option.name.trim()]: value });
                }
            }

            return next;
        },
        [{}],
    );
}

// Regenerates form.variants from form.options whenever an option/value is
// added, renamed, or removed — preserving already-entered SKU/price/
// stock/image/status for combinations that still exist (matched by combo
// key), so editing one option doesn't wipe out work already done on the
// others.
watch(
    () => form.options,
    (options) => {
        const combos = cartesianCombos(options);
        const existingByKey = new Map(
            form.variants.map((v) => [comboKey(v.option_values), v]),
        );

        form.variants = combos.map((optionValues) => {
            const existing = existingByKey.get(comboKey(optionValues));

            if (existing) {
                return { ...existing, option_values: optionValues };
            }

            return {
                option_values: optionValues,
                stock: 0,
                status: 'active',
            };
        });
    },
    { deep: true },
);

// Pre-seeds form.specifications with every key from the current
// category's template (blank by default), so each <select>/<input> has
// a real reactive key to bind to from the moment the sheet opens, rather
// than relying on Vue to add the property lazily on first input.
function blankSpecifications() {
    const fields = categoryConfig.value?.specifications || [];

    return Object.fromEntries(fields.map((f) => [f.key, '']));
}

function openNewProductSheet() {
    Object.assign(form, blankForm());
    form.category = sellerDetails.value?.line_of_business || '';
    form.specifications = blankSpecifications();
    activeProductId.value = 'new';
    formSnapshot.value = JSON.stringify(form);
}

// If seller details finish loading after the Add Product sheet was opened,
// automatically fill the category as soon as line_of_business becomes available.
watch(
    () => sellerDetails.value?.line_of_business,
    (lineOfBusiness) => {
        if (isNewProduct.value && lineOfBusiness) {
            form.category = lineOfBusiness;
        }
    },
);

function openEditProductSheet(product) {
    Object.assign(form, {
        name: product.name || '',
        description: product.description || '',
        category: product.category || '',
        brand: product.brand || '',
        condition: product.condition || '',
        specifications: {
            ...blankSpecifications(),
            ...(product.specifications || {}),
        },
        low_stock_threshold: product.low_stock_threshold ?? null,
        price: product.price ?? null,
        compare_price: product.compare_price ?? null,
        promo_code: product.promo_code || '',
        stock: product.stock ?? null,
        images: Array.isArray(product.images) ? [...product.images] : [],
        options: Array.isArray(product.options)
            ? product.options.map((o) => ({
                  name: o.name,
                  values: o.values.map((v) => v.value),
              }))
            : [],
        variants: Array.isArray(product.variants)
            ? product.variants.map((v) => ({
                  option_values: { ...v.option_values },
                  stock: v.stock ?? 0,
                  status: v.status || 'active',
              }))
            : [],
    });
    activeProductId.value = product.id;
    formSnapshot.value = JSON.stringify(form);
}

function closeSheet() {
    activeProductId.value = null;
    saveError.value = '';
    showDiscardConfirm.value = false;
    showSaveConfirm.value = false;
}

// ---- cancel / save confirmation ----
// Reuses the same .modal-* pattern as the Archive confirm dialog. A
// discard prompt only appears when something was actually typed/changed
// (compared against the snapshot taken the moment the sheet opened), so
// closing an untouched form doesn't nag the seller for no reason.
const formSnapshot = ref('');
const showDiscardConfirm = ref(false);
const showSaveConfirm = ref(false);

const isFormDirty = computed(() => JSON.stringify(form) !== formSnapshot.value);

function handleCancelClick() {
    if (isFormDirty.value) {
        showDiscardConfirm.value = true;
    } else {
        closeSheet();
    }
}

function confirmDiscard() {
    showDiscardConfirm.value = false;
    closeSheet();
}

function handleSaveClick() {
    if (!formIsValid.value) {
        return;
    }

    showSaveConfirm.value = true;
}

async function confirmSave() {
    showSaveConfirm.value = false;
    await handleSave();
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
    if (!formIsValid.value) {
        return;
    }

    // options/status/category are trimmed/cleaned client-side for a tidy
    // payload, but nothing here is trusted as-is — SellerProductService
    // re-derives category from the seller's line_of_business, forces
    // status to pending_review, and re-validates every option/variant
    // value server-side regardless of what's sent.
    const payload = {
        ...form,
        options: form.options
            .filter((o) => o.name.trim() && o.values.length > 0)
            .map((o) => ({ name: o.name.trim(), values: o.values })),
        variants: form.variants.map((v) => ({
            option_values: v.option_values,
            // Price isn't editable per-variant here — a variant's
            // selling price always mirrors the product's own price,
            // which is set in the Pricing & Inventory section above.
            price: form.price,
            stock: v.stock ?? 0,
            status: v.status || 'active',
        })),
    };

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