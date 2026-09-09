<!-- resources/js/seller/components/Inventory.vue -->
<template>
    <div class="inventory-page">
        <!-- ============================================================
         STAT BAR — real catalog health, unfiltered by the search/status
         filters below (so it always reflects the whole store, not just
         whatever's currently visible in the grid).
         ============================================================ -->
        <div class="inv-stat-bar">
            <div class="inv-stat-seg">
                <span class="inv-stat-ic neutral">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 9 5-9 5-9-5 9-5Z" /><path d="m3 12 9 5 9-5" /><path d="m3 17 9 5 9-5" /></svg>
                </span>
                <div><div class="inv-stat-v">{{ totalProductsCount }}</div><div class="inv-stat-l">Total Products</div></div>
            </div>
            <div class="inv-stat-seg">
                <span class="inv-stat-ic good">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 3H5a2 2 0 0 0-2 2v6l8.6 8.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8L11 3Z" /><circle cx="7.5" cy="7.5" r="1" /></svg>
                </span>
                <div><div class="inv-stat-v">{{ formatPrice(inventoryValue) }}</div><div class="inv-stat-l">Inventory Value</div></div>
            </div>
            <div class="inv-stat-seg">
                <span class="inv-stat-ic warn">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01" /><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>
                </span>
                <div><div class="inv-stat-v">{{ lowStockCount }}</div><div class="inv-stat-l">Low Stock</div></div>
            </div>
            <div class="inv-stat-seg">
                <span class="inv-stat-ic bad">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M15 9l-6 6M9 9l6 6" /></svg>
                </span>
                <div><div class="inv-stat-v">{{ outOfStockCount }}</div><div class="inv-stat-l">Out of Stock</div></div>
            </div>
        </div>

        <!-- ============================================================
         TOOLBAR
         Confirmed via SellerLayout.vue: it already renders the shared
         content-header (title/breadcrumb/notifications/profile) around
         every section. The header's search box is now wired directly to
         this page's real searchQuery (see SellerLayout.vue), so there's
         only ever one working search field — not a decorative shared one
         plus a duplicate real one here.
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
                <div class="filter-btn-wrap">
                    <button
                        ref="filterBtnEl"
                        type="button"
                        class="chip-btn"
                        :class="{ active: showFilterPanel }"
                        @click="showFilterPanel = !showFilterPanel"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 6h16M7 12h10M10 18h4" />
                        </svg>
                        Filter
                        <span v-if="hasActiveInventoryFilters" class="filter-active-dot"></span>
                    </button>

                    <aside v-if="showFilterPanel" ref="filterPanelEl" class="inventory-filters filter-popover">
                        <div class="filter-popover-head">
                            <h4 class="filter-heading" style="margin: 0">Filters</h4>
                            <button
                                v-if="hasActiveInventoryFilters"
                                type="button"
                                class="filter-clear-link"
                                @click="clearFilters"
                            >
                                Clear all
                            </button>
                        </div>
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
                </div>
                <div class="inv-sort-wrap">
                    <label class="inv-sort-label" for="inv-sort-select">Sort:</label>
                    <select
                        id="inv-sort-select"
                        class="inv-sort-select"
                        v-model="sortOption"
                    >
                        <option value="newest">Newest</option>
                        <option value="price_asc">Price: Low to High</option>
                        <option value="price_desc">Price: High to Low</option>
                        <option value="stock_asc">Stock: Low to High</option>
                    </select>
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
        <div v-if="!sheetOpen" class="inventory-layout">
            <div class="inventory-grid-wrap">
                <!-- Confirms a filter actually did something — without this,
                     toggling Stock Status/Price (whether from "Review
                     Stock" or the Filter popover) gave no feedback beyond
                     the grid quietly re-filtering and a small dot
                     appearing on the (closed) Filter button. -->
                <div v-if="hasActiveInventoryFilters" id="inv-stock-filter-bar" class="inv-stock-filter-bar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /><path d="M12 9v4M12 17h.01" /></svg>
                    Showing <b>{{ filteredProducts.length }}</b> product{{ filteredProducts.length === 1 ? '' : 's' }}<template v-if="inventoryFilterSummary"> · {{ inventoryFilterSummary }}</template>
                    <button type="button" class="inv-stock-filter-clear" @click="clearStockAndPriceFilters">Clear all</button>
                </div>

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
                    <p style="font-weight: 700; color: var(--inv-ink-900)">
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
                    <p style="font-weight: 700; color: #f7a49f">
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
                    <p style="font-weight: 700; color: var(--inv-ink-900)">
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
                    <p style="font-weight: 700; color: var(--inv-ink-900)">
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
                                        title="Adjust stock"
                                        @click="openStockModal(product)"
                                    >
                                        <svg
                                            width="14"
                                            height="14"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <rect x="3" y="3" width="14" height="14" rx="2" />
                                            <path d="M10 6.5v7M6.5 10h7" />
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
                                    SKU: {{ productSkuLabel(product) }}
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
                                            {{ effectiveStock(product) }} Qty
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
           INVENTORY INSIGHTS — stock health at a glance, and a
           shortlist of the products that most need restocking. "Review
           Stock" reuses the real Stock Status filter rather than
           duplicating its logic.
           ============================================================ -->
            <aside class="inv-insights">
                <h3 class="inv-insights-title">Inventory Insights</h3>

                <div>
                    <p class="inv-trend-label">Stock Trend</p>
                    <div class="inv-trend-value-row">
                        <span class="inv-trend-value">{{ healthyPct }}% Healthy</span>
                        <span v-if="stockTrendDelta != null" class="inv-trend-badge" :class="stockTrendBadge.cls">{{ stockTrendBadge.arrow }}{{ stockTrendBadge.label }}</span>
                        <span v-else class="inv-trend-badge warn">{{ stockTrendBadge.label }}</span>
                    </div>

                    <div v-if="totalProductsCount === 0" class="inv-donut-empty">
                        No products yet.
                    </div>
                    <div v-else-if="isLoadingStockTrend && !stockTrend.length" class="inv-trend-chart-skeleton" aria-hidden="true"></div>
                    <div v-else-if="stockTrendError" class="inv-donut-empty">
                        {{ stockTrendError }}
                        <button type="button" class="inv-trend-retry" @click="loadStockTrend()">Try again</button>
                    </div>
                    <div v-else class="inv-trend-chart-wrap">
                        <canvas ref="stockTrendCanvasEl" role="img" aria-label="In stock, low stock, and out of stock product counts over the last 7 days"></canvas>
                    </div>

                    <div class="inv-trend-legend">
                        <div class="inv-tl-row">
                            <span class="inv-tl-key"><span class="inv-tl-dot good"></span>In Stock</span>
                            <span class="inv-tl-val">{{ totalProductsCount - lowStockCount - outOfStockCount }}</span>
                        </div>
                        <div class="inv-tl-row">
                            <span class="inv-tl-key"><span class="inv-tl-dot warn"></span>Low Stock</span>
                            <span class="inv-tl-val">{{ lowStockCount }}</span>
                        </div>
                        <div class="inv-tl-row">
                            <span class="inv-tl-key"><span class="inv-tl-dot bad"></span>Out of Stock</span>
                            <span class="inv-tl-val">{{ outOfStockCount }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <p class="inv-attn-label">Needs attention</p>
                    <div v-if="needsAttentionItems.length" class="inv-attn-list">
                        <button
                            v-for="p in needsAttentionItems"
                            :key="p.id"
                            type="button"
                            class="inv-attn-item"
                            @click="openEditProductSheet(p)"
                        >
                            <span class="inv-attn-thumb">
                                <img v-if="p.images?.[0]?.url" :src="p.images[0].url" :alt="p.name" />
                                <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></svg>
                            </span>
                            <span class="inv-attn-info">
                                <span class="inv-attn-name">{{ p.name }}</span>
                                <span class="inv-attn-meta" :class="effectiveStock(p) === 0 ? 'bad' : 'warn'">{{
                                    effectiveStock(p) === 0 ? 'Out of Stock' : 'Low Stock'
                                }}</span>
                            </span>
                            <span class="inv-attn-units">{{ effectiveStock(p) }} units</span>
                        </button>
                    </div>
                    <p v-else class="inv-attn-empty">Every product is well stocked.</p>
                </div>

                <button type="button" class="inv-review-btn" @click="reviewStock">
                    Review stock
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </aside>
        </div>

        <!-- ============================================================
         ADD / EDIT PRODUCT — a full page takeover (not a modal),
         replacing the toolbar/grid/insights above while open. Same
         activeProductId/form state as before; only the container
         changed, so Cancel/Save/discard-confirm behavior is untouched.
         Sections are grouped into icon-badged cards for a clean
         scan-path. No fake multi-step wizard: this form isn't actually
         paginated, so a step indicator would just be decorative.
         ============================================================ -->
        <div v-else class="product-page">
            <div class="product-page-header">
                <div>
                    <button
                        type="button"
                        class="product-page-back"
                        @click="handleCancelClick"
                    >
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12.5 4 6 10l6.5 6" />
                        </svg>
                        Back to Products &amp; Inventory
                    </button>
                    <h1 class="product-page-title">
                        {{ isNewProduct ? 'Add New Product' : 'Edit Product' }}
                    </h1>
                    <p class="product-page-sub">
                        <template v-if="sheetLoading">Loading full product…</template>
                        <template v-else-if="isNewProduct"
                            >List a new item in your
                            {{ form.category || 'store' }} catalog.</template
                        >
                        <template v-else
                            >Editing — saving will resend this listing for
                            review.</template
                        >
                    </p>
                </div>
                <div class="product-page-actions">
                    <button
                        class="btn-outline"
                        @click="handleCancelClick"
                        :disabled="isSaving"
                    >
                        Cancel
                    </button>
                    <button
                        class="btn-primary"
                        @click="handleSaveClick"
                        :disabled="isSaving || !formIsValid"
                        :title="saveDisabledReason"
                    >
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2">
                            <path d="M4 10.5l4 4 8-9" />
                        </svg>
                        {{
                            isSaving
                                ? 'Saving…'
                                : isNewProduct
                                    ? 'Add Product'
                                    : 'Save Changes'
                        }}
                    </button>
                </div>
            </div>

            <div class="product-page-grid">
                <div class="product-page-main custom-scrollbar">
                        <!-- ============================================
                         GENERAL INFORMATION
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
                                    <h3>General Information</h3>
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
                         CATEGORY (read-only)
                         ============================================ -->
                        <section class="ps-section">
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
                                    <h3>Category</h3>
                                </div>
                            </div>

                            <div class="ps-section-card">
                                <div class="ps-category-chip">
                                    <span>{{ form.category || 'Not set' }}</span>
                                </div>
                                <p class="field-hint">
                                    Automatically set from your seller
                                    registration (Line of Business) — this
                                    can't be changed here.
                                </p>

                                <!-- Some categories cover different enough
                                     products (e.g. Pet Supplies: food vs
                                     toys vs accessories) that specs/variant
                                     options depend on this, per product —
                                     see CategoryFieldConfig's docblock. -->
                                <div v-if="availableSubcategories.length" style="margin-top: 0.9rem">
                                    <label class="field-label"
                                        >What kind of {{ form.category }} product is this?
                                        <span style="color: #dc2626">*</span></label
                                    >
                                    <select
                                        class="field-input"
                                        v-model="form.subcategory"
                                        @change="onSubcategoryChange"
                                    >
                                        <option value="" disabled>Choose one</option>
                                        <option v-for="s in availableSubcategories" :key="s" :value="s">
                                            {{ s }}
                                        </option>
                                    </select>
                                    <p class="field-hint">
                                        Changes which specification fields
                                        and variant options apply below.
                                    </p>
                                </div>
                            </div>
                        </section>

                        <p v-if="saveError" class="save-msg error">
                            {{ saveError }}
                        </p>
                </div>

                <div class="product-page-side">
                        <!-- ============================================
                         UPLOAD IMAGE
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
                                    <h3>Upload Image</h3>
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

                </div>
            </div>

            <div class="product-page-full">
                        <!-- ============================================
                         VARIANTS
                         Every product needs at least one variant now —
                         price/stock/low-stock threshold all live per
                         variant (see the removed Pricing & Inventory
                         section this replaced). Selling just one option,
                         or none at all, still means adding exactly one
                         variant below: pick values if relevant, then
                         click Add Variant — a "solo" product is just a
                         product with one variant row. Option types/values
                         are constrained to what's relevant for the
                         seller's own category (categoryConfig) — never
                         free-typed, except the rare field marked
                         free-text in the template (e.g. Model).
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
                                        <template v-else>
                                            Every product needs at least
                                            one variant, even if it only
                                            comes one way. Pick a value
                                            per option below (or none, for
                                            a product with no real
                                            options), set its price, and
                                            click Add Variant. Repeat to
                                            add more.
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <div class="ps-section-card">
                                <div
                                    v-for="option in form.options"
                                    :key="option.name"
                                    class="variant-group"
                                >
                                    <div class="variant-group-header">
                                        <span class="variant-group-name">{{ option.name }}</span>
                                        <span class="variant-group-hint">{{ optionHint(option.name) }}</span>
                                        <button
                                            v-if="!categoryOptionDef(option.name)"
                                            type="button"
                                            class="variant-group-remove"
                                            @click="removeCustomOption(option.name)"
                                        >
                                            Remove
                                        </button>
                                    </div>

                                    <!-- One value at a time — this is what's about to be added
                                         as ONE variant (see the staging bar below), not a
                                         multi-select. Values already used by a variant added
                                         earlier (or, when editing, already saved) show up here
                                         as quick-pick choices alongside the category's own
                                         presets, via displayValuesFor(). -->
                                    <div class="variant-radio-row">
                                        <label
                                            v-for="val in displayValuesFor(option)"
                                            :key="val"
                                            class="variant-radio"
                                            :class="{ selected: stagingValues[option.name] === val }"
                                        >
                                            <input
                                                type="radio"
                                                :name="`variant-${option.name}`"
                                                :checked="stagingValues[option.name] === val"
                                                @change="setSingleValue(option, val)"
                                            />
                                            <span>{{ val }}</span>
                                            <button
                                                v-if="isCustomValue(option, val)"
                                                type="button"
                                                class="variant-pill-remove"
                                                @click.stop.prevent="removeCustomValue(option, val)"
                                            >
                                                ×
                                            </button>
                                        </label>

                                        <label class="variant-radio variant-radio-other" :class="{ selected: isOtherActiveFor(option) }">
                                            <input
                                                type="radio"
                                                :name="`variant-${option.name}`"
                                                :checked="isOtherActiveFor(option)"
                                                @change="openOther(option.name)"
                                            />
                                            <span>Other</span>
                                        </label>
                                        <span v-if="otherOpen[option.name]" class="variant-other-input">
                                            <input
                                                type="text"
                                                v-model="otherDraft[option.name]"
                                                placeholder="Type a value…"
                                                autofocus
                                                @keydown.enter.prevent="commitOther(option)"
                                                @blur="commitOther(option)"
                                            />
                                        </span>
                                    </div>
                                </div>

                                <div class="variant-add-option">
                                    <button
                                        v-if="!showAddCustomOption"
                                        type="button"
                                        class="btn-outline"
                                        style="padding: 6px 14px; font-size: 12px"
                                        @click="openAddCustomOption"
                                    >
                                        + Add Custom Option
                                    </button>
                                    <div v-else class="variant-add-option-form">
                                        <input
                                            type="text"
                                            class="field-input"
                                            v-model="newCustomOptionName"
                                            placeholder="e.g. Packaging"
                                            autofocus
                                            @keydown.enter.prevent="confirmAddCustomOption"
                                        />
                                        <button type="button" class="btn-primary" style="padding: 6px 14px; font-size: 12px" @click="confirmAddCustomOption">
                                            Add
                                        </button>
                                        <button type="button" class="btn-outline" style="padding: 6px 14px; font-size: 12px" @click="cancelAddCustomOption">
                                            Cancel
                                        </button>
                                    </div>
                                    <p v-if="customOptionError" class="save-msg error">{{ customOptionError }}</p>
                                </div>

                                <datalist id="variant-discount-types">
                                    <option v-for="t in DISCOUNT_TYPES" :key="t" :value="t" />
                                </datalist>

                                <div v-if="form.options.length" class="variant-staging-bar">
                                    <div class="variant-staging-summary">
                                        <span v-if="stagingSummaryText">Adding: {{ stagingSummaryText }}</span>
                                        <span v-else class="variant-staging-empty">Adding: a single variant with no specific options.</span>
                                        <button
                                            v-if="stagingSummaryText"
                                            type="button"
                                            class="variant-staging-clear"
                                            @click="clearStaging"
                                        >
                                            Clear
                                        </button>
                                    </div>

                                    <div class="variant-staging-fields">
                                        <div class="variant-image-slot">
                                            <label class="variant-image-upload">
                                                <input
                                                    type="file"
                                                    accept="image/*"
                                                    style="display: none"
                                                    @change="handleVariantImageUpload($event, stagingVariant)"
                                                />
                                                <img v-if="stagingVariant.image?.url" :src="stagingVariant.image.url" alt="" />
                                                <svg
                                                    v-else
                                                    width="18"
                                                    height="18"
                                                    viewBox="0 0 20 20"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                >
                                                    <path d="M10 13V5M6.5 8.5 10 5l3.5 3.5" />
                                                    <path d="M4 13v2a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-2" />
                                                </svg>
                                            </label>
                                            <button
                                                v-if="stagingVariant.image?.url"
                                                type="button"
                                                class="variant-image-remove"
                                                aria-label="Remove variant image"
                                                @click="stagingVariant.image = null"
                                            >
                                                ×
                                            </button>
                                        </div>
                                        <div class="currency-input">
                                            <span>₱</span>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="field-input"
                                                v-model.number="stagingVariant.price"
                                                placeholder="Price"
                                                required
                                            />
                                        </div>
                                        <div class="percent-input">
                                            <input
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="1"
                                                class="field-input"
                                                v-model.number="stagingVariant.discount_percent"
                                                placeholder="0"
                                            />
                                            <span>%</span>
                                        </div>
                                        <p v-if="variantDiscountedPrice(stagingVariant) !== null" class="variant-discount-preview">
                                            → ₱{{ variantDiscountedPrice(stagingVariant) }}
                                        </p>
                                        <input
                                            type="text"
                                            class="field-input"
                                            list="variant-discount-types"
                                            v-model="stagingVariant.discount_type"
                                            placeholder="Discount type"
                                        />
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            class="field-input"
                                            v-model.number="stagingVariant.stock"
                                            placeholder="Stock"
                                        />
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            class="field-input"
                                            v-model.number="stagingVariant.low_stock_threshold"
                                            placeholder="Low-stock at"
                                            title="Low-stock warning threshold for this variant — leave blank to use the app default (10)"
                                        />
                                        <select class="field-input" v-model="stagingVariant.status">
                                            <option value="active">Active</option>
                                            <option value="unavailable">Unavailable</option>
                                        </select>
                                        <button
                                            type="button"
                                            class="btn-primary"
                                            :disabled="!canAddVariant"
                                            :title="canAddVariant ? '' : 'Enter a price for this variant'"
                                            @click="addVariant"
                                        >
                                            + Add Variant
                                        </button>
                                    </div>
                                    <p v-if="addVariantError" class="save-msg error">{{ addVariantError }}</p>
                                </div>

                                <p v-if="form.variants.length" class="variant-group-name" style="margin-top: 0.4rem">
                                    Added Variants ({{ form.variants.length }})
                                </p>
                                <!-- Cards, not a table: with this many fields per
                                     variant (price, discount, discount type,
                                     stock, low-stock, status, image) a table
                                     just forces horizontal scrolling to see the
                                     last few columns. Fields wrap naturally
                                     instead — nothing is ever clipped. -->
                                <div v-if="form.variants.length" class="variant-list">
                                    <div
                                        v-for="(variant, vi) in form.variants"
                                        :key="vi"
                                        class="variant-card"
                                    >
                                        <div class="variant-card-header">
                                            <span class="variant-card-title">{{ variantLabel(variant) }}</span>
                                            <button
                                                type="button"
                                                class="variant-row-remove"
                                                :aria-label="`Remove ${variantLabel(variant)}`"
                                                title="Remove this combination"
                                                @click="removeVariantRow(vi)"
                                            >
                                                ×
                                            </button>
                                        </div>

                                        <div class="variant-card-body">
                                            <div class="variant-image-slot">
                                                <label class="variant-image-upload">
                                                    <input
                                                        type="file"
                                                        accept="image/*"
                                                        style="display: none"
                                                        @change="handleVariantImageUpload($event, variant)"
                                                    />
                                                    <img v-if="variant.image?.url" :src="variant.image.url" alt="" />
                                                    <svg
                                                        v-else
                                                        width="18"
                                                        height="18"
                                                        viewBox="0 0 20 20"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="1.6"
                                                    >
                                                        <path d="M10 13V5M6.5 8.5 10 5l3.5 3.5" />
                                                        <path d="M4 13v2a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-2" />
                                                    </svg>
                                                </label>
                                                <button
                                                    v-if="variant.image?.url"
                                                    type="button"
                                                    class="variant-image-remove"
                                                    aria-label="Remove variant image"
                                                    @click="variant.image = null"
                                                >
                                                    ×
                                                </button>
                                            </div>

                                            <label class="variant-field">
                                                <span class="variant-field-label">SKU</span>
                                                <span class="variant-sku-display" :title="variant.sku ? '' : 'Assigned automatically once this product is saved'">{{ variant.sku || 'Assigned on save' }}</span>
                                            </label>

                                            <label class="variant-field">
                                                <span class="variant-field-label">Price <span style="color: #dc2626">*</span></span>
                                                <div class="currency-input">
                                                    <span>₱</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="field-input"
                                                        v-model.number="variant.price"
                                                        placeholder="Price"
                                                        required
                                                    />
                                                </div>
                                            </label>

                                            <label class="variant-field">
                                                <span class="variant-field-label">Discount</span>
                                                <div class="percent-input">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max="100"
                                                        step="1"
                                                        class="field-input"
                                                        v-model.number="variant.discount_percent"
                                                        placeholder="0"
                                                    />
                                                    <span>%</span>
                                                </div>
                                                <p v-if="variantDiscountedPrice(variant) !== null" class="variant-discount-preview">
                                                    → ₱{{ variantDiscountedPrice(variant) }}
                                                </p>
                                            </label>

                                            <label class="variant-field">
                                                <span class="variant-field-label">Discount Type</span>
                                                <input
                                                    type="text"
                                                    class="field-input"
                                                    list="variant-discount-types"
                                                    v-model="variant.discount_type"
                                                    placeholder="None"
                                                />
                                            </label>

                                            <label class="variant-field">
                                                <span class="variant-field-label">Stock</span>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    class="field-input"
                                                    v-model.number="variant.stock"
                                                />
                                            </label>

                                            <label class="variant-field">
                                                <span class="variant-field-label">Low-Stock At</span>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    class="field-input"
                                                    v-model.number="variant.low_stock_threshold"
                                                    placeholder="10"
                                                />
                                            </label>

                                            <label class="variant-field">
                                                <span class="variant-field-label">Status</span>
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
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

            </div>
        </div>

            <!-- ============================================================
             DISCARD CHANGES CONFIRM (reuses .modal-* classes; renders on
             top of the product modal's own overlay)
             ============================================================ -->
            <Transition name="modal-fade">
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
            </Transition>

            <!-- ============================================================
             SAVE CHANGES CONFIRM (reuses .modal-* classes)
             ============================================================ -->
            <Transition name="modal-fade">
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
            </Transition>

        <!-- ============================================================
         DELETE CONFIRM MODAL (reuses existing .modal-* classes)
         ============================================================ -->
        <Transition name="modal-fade">
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
        </Transition>

        <!-- ============================================================
         STOCK ADJUSTMENT + MOVEMENT HISTORY MODAL
         ============================================================ -->
        <Transition name="modal-fade">
        <div
            v-if="stockModalOpen && stockModalProduct"
            class="modal-overlay"
            @click.self="closeStockModal"
        >
            <div class="modal-panel stock-modal">
                <div class="modal-header">
                    <h3>{{ stockModalProduct.name }}</h3>
                    <button class="modal-close" @click="closeStockModal">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 5l10 10M15 5 5 15" />
                        </svg>
                    </button>
                </div>

                <div class="stock-tabs">
                    <button
                        :class="{ active: stockModalTab === 'adjust' }"
                        @click="stockModalTab = 'adjust'"
                    >
                        Adjust stock
                    </button>
                    <button
                        :class="{ active: stockModalTab === 'history' }"
                        @click="stockModalTab = 'history'"
                    >
                        History
                    </button>
                </div>

                <!-- ADJUST -->
                <div v-if="stockModalTab === 'adjust'" class="stock-adjust">
                    <div
                        v-if="stockModalProduct.has_variants && stockModalProduct.variants?.length"
                        class="stock-field"
                    >
                        <label>Variant</label>
                        <select v-model="stockForm.variantId">
                            <option
                                v-for="v in stockModalProduct.variants"
                                :key="v.id"
                                :value="v.id"
                            >
                                {{ Object.values(v.option_values || {}).join(' / ') || v.sku || 'Variant' }}
                                — {{ v.stock }} in stock
                            </option>
                        </select>
                    </div>

                    <div class="stock-current">
                        <span>Current</span>
                        <strong>{{ stockModalCurrentQty }}</strong>
                        <span class="stock-arrow">→</span>
                        <strong
                            :class="{ 'is-negative': stockModalResultingQty < 0 }"
                        >{{ stockModalResultingQty }}</strong>
                    </div>

                    <div class="stock-field stock-qty-row">
                        <div class="stock-dir-toggle">
                            <button
                                type="button"
                                :class="{ active: stockForm.direction === 'add' }"
                                @click="stockForm.direction = 'add'"
                            >
                                + Add
                            </button>
                            <button
                                type="button"
                                :class="{ active: stockForm.direction === 'remove' }"
                                @click="stockForm.direction = 'remove'"
                            >
                                − Remove
                            </button>
                        </div>
                        <input
                            v-model.number="stockForm.quantity"
                            type="number"
                            min="1"
                            step="1"
                            aria-label="Quantity"
                        />
                    </div>

                    <div class="stock-field">
                        <label>Reason</label>
                        <select v-model="stockForm.reason">
                            <option
                                v-for="r in ADJUST_REASONS"
                                :key="r.value"
                                :value="r.value"
                            >
                                {{ r.label }}
                            </option>
                        </select>
                    </div>

                    <div class="stock-field">
                        <label>Note <span class="stock-optional">(optional)</span></label>
                        <textarea
                            v-model="stockForm.note"
                            rows="2"
                            maxlength="500"
                            placeholder="e.g. supplier delivery #1204"
                        ></textarea>
                    </div>

                    <p v-if="stockAdjustError" class="stock-error">{{ stockAdjustError }}</p>

                    <div class="modal-actions">
                        <button class="btn-outline" style="flex: 1" @click="closeStockModal">
                            Cancel
                        </button>
                        <button
                            class="btn-primary"
                            style="flex: 1"
                            :disabled="stockAdjusting"
                            @click="submitStockAdjustment"
                        >
                            {{ stockAdjusting ? 'Saving…' : 'Apply adjustment' }}
                        </button>
                    </div>
                </div>

                <!-- HISTORY -->
                <div v-else class="stock-history">
                    <p v-if="stockMovementsLoading" class="stock-history-empty">Loading…</p>
                    <p v-else-if="!stockMovements.length" class="stock-history-empty">
                        No stock movements recorded yet.
                    </p>
                    <ul v-else class="stock-history-list">
                        <li v-for="m in stockMovements" :key="m.id">
                            <div class="stock-history-row">
                                <span class="stock-history-type">{{ movementLabel(m) }}</span>
                                <span
                                    class="stock-history-delta"
                                    :class="m.quantityChange >= 0 ? 'up' : 'down'"
                                >{{ m.quantityChange >= 0 ? '+' : '' }}{{ m.quantityChange }}</span>
                            </div>
                            <div class="stock-history-meta">
                                {{ m.quantityBefore }} → {{ m.quantityAfter }}
                                <template v-if="m.variantLabel"> · {{ m.variantLabel }}</template>
                                <template v-if="m.orderNumber"> · Order {{ m.orderNumber }}</template>
                                · {{ m.actor }} · {{ movementWhen(m.createdAt) }}
                            </div>
                            <div v-if="m.note" class="stock-history-note">{{ m.note }}</div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        </Transition>

    </div>
</template>

<script setup>
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { useSeller } from '../composables/useSeller';
import { useSellerProducts } from '../composables/useSellerProducts';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend, Filler);

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
    sortOption,

    currentPage,
    totalPages,
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
    loadCategoryConfig,

    stockTrend,
    isLoadingStockTrend,
    stockTrendError,
    loadStockTrend,

    effectiveStock,
    stockStatusOf,
    formatPrice,
    statusBadgeClass,
    statusLabel,
    stockBarClass,
    stockBarWidth,
} = useSellerProducts();

const { sellerDetails } = useSeller();

// ---- Inventory stat bar + Insights panel — derived from the seller's
// whole catalog (`products`), not `filteredProducts`, so these numbers
// stay stable while the seller searches/filters the grid below them.
const totalProductsCount = computed(() => products.value.length);
const lowStockCount = computed(
    () => products.value.filter((p) => stockStatusOf(p) === 'low_stock').length,
);
const outOfStockCount = computed(
    () => products.value.filter((p) => stockStatusOf(p) === 'out_of_stock').length,
);
const inventoryValue = computed(() =>
    products.value.reduce((sum, p) => sum + (Number(p.price) || 0) * effectiveStock(p), 0),
);
const healthyPct = computed(() => {
    const total = totalProductsCount.value;

    if (total === 0) {
        return 100;
    }

    const unhealthy = lowStockCount.value + outOfStockCount.value;

    return Math.round(((total - unhealthy) / total) * 100);
});

// A real week-over-week delta, not a fabricated one: reconstructed from
// inventory_movements (see SellerInventoryController::stockTrend), which
// logs a real quantity_after for every stock change including the
// initial_stock row a product gets on creation — so "how many products
// were in/low/out of stock N days ago" is genuinely computable, just not
// from a dedicated snapshot table. Compares the oldest vs newest day the
// fetched window actually has a known reading for (a brand-new catalog
// may not have 7 full days yet).
const stockTrendDelta = computed(() => {
    const known = stockTrend.value.filter((d) => d.healthyPct != null);

    if (known.length < 2) {
        return null;
    }

    return Math.round((known[known.length - 1].healthyPct - known[0].healthyPct) * 10) / 10;
});

const stockTrendBadge = computed(() => {
    const delta = stockTrendDelta.value;

    if (delta == null) {
        return { label: 'Not enough history yet', cls: 'warn', arrow: '' };
    }

    if (delta > 0) {
        return { label: `${delta}%`, cls: 'good', arrow: '↑' };
    }

    if (delta < 0) {
        return { label: `${Math.abs(delta)}%`, cls: 'bad', arrow: '↓' };
    }

    return { label: '0%', cls: 'warn', arrow: '→' };
});

// ---- Stock Trend line chart (In Stock / Low Stock / Out of Stock) ----
const stockTrendCanvasEl = ref(null);
let stockTrendChart = null;

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

function renderStockTrendChart() {
    if (!stockTrendCanvasEl.value || !stockTrend.value.length) {
        return;
    }

    const labels = stockTrend.value.map((d) => d.label);

    stockTrendChart?.destroy();
    stockTrendChart = new Chart(stockTrendCanvasEl.value, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'In Stock',
                    data: stockTrend.value.map((d) => d.inStock),
                    borderColor: '#14b8a6',
                    backgroundColor: 'rgba(20, 184, 166, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2.5,
                },
                {
                    label: 'Low Stock',
                    data: stockTrend.value.map((d) => d.lowStock),
                    borderColor: '#fbbf7d',
                    backgroundColor: 'transparent',
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                },
                {
                    label: 'Out of Stock',
                    data: stockTrend.value.map((d) => d.outOfStock),
                    borderColor: '#f7a49f',
                    backgroundColor: 'transparent',
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: prefersReducedMotion() ? false : { duration: 350 },
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#6d766e', font: { size: 10 } } },
                y: { display: false, beginAtZero: true },
            },
        },
    });
}

// Watches the canvas ref too, not just the data — the canvas only exists
// once loading/error/empty states clear, so either becoming ready must
// still be able to trigger the first real draw (same fix Reports.vue's
// Weekly Fulfillment chart needed).
watch([stockTrend, stockTrendCanvasEl], () => nextTick(renderStockTrendChart));

// Worst-stocked first, so the seller sees what needs restocking soonest.
const needsAttentionItems = computed(() =>
    products.value
        .filter((p) => ['low_stock', 'out_of_stock'].includes(stockStatusOf(p)))
        .slice()
        .sort((a, b) => effectiveStock(a) - effectiveStock(b))
        .slice(0, 4),
);

// Reuses the real Stock Status filter (the same checkboxes in the
// sidebar) instead of maintaining a second, parallel filtering path.
function reviewStock() {
    selectedStockStatuses.value = ['low_stock', 'out_of_stock'];
}

// Clears exactly what the active-filter bar above the grid describes
// (Stock Status + Price) — not clearFilters(), which also resets search
// the seller may have typed separately and wouldn't expect this button
// to touch.
function clearStockAndPriceFilters() {
    selectedStockStatuses.value = [];
    priceMin.value = PRICE_MIN_BOUND;
    priceMax.value = priceCeiling.value;
}

onMounted(async () => {
    // loadProducts() is deduplicated and also watches for a late-arriving
    // seller session, so this is safe even if the composable already started
    // the request before this component mounted. Product cards get network
    // priority; the form-only category config loads immediately afterward.
    await loadProducts();
    void loadCategoryConfig();
    void loadStockTrend();

    document.addEventListener('click', onFilterPanelDocClick);
    document.addEventListener('keydown', onFilterPanelEscKey);
});

// Same 30s poll rhythm as Orders.vue/Dashboard.vue — a restock or a
// buyer purchase draining stock elsewhere shouldn't need a page reload
// to show up here. The Add/Edit sheet works off its own copy of a
// product (see openEditProductSheet()), so a background refresh of the
// list can't clobber an in-progress edit.
const INVENTORY_POLL_MS = 30 * 1000;
let inventoryPollTimer = null;

onMounted(() => {
    inventoryPollTimer = setInterval(() => loadProducts(), INVENTORY_POLL_MS);
});

onBeforeUnmount(() => {
    clearInterval(inventoryPollTimer);
});

// ---- Filter popover (Stock Status + Price Range, shown on demand
// instead of a permanent sidebar — closes on outside click or Escape) ----
const showFilterPanel = ref(false);
const filterBtnEl = ref(null);
const filterPanelEl = ref(null);

function onFilterPanelDocClick(event) {
    if (!showFilterPanel.value) {
        return;
    }

    if (
        filterPanelEl.value &&
        !filterPanelEl.value.contains(event.target) &&
        filterBtnEl.value &&
        !filterBtnEl.value.contains(event.target)
    ) {
        showFilterPanel.value = false;
    }
}

function onFilterPanelEscKey(event) {
    if (event.key === 'Escape') {
        showFilterPanel.value = false;
    }
}

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
    document.removeEventListener('click', onFilterPanelDocClick);
    document.removeEventListener('keydown', onFilterPanelEscKey);
    stockTrendChart?.destroy();
});

function clearFilters() {
    searchQuery.value = '';
    selectedStockStatuses.value = [];
    priceMin.value = PRICE_MIN_BOUND;
    priceMax.value = priceCeiling.value;
}

// Drives the small dot on the Filter button — only Stock Status/Price
// count as "active filters" here, not the separate search box.
const hasActiveInventoryFilters = computed(
    () =>
        selectedStockStatuses.value.length > 0 ||
        priceMin.value > PRICE_MIN_BOUND ||
        priceMax.value < priceCeiling.value,
);

// Human-readable "what's actually filtered" for the active-filter bar,
// e.g. "Low Stock, Out of Stock · ₱100–₱500" — built from the same state
// hasActiveInventoryFilters checks, so the two can never disagree.
const inventoryFilterSummary = computed(() => {
    const parts = [];

    if (selectedStockStatuses.value.length) {
        parts.push(
            stockStatusOptions
                .filter((o) => selectedStockStatuses.value.includes(o.value))
                .map((o) => o.label)
                .join(', '),
        );
    }

    if (priceMin.value > PRICE_MIN_BOUND || priceMax.value < priceCeiling.value) {
        parts.push(`₱${priceMin.value}–₱${priceMax.value}`);
    }

    return parts.join(' · ');
});

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
/* ----------------------------------------------------------------
 | Manual stock adjustment + movement history
 | Adds/subtracts a signed quantity (never replaces), with a required
 | reason; every change is recorded server-side (InventoryService).
 ---------------------------------------------------------------- */
const stockModalOpen = ref(false);
const stockModalProduct = ref(null);
const stockModalTab = ref('adjust'); // 'adjust' | 'history'
const stockAdjusting = ref(false);
const stockAdjustError = ref('');
const stockMovements = ref([]);
const stockMovementsLoading = ref(false);

const stockForm = reactive({
    variantId: '',
    direction: 'add', // 'add' | 'remove'
    quantity: 1,
    reason: 'restock',
    note: '',
});

function openStockModal(product) {
    stockModalProduct.value = product;
    stockModalTab.value = 'adjust';
    stockAdjustError.value = '';
    stockMovements.value = [];
    Object.assign(stockForm, {
        variantId:
            product.has_variants && product.variants?.length ? product.variants[0].id : '',
        direction: 'add',
        quantity: 1,
        reason: 'restock',
        note: '',
    });
    stockModalOpen.value = true;
}

function closeStockModal() {
    stockModalOpen.value = false;
    stockModalProduct.value = null;
}

const stockModalCurrentQty = computed(() => {
    const product = stockModalProduct.value;

    if (!product) {
        return 0;
    }

    if (product.has_variants && stockForm.variantId) {
        const v = (product.variants || []).find((x) => x.id === stockForm.variantId);

        return Number(v?.stock ?? 0);
    }

    return effectiveStock(product);
});

const stockModalResultingQty = computed(() => {
    const delta =
        (stockForm.direction === 'remove' ? -1 : 1) * Math.abs(Number(stockForm.quantity) || 0);

    return stockModalCurrentQty.value + delta;
});

async function submitStockAdjustment() {
    const product = stockModalProduct.value;

    if (!product || stockAdjusting.value) {
        return;
    }

    const qty = Math.abs(Number(stockForm.quantity) || 0);

    if (qty < 1) {
        stockAdjustError.value = 'Enter a quantity of 1 or more.';

        return;
    }

    if (stockModalResultingQty.value < 0) {
        stockAdjustError.value = "That would take stock below zero — reduce the quantity.";

        return;
    }

    stockAdjusting.value = true;
    stockAdjustError.value = '';

    try {
        await adjustStock({
            productId: product.id,
            variantId:
                product.has_variants && stockForm.variantId ? stockForm.variantId : null,
            delta: (stockForm.direction === 'remove' ? -1 : 1) * qty,
            reason: stockForm.reason,
            note: stockForm.note.trim() || null,
        });

        // adjustStock() replaces the product object in the list — re-point
        // the modal at the fresh copy so "Current" stays accurate.
        stockModalProduct.value =
            products.value.find((p) => p.id === product.id) || stockModalProduct.value;

        // Keep the modal open on the History tab so the seller sees it land.
        stockForm.quantity = 1;
        stockForm.note = '';
        stockModalTab.value = 'history';
        await loadStockHistory();
    } catch (err) {
        stockAdjustError.value = err?.message || 'Could not adjust the stock.';
    } finally {
        stockAdjusting.value = false;
    }
}

async function loadStockHistory() {
    const product = stockModalProduct.value;

    if (!product) {
        return;
    }

    stockMovementsLoading.value = true;

    try {
        const { data } = await loadMovements(product.id, {
            variantId:
                product.has_variants && stockForm.variantId ? stockForm.variantId : null,
        });

        stockMovements.value = data;
    } catch (err) {
        stockAdjustError.value = err?.message || 'Could not load stock history.';
    } finally {
        stockMovementsLoading.value = false;
    }
}

watch(stockModalTab, (tab) => {
    if (tab === 'history') {
        void loadStockHistory();
    }
});

const MOVEMENT_LABELS = {
    initial_stock: 'Initial stock',
    restock: 'Restock',
    manual_increase: 'Manual add',
    manual_decrease: 'Manual remove',
    damaged: 'Damaged',
    incorrect_count: 'Count correction',
    returned_item: 'Returned item',
    lost_item: 'Lost item',
    form_edit: 'Edited on form',
    sale: 'Sale',
    cancellation_restock: 'Order cancelled',
    return_restock: 'Return approved',
    other: 'Adjustment',
};

function movementLabel(m) {
    return MOVEMENT_LABELS[m.type] || m.type;
}

function movementWhen(iso) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

// A product's own top-level `sku` field is never set anymore (every
// product has real per-variant SKUs instead — see
// SellerProductService::generateVariantSku()), so the list card reads
// straight from `product.variants`: a single-variant ("solo") product
// shows that one real SKU, a multi-variant one shows a count (there's
// no single correct answer for "the" SKU of several different SKUs).
function productSkuLabel(product) {
    const variants = product.variants || [];

    if (variants.length === 1) {
        return variants[0].sku || '—';
    }

    if (variants.length > 1) {
        return `${variants.length} SKUs`;
    }

    return '—';
}

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
const sheetLoading = ref(false); // fetching the full product for the edit sheet

const blankForm = () => ({
    name: '',
    description: '',
    category: '',
    subcategory: '', // required only when categoryConfig.subcategories is non-empty
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
    // sku is READ-ONLY display data, never submitted — the server
    // auto-generates it (see SellerProductService::generateVariantSku()).
    variants: [], // [{ option_values: {Name: value}, sku, price, stock, image, status }]
});
const form = reactive(blankForm());

// Categories with no subcategory concept (CategoryFieldConfig::hasSubcategories()
// false server-side) return [] here — the field simply never renders, and
// nothing extra is required to save.
const availableSubcategories = computed(() => categoryConfig.value?.subcategories || []);

// A variant product prices/stocks itself per variant (see the Product
// Variants section) — the top-level Price/Stock fields only exist, and
// only need filling in, for a simple product with no variants at all.
// Every product needs at least one variant now — there's no more
// product-level Pricing & Inventory section for a "simple" product to
// fall back on. A single-option (or no-option) product just has one
// variant row instead.
const formIsValid = computed(() => {
    if (!form.name.trim() || !form.category.trim()) {
        return false;
    }

    if (availableSubcategories.value.length && !form.subcategory) {
        return false;
    }

    if (!form.variants.length) {
        return false;
    }

    return form.variants.every((v) => v.price !== null && v.price !== '');
});

// Explains *why* Save is disabled instead of leaving sellers to guess at
// a grayed-out button — checked in priority order, most important first.
const saveDisabledReason = computed(() => {
    if (!form.name.trim()) {
        return 'Enter a product name to continue.';
    }

    if (availableSubcategories.value.length && !form.subcategory) {
        return `Choose what kind of ${form.category} product this is.`;
    }

    if (!form.variants.length) {
        return 'Add at least one variant to continue.';
    }

    if (form.variants.some((v) => v.price === null || v.price === '')) {
        return 'Every variant needs a price.';
    }

    return '';
});

// ---- category-constrained option types/values ----
// All sourced from categoryConfig (GET /api/seller/category-config),
// never hardcoded here — a different seller category simply gets a
// different config payload and this same code renders accordingly.
// Every option type the category defines is always shown (Weight, Pet
// Type, Flavor, ...) — there's no "+ Add Option" step for these; a
// seller just leaves one untouched to skip that axis entirely.
//
// Variants are built ONE AT A TIME: a seller picks a single value per
// option below (see stagingValues), optionally sets that variant's own
// price/discount/stock/status, then clicks "Add Variant" (addVariant())
// to commit it as a row in form.variants — then repeats for the next
// combination. This replaced an earlier "pick every value you'll stock,
// then every combination is generated for you" design: with several
// option types that multiplies fast into combinations a seller never
// intended to sell, and there's no clean way to select "one weight AND
// one flavor for this specific listing" from independent multi-selects.

function categoryOptionDef(name) {
    return (categoryConfig.value?.variant_options || []).find(
        (o) => o.name === name,
    );
}

function optionHint(name) {
    return categoryOptionDef(name)?.hint || `Choose a ${name.toLowerCase()} for this variant`;
}

// Keeps form.options in exact 1:1 sync with the category's own option
// list (by name), preserving whatever value HISTORY is already on an
// option that still exists (see displayValuesFor), dropping any that no
// longer do (defensive — the category itself doesn't change under a
// seller's account), and adding a blank entry for one that's newly
// available. Runs whenever the sheet opens and whenever categoryConfig
// finishes loading, so the widgets render correctly regardless of which
// happens first.
//
// Anything in form.options whose name ISN'T one of the category's own
// (a seller-added "+ Add Custom Option" axis, or one loaded from an
// existing product) is left exactly where it is, appended after the
// category-driven ones — reconciliation only manages the fixed set,
// never touches custom entries.
function reconcileFormOptions() {
    const defs = categoryConfig.value?.variant_options || [];
    const defNames = new Set(defs.map((d) => d.name));
    const byName = new Map(form.options.map((o) => [o.name, o]));

    const categoryDriven = defs.map((def) => byName.get(def.name) || { name: def.name, values: [] });
    const custom = form.options.filter((o) => !defNames.has(o.name));

    form.options = [...categoryDriven, ...custom];
}

// The category's own suggested values for an option, in their defined
// order — never includes whatever a seller has typed in via "Other".
function presetValuesFor(name) {
    return categoryOptionDef(name)?.values || [];
}

// Presets first (category order), then any value HISTORY on the option
// that isn't part of the preset list — i.e. values a seller has typed in
// via "Other" and used in at least one variant so far this session, or
// (when editing an existing listing) already saved that way. This is
// what makes the radio group grow quick-pick choices as more variants
// get added, instead of re-typing "Rabbit" every time.
function displayValuesFor(option) {
    const presets = presetValuesFor(option.name);
    const extras = option.values.filter((v) => !presets.includes(v));

    return [...presets, ...extras];
}

function isCustomValue(option, value) {
    return !presetValuesFor(option.name).includes(value);
}

// Picking a value always replaces whatever was staged before for this
// option, and closes its "Other" input if it was open.
function setSingleValue(option, value) {
    stagingValues[option.name] = value;
    otherOpen[option.name] = false;
}

// Removes a value from an option's quick-pick history (the × on a
// non-preset radio choice) — e.g. a typo typed via "Other" that was
// never actually used. Clears it from the current staging pick too if
// that's the one being removed.
function removeCustomValue(option, value) {
    option.values = option.values.filter((v) => v !== value);

    if (stagingValues[option.name] === value) {
        stagingValues[option.name] = '';
    }
}

// ---- "Other" — type a value that isn't in the category's preset list ----
// Keyed by option name so more than one group's input can be open at once.
const otherOpen = reactive({});
const otherDraft = reactive({});

function openOther(name) {
    otherOpen[name] = true;
    otherDraft[name] = '';
}

function closeOther(name) {
    otherOpen[name] = false;
}

// True when the current staged pick for this option is one the seller
// typed in themselves — so the "Other" radio still reads as selected
// after the input is committed and closed again.
function isOtherActiveFor(option) {
    return !!otherOpen[option.name] || (!!stagingValues[option.name] && isCustomValue(option, stagingValues[option.name]));
}

function commitOther(option) {
    const value = (otherDraft[option.name] || '').trim();

    if (value) {
        stagingValues[option.name] = value;

        if (!option.values.includes(value)) {
            option.values.push(value);
        }
    }

    closeOther(option.name);
}

// ---- staging: the single variant currently being configured ----
// stagingValues holds at most one picked value per option name; nothing
// here is "the product's variants" until addVariant() commits it as a
// row in form.variants.
const stagingValues = reactive({});
const stagingVariant = reactive({ price: null, discount_percent: null, discount_type: '', stock: 0, low_stock_threshold: null, status: 'active', image: null });
const addVariantError = ref('');

// e.g. "100g · Chicken · Senior" — what's about to be added if the
// seller clicks "Add Variant" right now. Empty is valid too — a product
// with no real option axes still gets exactly one "solo" variant with no
// option_values, which is where its price/stock actually live now that
// there's no more product-level Pricing & Inventory section.
const stagingSummaryText = computed(() =>
    form.options
        .map((o) => stagingValues[o.name])
        .filter(Boolean)
        .join(' · '),
);

// Only a price is actually required to add a variant — picking option
// values is optional (a "solo" product just adds one variant with no
// option_values at all).
const canAddVariant = computed(() => stagingVariant.price !== null && stagingVariant.price !== '');

function clearStaging() {
    for (const key of Object.keys(stagingValues)) {
        delete stagingValues[key];
    }

    for (const key of Object.keys(otherOpen)) {
        delete otherOpen[key];
    }

    addVariantError.value = '';
}

function addVariant() {
    addVariantError.value = '';

    if (stagingVariant.price === null || stagingVariant.price === '') {
        addVariantError.value = 'Enter a price for this variant.';

        return;
    }

    const optionValues = {};

    for (const opt of form.options) {
        if (stagingValues[opt.name]) {
            optionValues[opt.name] = stagingValues[opt.name];
        }
    }

    const key = comboKey(optionValues);

    if (form.variants.some((v) => comboKey(v.option_values) === key)) {
        addVariantError.value = 'This exact combination has already been added.';

        return;
    }

    // Remember every value just used so it becomes a quick-pick radio
    // choice for the next variant (see displayValuesFor) — matters for
    // values picked via "Other", which commitOther already added, but
    // this covers every path consistently.
    for (const [name, value] of Object.entries(optionValues)) {
        const opt = form.options.find((o) => o.name === name);

        if (opt && !opt.values.includes(value)) {
            opt.values.push(value);
        }
    }

    form.variants.push({
        // No sku: the server assigns one once this variant is actually
        // saved — see SellerProductService::generateVariantSku().
        option_values: optionValues,
        price: stagingVariant.price,
        discount_percent: stagingVariant.discount_percent,
        discount_type: stagingVariant.discount_type,
        stock: stagingVariant.stock ?? 0,
        low_stock_threshold: stagingVariant.low_stock_threshold,
        status: stagingVariant.status || 'active',
        image: stagingVariant.image,
    });

    // Price/discount/stock/status are left as-is — a seller adding
    // several variants in a row usually wants the same price and
    // discount on each, and can just change stock before the next Add if
    // it differs. Only the image resets — a photo almost never applies
    // to the NEXT (differently-optioned) variant the way price/discount
    // often do.
    stagingVariant.image = null;
    clearStaging();
}

// Deletes one already-added variant outright (not the same as setting
// its Status to Unavailable — that keeps the row but marks it not
// currently for sale; this removes the row entirely). Safe to just
// splice: unlike the old auto-generated-combination design, nothing
// regenerates form.variants behind the scenes anymore.
function removeVariantRow(index) {
    form.variants.splice(index, 1);
}

// ---- custom variant options ----
// A seller can add a whole axis beyond what the category template
// anticipates (e.g. "Packaging") — it renders through the same radio
// group as any other option (categoryOptionDef() simply returns
// undefined for it, so presetValuesFor()/optionHint() already fall back
// correctly with no preset list, just "+ Other").
const showAddCustomOption = ref(false);
const newCustomOptionName = ref('');
const customOptionError = ref('');

function openAddCustomOption() {
    showAddCustomOption.value = true;
    newCustomOptionName.value = '';
    customOptionError.value = '';
}

function cancelAddCustomOption() {
    showAddCustomOption.value = false;
}

function confirmAddCustomOption() {
    const name = newCustomOptionName.value.trim();

    if (!name) {
        customOptionError.value = 'Enter a name for the option.';

        return;
    }

    if (form.options.some((o) => o.name.toLowerCase() === name.toLowerCase())) {
        customOptionError.value = `"${name}" already exists.`;

        return;
    }

    form.options.push({ name, values: [] });
    showAddCustomOption.value = false;
}

function removeCustomOption(name) {
    form.options = form.options.filter((o) => o.name !== name);
    delete stagingValues[name];
}

function variantLabel(variant) {
    const entries = Object.entries(variant.option_values || {});

    if (!entries.length) {
        return 'Default (no options)';
    }

    return entries.map(([name, value]) => `${name}: ${value}`).join(', ');
}

function comboKey(optionValues) {
    return Object.keys(optionValues)
        .sort()
        .map((k) => `${k.trim().toLowerCase()}:${String(optionValues[k]).trim().toLowerCase()}`)
        .join('|');
}

// Suggested discount labels for a variant's "Discount Type" field — shown
// via a <datalist> so the field stays a plain text input a seller can
// also type past (same "suggestions, not a whitelist" pattern as variant
// option values), rather than a closed dropdown.
const DISCOUNT_TYPES = ['Flash Sale', 'Clearance', 'Seasonal', 'New Customer', 'Bundle'];

// Read-only preview of what a variant would sell for after its discount —
// seller-facing only, purely so they can see the effect of the % they
// just typed. Mirrors ProductVariant::discountedPrice() but computed
// client-side against the not-yet-saved form values. Also used against
// stagingVariant for the "Add Variant" bar's own preview.
function variantDiscountedPrice(variant) {
    if (!variant.discount_percent) {
        return null;
    }

    // variant.price is required now (no more product-level base price to
    // fall back to) — the ?? 0 is just defensive against the moment
    // between clearing the field and the Add button's own validation
    // catching it.
    const base = variant.price ?? 0;

    return (base * (1 - variant.discount_percent / 100)).toFixed(2);
}

// Pre-seeds form.specifications with every key from the current
// category's template (blank by default), so each <select>/<input> has
// a real reactive key to bind to from the moment the sheet opens, rather
// than relying on Vue to add the property lazily on first input.
function blankSpecifications() {
    const fields = categoryConfig.value?.specifications || [];

    return Object.fromEntries(fields.map((f) => [f.key, '']));
}

async function openNewProductSheet() {
    Object.assign(form, blankForm());
    form.category = sellerDetails.value?.line_of_business || '';
    clearStaging();
    Object.assign(stagingVariant, { price: null, discount_percent: null, discount_type: '', stock: 0, low_stock_threshold: null, status: 'active', image: null });
    showAddCustomOption.value = false;

    // A seller could have just finished editing a DIFFERENT product that
    // uses a different subcategory (e.g. Toys) — categoryConfig would
    // still be scoped to that one otherwise, leaking its specifications/
    // variant_options into this brand-new, not-yet-categorised product.
    if (availableSubcategories.value.length) {
        await loadCategoryConfig(null);
    }

    // Seeded AFTER the reload above so a category with subcategories
    // starts with zero fields (none apply until one is chosen) rather
    // than briefly showing whatever the previous product's were.
    form.specifications = blankSpecifications();
    reconcileFormOptions();
    activeProductId.value = 'new';
    formSnapshot.value = JSON.stringify(form);
}

// A different subcategory means a different specifications/variant-option
// vocabulary entirely (Toys' Size/Color and Toy Type have nothing to do
// with Food & Treats' Pack Weight/Flavor and Ingredients) — carrying old
// picks forward as if they were "custom" options, or leaving stale spec
// fields around, would just be confusing. This clears both and re-fetches
// the right ones for the newly-chosen subcategory.
async function onSubcategoryChange() {
    form.options = [];
    form.variants = [];
    clearStaging();
    await loadCategoryConfig(form.subcategory || null);
    form.specifications = blankSpecifications();
    reconcileFormOptions();
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

// categoryConfig loads once on mount (see onMounted below) independently
// of the sheet — this covers the edge case of a seller opening Add/Edit
// Product before that request resolves, so the variant groups still
// appear as soon as it does instead of staying stuck on the empty state.
watch(categoryConfig, () => {
    if (activeProductId.value !== null) {
        reconcileFormOptions();
    }
});

async function openEditProductSheet(listProduct) {
    // The list omits full images (base64, heavy) — pull the complete
    // product so the sheet has every image. Fall back to the list copy
    // if that request fails.
    activeProductId.value = listProduct.id;
    sheetLoading.value = true;

    let product = listProduct;

    // getProduct() (full images) and loadCategoryConfig() (this
    // product's variant/spec field list — what actually drives the
    // Variants section) are independent: the config reload only needs
    // the subcategory the trimmed LIST payload already carries, nothing
    // from the fresh product fetch. They used to run one after the
    // other, so the Variants section sat empty for the SUM of both
    // round trips; running them together cuts that wait roughly in
    // half. (Category itself never needs reloading here — it's fixed
    // per seller account, not per product.)
    const needsCategoryConfig = availableSubcategories.value.length > 0;

    try {
        const [fetchedProduct] = await Promise.all([
            getProduct(listProduct.id),
            needsCategoryConfig ? loadCategoryConfig(listProduct.subcategory || null) : null,
        ]);
        product = fetchedProduct;
    } catch {
        product = listProduct;
    } finally {
        sheetLoading.value = false;
    }

    Object.assign(form, {
        name: product.name || '',
        description: product.description || '',
        category: product.category || '',
        subcategory: product.subcategory || '',
        brand: product.brand || '',
        condition: product.condition || '',
        // Re-seeded with the right field list below, once categoryConfig
        // is reloaded for THIS product's own subcategory — for now just
        // carry over whatever was actually saved so nothing is lost.
        specifications: { ...(product.specifications || {}) },
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
        // A legacy "simple product" (created before every product required
        // a variant) has no rows here — synthesize one default variant
        // from its existing price/stock/low-stock threshold so nothing it
        // already had gets lost, and the seller doesn't have to
        // reconstruct it from memory just to save an unrelated edit.
        variants: Array.isArray(product.variants) && product.variants.length
            ? product.variants.map((v) => ({
                  sku: v.sku || '',
                  option_values: { ...v.option_values },
                  price: v.price ?? null,
                  discount_percent: v.discount_percent ?? null,
                  discount_type: v.discount_type || '',
                  stock: v.stock ?? 0,
                  low_stock_threshold: v.low_stock_threshold ?? null,
                  status: v.status || 'active',
                  image: v.image || null,
              }))
            : [
                  {
                      sku: product.sku || '',
                      option_values: {},
                      price: product.price ?? null,
                      discount_percent: null,
                      discount_type: '',
                      stock: product.stock ?? 0,
                      low_stock_threshold: product.low_stock_threshold ?? null,
                      status: 'active',
                      image: null,
                  },
              ],
    });
    clearStaging();
    Object.assign(stagingVariant, { price: null, discount_percent: null, discount_type: '', stock: 0, low_stock_threshold: null, status: 'active', image: null });
    showAddCustomOption.value = false;

    // categoryConfig was already reloaded for THIS product's own
    // subcategory above, in parallel with the product fetch.

    // Fills in blanks for any field this product hasn't set yet, using
    // THIS product's own (just-reloaded) subcategory field list — real
    // saved values from the spread above always win.
    form.specifications = { ...blankSpecifications(), ...form.specifications };
    reconcileFormOptions();
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

// One image per variant (product_variants.image is a single {url}, not a
// gallery like products.images) — `target` is either stagingVariant (the
// variant being configured) or an already-added row in form.variants,
// both plain reactive objects this can set .image on directly.
function handleVariantImageUpload(e, target) {
    const file = e.target.files?.[0];
    e.target.value = '';

    if (!file) {
        return;
    }

    const reader = new FileReader();
    reader.onload = () => {
        target.image = { url: reader.result, isNew: true };
    };
    reader.readAsDataURL(file);
}

async function handleSave() {
    if (!formIsValid.value) {
        return;
    }

    // `options` is derived from what's actually used across form.variants
    // (not form.options[].values, which also holds "Other" values typed
    // in but never actually added as a variant) — this way the submitted
    // option/value list can never drift from the variants that reference
    // it. Everything here is re-validated server-side regardless
    // (SellerProductService re-derives category from the seller's own
    // line_of_business, forces status to pending_review, etc.).
    const usedValuesByOption = new Map();

    for (const v of form.variants) {
        for (const [name, value] of Object.entries(v.option_values)) {
            if (!usedValuesByOption.has(name)) {
                usedValuesByOption.set(name, new Set());
            }

            usedValuesByOption.get(name).add(value);
        }
    }

    const payload = {
        ...form,
        options: Array.from(usedValuesByOption, ([name, values]) => ({ name, values: Array.from(values) })),
        // No sku here: it's server-generated for a new variant and
        // untouched for an existing one either way — see
        // SellerProductService::generateVariantSku().
        variants: form.variants.map((v) => ({
            option_values: v.option_values,
            price: v.price === '' ? null : v.price,
            discount_percent: v.discount_percent === '' ? null : v.discount_percent,
            discount_type: (v.discount_type || '').trim() || null,
            stock: v.stock ?? 0,
            // null means "use the app default" — see
            // ProductVariant::effectiveLowStockThreshold().
            low_stock_threshold: v.low_stock_threshold === '' ? null : v.low_stock_threshold,
            status: v.status || 'active',
            image: v.image || null,
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

<style scoped>
/* Stock adjustment + history modal (reuses .modal-overlay / .modal-panel
   / .modal-header / .modal-actions from the global seller CSS). */
.stock-modal {
    max-width: 460px;
}

.stock-tabs {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.stock-tabs button {
    padding: 0.5rem 0.75rem;
    border: 0;
    background: none;
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}

.stock-tabs button.active {
    color: #0f766e;
    border-bottom-color: #0f766e;
}

.stock-field {
    margin-bottom: 0.9rem;
}

.stock-field label {
    display: block;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #64748b;
    margin-bottom: 0.35rem;
}

.stock-optional {
    font-weight: 500;
    text-transform: none;
    letter-spacing: 0;
}

.stock-field select,
.stock-field textarea,
.stock-qty-row input {
    width: 100%;
    padding: 0.55rem 0.7rem;
    border: 1px solid #cbd5e1;
    border-radius: 0.5rem;
    font: inherit;
    background: #fff;
    box-sizing: border-box;
}

.stock-field textarea {
    resize: vertical;
}

.stock-current {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 0.8rem;
    margin-bottom: 0.9rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    font-size: 0.9rem;
    color: #475569;
}

.stock-current strong {
    font-size: 1.05rem;
    color: #0f172a;
}

.stock-current strong.is-negative {
    color: #dc2626;
}

.stock-arrow {
    color: #94a3b8;
}

.stock-qty-row {
    display: flex;
    gap: 0.6rem;
    align-items: stretch;
}

.stock-qty-row input {
    width: 5.5rem;
    flex-shrink: 0;
}

.stock-dir-toggle {
    display: flex;
    flex: 1;
    border: 1px solid #cbd5e1;
    border-radius: 0.5rem;
    overflow: hidden;
}

.stock-dir-toggle button {
    flex: 1;
    padding: 0.55rem 0.5rem;
    border: 0;
    background: #fff;
    font: inherit;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
}

.stock-dir-toggle button.active {
    background: #0f766e;
    color: #fff;
}

.stock-error {
    margin: 0 0 0.75rem;
    color: #dc2626;
    font-size: 0.82rem;
    font-weight: 600;
}

.stock-history {
    max-height: 340px;
    overflow-y: auto;
}

.stock-history-empty {
    color: #94a3b8;
    font-size: 0.88rem;
    text-align: center;
    padding: 1.5rem 0;
}

.stock-history-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.stock-history-list li {
    padding: 0.7rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.stock-history-list li:last-child {
    border-bottom: 0;
}

.stock-history-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}

.stock-history-type {
    font-size: 0.88rem;
    font-weight: 600;
    color: #1e293b;
}

.stock-history-delta {
    font-size: 0.9rem;
    font-weight: 800;
}

.stock-history-delta.up {
    color: #059669;
}

.stock-history-delta.down {
    color: #dc2626;
}

.stock-history-meta {
    margin-top: 0.15rem;
    font-size: 0.72rem;
    color: #94a3b8;
}

.stock-history-note {
    margin-top: 0.25rem;
    font-size: 0.78rem;
    color: #475569;
    font-style: italic;
}

/* ============================================================
   Inventory stat bar + Insights panel (near-black / green system —
   matches the redesigned Dashboard). Confined to this component by
   Vue's scoped attribute, same as the rest of this style block.
   ============================================================ */
.inv-stat-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    background: var(--inv-surface);
    border: 1px solid var(--inv-border);
    border-radius: 1rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.inv-stat-seg {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 1.05rem 1.3rem;
    border-right: 1px solid var(--inv-border-soft);
}
.inv-stat-seg:last-child {
    border-right: none;
}
.inv-stat-ic {
    width: 2.3rem;
    height: 2.3rem;
    border-radius: 0.65rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.inv-stat-ic.neutral {
    background: rgba(255, 255, 255, 0.06);
    color: var(--inv-ink-500);
}
.inv-stat-ic.good {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
}
.inv-stat-ic.warn {
    background: rgba(180, 83, 9, 0.2);
    color: #fbbf7d;
}
.inv-stat-ic.bad {
    background: rgba(200, 67, 61, 0.2);
    color: #f7a49f;
}
.inv-stat-v {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--inv-ink-900);
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.inv-stat-l {
    font-size: 0.72rem;
    color: var(--inv-ink-500);
    font-weight: 600;
    margin-top: 0.15rem;
}

@media (max-width: 1180px) {
    .inv-stat-bar {
        grid-template-columns: repeat(2, 1fr);
    }
    .inv-stat-seg:nth-child(2) {
        border-right: none;
    }
}

/* ---- Insights panel ---- */
.inv-insights {
    width: 18rem;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
    background: var(--inv-surface);
    border: 1px solid var(--inv-border);
    border-radius: 1rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    padding: 1.25rem 1.3rem 1.4rem;
}
.inv-insights-title {
    font-size: 0.98rem;
    font-weight: 700;
    color: var(--inv-ink-900);
    margin: 0;
}
.inv-trend-label,
.inv-attn-label {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--inv-ink-500);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 0.35rem;
}
.inv-trend-value-row {
    display: flex;
    align-items: baseline;
    gap: 0.55rem;
    flex-wrap: wrap;
}
.inv-trend-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--inv-ink-900);
}
.inv-trend-badge {
    font-size: 0.68rem;
    font-weight: 800;
    padding: 0.12rem 0.5rem;
    border-radius: 999px;
}
.inv-trend-badge.good {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
}
.inv-trend-badge.warn {
    background: rgba(180, 83, 9, 0.2);
    color: #fbbf7d;
}
.inv-trend-badge.bad {
    background: rgba(200, 67, 61, 0.2);
    color: #f7a49f;
}
.inv-trend-chart-wrap {
    height: 6.5rem;
    margin: 0.9rem 0 0.2rem;
}
.inv-trend-chart-skeleton {
    height: 6.5rem;
    margin: 0.9rem 0 0.2rem;
    border-radius: 0.6rem;
    background: var(--inv-surface-2, rgba(255, 255, 255, 0.04));
    animation: inv-skeleton-pulse 1.4s ease-in-out infinite;
}
@keyframes inv-skeleton-pulse {
    0%,
    100% {
        opacity: 0.6;
    }
    50% {
        opacity: 1;
    }
}
.inv-donut-empty {
    font-size: 0.8rem;
    color: var(--inv-ink-400);
    text-align: center;
    padding: 1.2rem 0 0.6rem;
}
.inv-trend-retry {
    display: block;
    margin: 0.4rem auto 0;
    font-size: 0.75rem;
    font-weight: 700;
    color: #5eead4;
    background: none;
    border: none;
    cursor: pointer;
}
.inv-trend-legend {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-top: 0.9rem;
}
.inv-tl-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.79rem;
    color: var(--inv-ink-700);
}
.inv-tl-key {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.inv-tl-dot {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 50%;
    flex-shrink: 0;
}
.inv-tl-dot.good {
    background: #14b8a6;
}
.inv-tl-dot.warn {
    background: #fbbf7d;
}
.inv-tl-dot.bad {
    background: #f7a49f;
}
.inv-tl-val {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.inv-attn-list {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin-top: 0.65rem;
}
.inv-attn-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    padding: 0;
    border: none;
    background: none;
    cursor: pointer;
    text-align: left;
    font: inherit;
}
.inv-attn-thumb {
    width: 2.4rem;
    height: 2.4rem;
    border-radius: 0.6rem;
    background: var(--inv-surface-2);
    color: var(--inv-ink-400);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
}
.inv-attn-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.inv-attn-info {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}
.inv-attn-name {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--inv-ink-900);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.inv-attn-meta {
    font-size: 0.72rem;
    font-weight: 700;
    margin-top: 0.1rem;
}
.inv-attn-meta.warn {
    color: #fbbf7d;
}
.inv-attn-meta.bad {
    color: #f7a49f;
}
.inv-attn-units {
    margin-left: auto;
    font-size: 0.72rem;
    color: var(--inv-ink-400);
    white-space: nowrap;
    flex-shrink: 0;
}
.inv-attn-empty {
    font-size: 0.8rem;
    color: var(--inv-ink-400);
    margin: 0.65rem 0 0;
}

.inv-review-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    width: 100%;
    padding: 0.7rem;
    border-radius: 0.75rem;
    border: none;
    background: var(--teal-500);
    color: #fff;
    font-size: 0.83rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
}
.inv-review-btn:hover {
    background: var(--teal-600);
}

.inv-stock-filter-bar {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    padding: 0.7rem 1rem;
    margin-bottom: 1.1rem;
    border-radius: 0.75rem;
    border: 1px solid var(--inv-border);
    background: var(--inv-surface);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--inv-ink-700);
}
.inv-stock-filter-bar svg {
    color: #fbbf7d;
    flex-shrink: 0;
}
.inv-stock-filter-bar b {
    color: var(--inv-ink-900);
}
.inv-stock-filter-clear {
    margin-left: auto;
    border: none;
    background: none;
    color: #5eead4;
    font-weight: 700;
    font-size: 0.78rem;
    cursor: pointer;
}
.inv-stock-filter-clear:hover {
    text-decoration: underline;
}

@media (max-width: 1180px) {
    .inventory-layout {
        flex-wrap: wrap;
    }
    .inv-insights {
        width: 100%;
    }
}

/* ---- Filter popover (Stock Status + Price Range, on demand) ---- */
.chip-btn.active {
    background: rgba(15, 118, 110, 0.22);
    color: #5eead4;
    border-color: rgba(15, 118, 110, 0.45);
}
.filter-active-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--teal-500);
    display: inline-block;
    margin-left: 0.15rem;
}
.filter-btn-wrap {
    position: relative;
}
.filter-popover {
    position: absolute;
    top: calc(100% + 0.6rem);
    right: 0;
    z-index: 30;
    width: 17rem;
    background: var(--inv-surface-2);
    box-shadow: 0 16px 36px -10px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--inv-border);
    border-radius: 0.85rem;
    padding: 1.1rem 1.2rem 1.3rem;
}
.filter-popover-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--inv-border-soft);
}
.filter-clear-link {
    border: none;
    background: none;
    color: var(--inv-ink-500);
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
}
.filter-clear-link:hover {
    color: #5eead4;
}

@media (max-width: 640px) {
    .filter-popover {
        right: auto;
        left: 0;
        width: calc(100vw - 3rem);
        max-width: 20rem;
    }
}

/* ============================================================
   DARK THEME — the reference puts the whole Inventory page (not
   just the sidebar/header) on a near-black surface. Scoped to this
   component only, so no other seller page is affected.
   ============================================================ */
.inventory-page {
    --inv-surface: #161b17;
    --inv-surface-2: #1d231e;
    --inv-border: rgba(255, 255, 255, 0.08);
    --inv-border-soft: rgba(255, 255, 255, 0.06);
    --inv-ink-900: #f2f4f1;
    --inv-ink-700: #ced4cd;
    --inv-ink-500: #97a099;
    --inv-ink-400: #6d766e;

    /* Base text color — .seller-app sets a dark, light-mode default
       (color: #1e293b) that any unstyled span here would otherwise
       silently inherit, invisible against these dark cards. */
    color: var(--inv-ink-900);
}

.inventory-page .card,
.inventory-page .inventory-toolbar {
    background: var(--inv-surface);
    border-color: var(--inv-border);
}
.inventory-page .toolbar-label {
    color: var(--inv-ink-500);
}
.inventory-page .chip-btn {
    background: var(--inv-surface-2);
    border-color: var(--inv-border);
    color: var(--inv-ink-700);
}
.inventory-page .chip-btn:hover:not(:disabled) {
    border-color: var(--teal-500);
    color: #5eead4;
}
.inventory-page .chip-btn:disabled {
    opacity: 0.45;
}
.inventory-page .chip-btn.danger {
    background: rgba(220, 38, 38, 0.14);
    border-color: rgba(220, 38, 38, 0.3);
    color: #f7a49f;
}
.inventory-page .chip-btn.danger:hover:not(:disabled) {
    background: rgba(220, 38, 38, 0.22);
    color: #f7a49f;
}
.inventory-page .notif-btn {
    color: var(--inv-ink-500);
}
.inventory-page .notif-btn.active {
    background: rgba(15, 118, 110, 0.22);
    color: #5eead4;
}

/* sort dropdown */
.inv-sort-wrap {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.inv-sort-label {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--inv-ink-500);
}
.inv-sort-select {
    padding: 0.42rem 0.6rem;
    border-radius: 0.6rem;
    border: 1px solid var(--inv-border);
    background: var(--inv-surface-2);
    color: var(--inv-ink-900);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
}

/* filter popover internals */
.inventory-page .filter-heading {
    color: var(--inv-ink-500);
}
.inventory-page .filter-check {
    color: var(--inv-ink-700);
}
.inventory-page .check-box {
    border-color: var(--inv-border);
    background: transparent;
}
.inventory-page .check-box.checked {
    background: var(--teal-500);
    border-color: var(--teal-500);
    color: #fff;
}
.inventory-page .price-track {
    background: var(--inv-border);
}
.inventory-page .price-thumb {
    background: var(--inv-ink-900);
    border: 2px solid var(--teal-500);
}
.inventory-page .price-input-box {
    background: var(--inv-surface);
    border-color: var(--inv-border);
    color: var(--inv-ink-900);
}
.inventory-page .price-input-box input {
    background: none;
    color: var(--inv-ink-900);
}
.inventory-page .price-input-sep {
    color: var(--inv-ink-400);
}

/* product grid / cards */
.inventory-page .product-card {
    background: var(--inv-surface);
    border-color: var(--inv-border);
}
.inventory-page .product-card-image {
    background: var(--inv-surface-2);
}
.inventory-page .product-card-image-placeholder {
    color: var(--inv-ink-400);
}
.inventory-page .product-name,
.inventory-page .product-price,
.inventory-page .product-stock-qty {
    color: var(--inv-ink-900);
}
.inventory-page .product-sku,
.inventory-page .product-compare-price,
.inventory-page .product-stock-label {
    color: var(--inv-ink-500);
}
.inventory-page .product-stock-bar {
    background: var(--inv-border);
}
.inventory-page .product-hover-actions button {
    background: var(--inv-surface-2);
    color: var(--inv-ink-700);
}
.inventory-page .product-hover-actions button:hover {
    color: #5eead4;
}

/* pagination */
.inventory-page .pagination {
    border-top-color: var(--inv-border-soft);
}
.inventory-page .pagination-label {
    color: var(--inv-ink-500);
}
.inventory-page .page-btn {
    background: var(--inv-surface-2);
    border-color: var(--inv-border);
    color: var(--inv-ink-700);
}
.inventory-page .page-btn:disabled {
    color: var(--inv-ink-400);
}

/* ============================================================
   ADD / EDIT PRODUCT -- full-page layout (replaces the old centered
   modal). Inherits --inv-* tokens from .inventory-page, so it is
   dark by default; the overrides below only touch the shared
   .field-input / .ps-* / .image-* classes, which are also used by
   other (still-light) seller pages -- safe here because Vue scoped
   CSS confines these rules to this component only.
   ============================================================ */
.product-page {
    display: flex;
    flex-direction: column;
}
.product-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}
.product-page-back {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    border: none;
    background: none;
    padding: 0;
    margin-bottom: 0.7rem;
    color: var(--inv-ink-500);
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
}
.product-page-back:hover {
    color: var(--inv-ink-900);
}
.product-page-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--inv-ink-900);
    margin: 0;
}
.product-page-sub {
    font-size: 0.85rem;
    color: var(--inv-ink-500);
    margin: 0.3rem 0 0;
}
.product-page-actions {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    flex-shrink: 0;
}
.product-page-actions .btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.product-page-grid {
    display: flex;
    /* stretch, not flex-start: .product-page-side only holds Upload Image
       now (Category moved into .product-page-main), so on its own it's
       much shorter than the main column — stretch lets it grow to match,
       and the rules below grow Upload Image's own card/dropzone to fill
       that height instead of leaving blank space under a short card. */
    align-items: stretch;
    gap: 1.5rem;
}
.product-page-main {
    flex: 1 1 60%;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}
.product-page-side {
    flex: 1 1 30%;
    min-width: 18rem;
    max-width: 22rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}
.product-page-side .ps-section {
    flex: 1;
}
.product-page-side .ps-section-card {
    flex: 1;
}
.product-page-side .image-dropzone {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

/* Product Variants lives here, below the two-column grid, spanning the
   FULL page width instead of being squeezed into .product-page-main's
   ~60% — with price/discount/discount type/stock/low-stock/status/image
   per variant, the extra width (previously empty space to the right of
   the narrower main column) lets more fields sit on one line before
   wrapping, instead of always wrapping onto several. */
.product-page-full {
    margin-top: 1.5rem;
}

@media (max-width: 960px) {
    .product-page-grid {
        flex-direction: column;
    }
    .product-page-side {
        max-width: none;
        width: 100%;
    }
}

/* ---- form cards/fields, dark variants ---- */
.inventory-page .ps-section-header h3 {
    color: var(--inv-ink-900);
}
.inventory-page .ps-section-card {
    background: var(--inv-surface);
    border-color: var(--inv-border);
}
.inventory-page .field-input {
    background: var(--inv-surface-2);
    border-color: var(--inv-border);
    color: var(--inv-ink-900);
}
.inventory-page .field-hint {
    color: var(--inv-ink-500);
}
.inventory-page .ps-category-chip {
    background: rgba(15, 118, 110, 0.18);
    border: 1px solid rgba(15, 118, 110, 0.4);
    color: #5eead4;
}
.inventory-page .ps-icon-teal {
    background: rgba(15, 118, 110, 0.18);
    color: #5eead4;
}
.inventory-page .ps-icon-emerald {
    background: rgba(20, 184, 166, 0.18);
    color: #5eead4;
}
.inventory-page .ps-icon-sky {
    background: rgba(111, 163, 224, 0.18);
    color: #9dc2ef;
}
.inventory-page .ps-icon-indigo {
    background: rgba(129, 140, 248, 0.18);
    color: #b4bcfa;
}
.inventory-page .ps-icon-amber {
    background: rgba(251, 191, 125, 0.18);
    color: #fbbf7d;
}
.inventory-page .ps-icon-pink {
    background: rgba(236, 72, 153, 0.18);
    color: #f4a7d0;
}
.inventory-page .image-dropzone {
    background: var(--inv-surface-2);
    border-color: var(--inv-border);
    color: var(--inv-ink-500);
}
.inventory-page .image-dropzone .dz-title {
    color: var(--inv-ink-700);
}
.inventory-page .image-dropzone .dz-sub {
    color: var(--inv-ink-400);
}
.inventory-page .image-thumb,
.inventory-page .image-thumb-add {
    border-color: var(--inv-border);
}
.inventory-page .image-thumb-add {
    color: var(--inv-ink-500);
}
.inventory-page .variant-card {
    border-color: var(--inv-border);
    background: var(--inv-surface-2);
}
.inventory-page .variant-card-title {
    color: var(--inv-ink-900);
}
.inventory-page .variant-field-label {
    color: var(--inv-ink-500);
}
.inventory-page .variant-sku-display {
    background: var(--inv-surface);
    border-color: var(--inv-border);
    color: var(--inv-ink-500);
}
.inventory-page .variant-image-upload {
    background: var(--inv-surface);
    border-color: var(--inv-border);
    color: var(--inv-ink-500);
}
.inventory-page .variant-value-input {
    color: var(--inv-ink-900);
}
.inventory-page .variant-option-values {
    background: var(--inv-surface-2);
    border-color: var(--inv-border);
}
.inventory-page .variant-value-chip {
    background: rgba(15, 118, 110, 0.18);
    border-color: rgba(15, 118, 110, 0.4);
    color: #5eead4;
}
.inventory-page .variant-value-chip button {
    color: #5eead4;
}
.inventory-page .variant-group {
    border-bottom-color: var(--inv-border-soft);
}
.inventory-page .variant-group-name {
    color: var(--inv-ink-900);
}
.inventory-page .variant-group-hint {
    color: var(--inv-ink-500);
}
.inventory-page .variant-pill {
    background: var(--inv-surface-2);
    border-color: var(--inv-border);
    color: var(--inv-ink-700);
}
.inventory-page .variant-pill-other {
    color: var(--inv-ink-500);
}
.inventory-page .variant-radio {
    color: var(--inv-ink-700);
}
.inventory-page .variant-radio.selected,
.inventory-page .variant-radio-other.selected {
    color: #5eead4;
}
.inventory-page .variant-radio-other {
    color: var(--inv-ink-500);
}
.inventory-page .variant-other-input input {
    background: var(--inv-surface-2);
    color: var(--inv-ink-900);
}
.inventory-page .variant-staging-bar {
    background: rgba(15, 118, 110, 0.18);
}
.inventory-page .variant-staging-summary {
    color: #5eead4;
}
.inventory-page .variant-staging-empty {
    color: var(--inv-ink-500);
}
.inventory-page .variant-staging-clear {
    color: #5eead4;
}
.inventory-page .variant-row-remove {
    color: var(--inv-ink-500);
}
.inventory-page .variant-row-remove:hover {
    background: rgba(220, 38, 38, 0.18);
    color: #f7a49f;
}
</style>