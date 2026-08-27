<!-- resources/js/seller/components/Feedback.vue -->
<template>
    <div class="feedback-page">
        <!-- Toolbar -->
        <div class="feedback-toolbar">
            <p class="feedback-toolbar-note">
                Reviews and ratings left on your products.
            </p>
            <button
                type="button"
                class="btn-outline btn-sm feedback-export-btn"
                :disabled="isExporting"
                @click="exportCsv"
            >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 15V3" />
                    <path d="m7 10 5 5 5-5" />
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                </svg>
                {{ isExporting ? 'Exporting…' : 'Export CSV' }}
            </button>
        </div>
        <p v-if="exportError" class="feedback-inline-error">{{ exportError }}</p>

        <!-- Summary cards -->
        <div class="feedback-summary-grid">
            <div class="card feedback-summary-card">
                <div class="feedback-summary-card-top">
                    <span class="feedback-summary-icon amber">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2Z" />
                        </svg>
                    </span>
                    <span class="info-tip" tabindex="0" role="img" aria-label="Average of every star rating across your reviews">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
                        <span class="info-tip-bubble">Average of every star rating across your reviews.</span>
                    </span>
                </div>
                <h3 class="feedback-summary-value">{{ summary?.overallRating ?? '—' }}</h3>
                <p class="feedback-summary-label">Average Rating</p>
                <span v-if="ratingTrendValue !== null" class="feedback-trend" :class="trendClass(ratingTrendValue)">
                    {{ trendArrow(ratingTrendValue) }} {{ Math.abs(ratingTrendValue).toFixed(2) }} vs last 30 days
                </span>
            </div>

            <div class="card feedback-summary-card">
                <div class="feedback-summary-card-top">
                    <span class="feedback-summary-icon sky">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" />
                        </svg>
                    </span>
                    <span class="info-tip" tabindex="0" role="img" aria-label="All reviews received across every product you sell">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
                        <span class="info-tip-bubble">All reviews received across every product you sell.</span>
                    </span>
                </div>
                <h3 class="feedback-summary-value">{{ summary?.totalReviews ?? '—' }}</h3>
                <p class="feedback-summary-label">Total Reviews</p>
                <span v-if="countTrendValue !== null" class="feedback-trend" :class="trendClass(countTrendValue)">
                    {{ trendArrow(countTrendValue) }} {{ Math.abs(countTrendValue) }} vs last 30 days
                </span>
            </div>

            <div class="card feedback-summary-card">
                <div class="feedback-summary-card-top">
                    <span class="feedback-summary-icon rose">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 3" />
                        </svg>
                    </span>
                    <span class="info-tip" tabindex="0" role="img" aria-label="Reviews you have not replied to yet">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
                        <span class="info-tip-bubble">Reviews you haven't replied to yet.</span>
                    </span>
                </div>
                <h3 class="feedback-summary-value">{{ summary?.unansweredCount ?? '—' }}</h3>
                <p class="feedback-summary-label">Unanswered</p>
            </div>

            <div class="card feedback-summary-card">
                <div class="feedback-summary-card-top">
                    <span class="feedback-summary-icon emerald">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M13 2 3 14h7l-1 8 10-12h-7l1-8Z" />
                        </svg>
                    </span>
                    <span class="info-tip" tabindex="0" role="img" :aria-label="responseRateTooltip">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
                        <span class="info-tip-bubble">{{ responseRateTooltip }}</span>
                    </span>
                </div>
                <h3 class="feedback-summary-value">{{ summary ? `${summary.responseRate}%` : '—' }}</h3>
                <p class="feedback-summary-label">Response Rate</p>
            </div>
        </div>

        <div class="feedback-layout">
            <!-- Left column -->
            <aside class="feedback-side">
                <div class="card feedback-distribution">
                    <h3 class="feedback-side-title">
                        Rating Distribution
                        <span class="info-tip" tabindex="0" role="img" aria-label="Click a row to filter the list by that rating">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
                            <span class="info-tip-bubble">Click a row to filter the list by that rating.</span>
                        </span>
                    </h3>
                    <div v-if="summary" class="feedback-dist-list">
                        <button
                            v-for="row in summary.ratingDistribution"
                            :key="row.rating"
                            type="button"
                            class="feedback-dist-row"
                            :class="{ active: filters.rating === row.rating }"
                            @click="onDistRowClick(row.rating)"
                        >
                            <div class="feedback-dist-label-row">
                                <span>{{ row.rating }} Star{{ row.rating > 1 ? 's' : '' }}</span>
                                <span class="feedback-dist-count">{{ row.count }} ({{ row.percent }}%)</span>
                            </div>
                            <div class="feedback-dist-bar">
                                <div class="feedback-dist-fill" :class="distBarClass(row.rating)" :style="{ width: row.percent + '%' }"></div>
                            </div>
                        </button>
                    </div>
                </div>

                <div class="card feedback-attention">
                    <h3 class="feedback-side-title">Needs Attention</h3>
                    <template v-if="summary && summary.unansweredCount > 0">
                        <p class="feedback-attention-copy">
                            <strong>{{ summary.unansweredCount }}</strong> review{{ summary.unansweredCount === 1 ? '' : 's' }} waiting on a reply.
                        </p>
                        <p v-if="summary.lowRatingUnansweredCount > 0" class="feedback-attention-warning">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                                <path d="M12 9v4" /><path d="M12 17h.01" />
                            </svg>
                            {{ summary.lowRatingUnansweredCount }} of those are 1-2 star ratings
                        </p>
                        <button type="button" class="btn-primary btn-sm feedback-attention-btn" @click="viewUnanswered">
                            View unanswered
                        </button>
                    </template>
                    <p v-else-if="summary" class="feedback-attention-clear">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5" /></svg>
                        You're all caught up — every review has a reply.
                    </p>
                </div>
            </aside>

            <!-- Right column -->
            <section class="feedback-main">
                <div ref="controlsCard" class="card feedback-controls">
                    <div class="feedback-tabs" role="tablist" aria-label="Filter reviews by status">
                        <button
                            v-for="tab in statusTabs"
                            :key="tab.value"
                            type="button"
                            role="tab"
                            class="feedback-tab"
                            :class="{ active: filters.status === tab.value }"
                            :aria-selected="filters.status === tab.value"
                            @click="setFilter({ status: tab.value })"
                        >
                            {{ tab.label }}
                            <span class="feedback-tab-count">{{ tabCount(tab.value) }}</span>
                        </button>
                    </div>

                    <div class="feedback-controls-right">
                        <div class="header-search feedback-search">
                            <span class="search-icon">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.35-4.35" /></svg>
                            </span>
                            <input
                                type="text"
                                :value="searchInput"
                                placeholder="Search name, product, order #…"
                                aria-label="Search reviews"
                                @input="onSearchInput($event.target.value)"
                            />
                        </div>

                        <select
                            class="field-input feedback-sort"
                            aria-label="Sort reviews"
                            :value="filters.sort"
                            @change="setFilter({ sort: $event.target.value })"
                        >
                            <option value="newest">Newest</option>
                            <option value="oldest">Oldest</option>
                            <option value="highest">Highest rating</option>
                            <option value="lowest">Lowest rating</option>
                        </select>

                        <button
                            type="button"
                            class="btn-outline btn-sm feedback-filter-toggle"
                            :aria-expanded="showMoreFilters"
                            @click="showMoreFilters = !showMoreFilters"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 5h18M7 12h10M11 19h2" /></svg>
                            Filters
                            <span v-if="hasSecondaryFilters" class="feedback-filter-dot" aria-hidden="true"></span>
                        </button>
                    </div>

                    <div class="feedback-secondary-filters" :class="{ open: showMoreFilters }">
                        <div class="feedback-field">
                            <label class="field-label" for="fb-rating">Exact rating</label>
                            <select
                                id="fb-rating"
                                class="field-input"
                                v-model.number="ratingFilterValue"
                                @change="onRatingSelect"
                            >
                                <option :value="null">Any rating</option>
                                <option v-for="n in [5, 4, 3, 2, 1]" :key="n" :value="n">{{ n }} star{{ n > 1 ? 's' : '' }}</option>
                            </select>
                        </div>
                        <div class="feedback-field">
                            <label class="field-label" for="fb-date-from">From</label>
                            <input id="fb-date-from" type="date" class="field-input" v-model="dateFromValue" :max="dateToValue || today" @change="applyDateRange" />
                        </div>
                        <div class="feedback-field">
                            <label class="field-label" for="fb-date-to">To</label>
                            <input id="fb-date-to" type="date" class="field-input" v-model="dateToValue" :min="dateFromValue" :max="today" @change="applyDateRange" />
                        </div>
                        <button v-if="hasActiveFilters" type="button" class="btn-outline btn-sm feedback-clear-btn" @click="onClearFilters">
                            Clear filters
                        </button>
                    </div>
                </div>

                <!-- Loading skeleton (first load only) -->
                <div v-if="isLoadingReviews && !hasReviews && !loadError" class="feedback-skeleton-list">
                    <div v-for="n in 3" :key="n" class="card feedback-skeleton-card" aria-hidden="true">
                        <div class="feedback-skeleton-line" style="width: 40%"></div>
                        <div class="feedback-skeleton-line" style="width: 70%"></div>
                        <div class="feedback-skeleton-line" style="width: 90%"></div>
                        <div class="feedback-skeleton-line" style="width: 55%"></div>
                    </div>
                </div>

                <!-- Error -->
                <div v-else-if="loadError" class="card empty-state">
                    <p style="font-weight: 700; color: #b91c1c">Couldn't load your reviews</p>
                    <p class="empty-hint">{{ loadError }}</p>
                    <button type="button" class="btn-outline" style="margin-top: 1rem" @click="loadReviews">Try again</button>
                </div>

                <!-- No reviews at all yet -->
                <div v-else-if="!hasAnyReviewsAtAll" class="card empty-state">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2Z" />
                    </svg>
                    <p style="font-weight: 700; color: #1e293b">No reviews yet</p>
                    <p class="empty-hint">
                        Reviews will show up here once customers start leaving feedback on delivered orders.
                    </p>
                </div>

                <!-- No results for current filters -->
                <div v-else-if="!hasReviews" class="card empty-state">
                    <p style="font-weight: 700; color: #1e293b">
                        {{ filters.status === 'unanswered' ? 'No unanswered reviews' : 'No reviews match these filters' }}
                    </p>
                    <p class="empty-hint">Try adjusting your search, rating, or date range.</p>
                    <button type="button" class="btn-outline" style="margin-top: 1rem" @click="onClearFilters">Clear filters</button>
                </div>

                <!-- Review list -->
                <div v-else class="feedback-list" :class="{ 'is-updating': isLoadingReviews }">
                    <article
                        v-for="review in reviews"
                        :key="review.id"
                        class="card feedback-card"
                        :class="{ urgent: review.rating <= 2 && !review.isResponded }"
                    >
                        <div class="feedback-card-head">
                            <div class="feedback-card-buyer">
                                <span class="feedback-avatar" aria-hidden="true">{{ review.buyer.initials }}</span>
                                <div>
                                    <h4 class="feedback-buyer-name">{{ review.buyer.name }}</h4>
                                    <div class="feedback-stars-row">
                                        <span class="feedback-stars" role="img" :aria-label="`${review.rating} out of 5 stars`">
                                            <span v-for="n in 5" :key="n" class="star" :class="{ filled: n <= review.rating }">
                                                <svg width="13" height="13" viewBox="0 0 24 24">
                                                    <path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2Z" />
                                                </svg>
                                            </span>
                                        </span>
                                        <span class="feedback-date">{{ review.date }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="feedback-card-status">
                                <span v-if="!review.isResponded" class="badge" :class="review.rating <= 2 ? 'badge-red' : 'badge-amber'">
                                    <svg v-if="review.rating <= 2" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /><path d="M12 9v4" /><path d="M12 17h.01" />
                                    </svg>
                                    {{ review.rating <= 2 ? 'Needs response' : 'Unanswered' }}
                                </span>
                                <span v-else class="badge badge-sky">Responded</span>
                            </div>
                        </div>

                        <div v-if="review.product?.name" class="feedback-product-chip">
                            <div class="feedback-product-thumb">
                                <img v-if="review.product.image" :src="review.product.image" :alt="review.product.name" loading="lazy" decoding="async" />
                                <div v-else class="feedback-product-thumb-placeholder" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" />
                                    </svg>
                                </div>
                            </div>
                            <div class="feedback-product-info">
                                <p class="feedback-product-name">{{ review.product.name }}</p>
                                <p v-if="review.product.variant || review.orderNumber" class="feedback-product-meta">
                                    <span v-if="review.product.variant">{{ review.product.variant }}</span>
                                    <span v-if="review.product.variant && review.orderNumber"> · </span>
                                    <span v-if="review.orderNumber">Order {{ review.orderNumber }}</span>
                                </p>
                            </div>
                        </div>

                        <template v-if="review.comment">
                            <p class="feedback-comment" :class="{ clamped: isLong(review.comment) && !expandedComments.has(review.id) }">
                                {{ review.comment }}
                            </p>
                            <button v-if="isLong(review.comment)" type="button" class="feedback-showmore" @click="toggleExpand(review.id)">
                                {{ expandedComments.has(review.id) ? 'Show less' : 'Show more' }}
                            </button>
                        </template>

                        <div v-if="review.images?.length" class="feedback-card-images">
                            <button
                                v-for="(img, i) in review.images"
                                :key="i"
                                type="button"
                                class="feedback-image-thumb"
                                :aria-label="`View customer photo ${i + 1} of ${review.images.length}`"
                                @click="openPreview(review, i)"
                            >
                                <img :src="img" :alt="`Customer photo ${i + 1}`" loading="lazy" decoding="async" />
                            </button>
                        </div>

                        <!-- Published response -->
                        <div v-if="review.isResponded && editingId !== review.id" class="feedback-response">
                            <div class="feedback-response-head">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 10 20 15 15 20" /><path d="M4 4v7a4 4 0 0 0 4 4h12" />
                                </svg>
                                <span class="feedback-response-label">Your response</span>
                                <span v-if="review.isEdited" class="feedback-response-edited">(edited)</span>
                            </div>
                            <p class="feedback-response-text">{{ review.sellerResponse }}</p>
                            <button type="button" class="feedback-edit-btn" @click="startEditing(review)">Edit response</button>
                        </div>

                        <p v-if="justSubmittedId === review.id" class="save-msg success">✓ Response sent.</p>

                        <!-- Response form: shown for unanswered reviews, or while editing an existing one -->
                        <div v-if="!review.isResponded || editingId === review.id" class="feedback-response-form">
                            <div v-if="!review.isResponded" class="feedback-quick-replies">
                                <button
                                    v-for="qr in quickReplies"
                                    :key="qr.label"
                                    type="button"
                                    class="feedback-quick-reply-btn"
                                    @click="applyQuickReply(review, qr)"
                                >
                                    {{ qr.label }}
                                </button>
                            </div>

                            <label class="field-label" :for="`fb-response-${review.id}`">
                                {{ review.isResponded ? 'Edit your response' : `Respond to ${review.buyer.name}` }}
                            </label>
                            <textarea
                                :id="`fb-response-${review.id}`"
                                class="field-input feedback-textarea"
                                rows="3"
                                maxlength="2000"
                                :placeholder="`Type your response to ${review.buyer.name}…`"
                                v-model="drafts[review.id]"
                                @input="persistDraft(review.id)"
                            ></textarea>

                            <div class="feedback-form-footer">
                                <span class="feedback-char-count" :class="{ warn: (drafts[review.id] || '').length > 1800 }">
                                    {{ (drafts[review.id] || '').length }}/2000
                                </span>
                                <div class="feedback-form-actions">
                                    <button v-if="review.isResponded" type="button" class="btn-outline btn-sm" @click="cancelEditing(review)">
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-primary btn-sm"
                                        :disabled="!canSubmit(review)"
                                        @click="onSubmit(review)"
                                    >
                                        {{ respondingId === review.id ? 'Sending…' : (review.isResponded ? 'Update Response' : 'Send Response') }}
                                    </button>
                                </div>
                            </div>
                            <p v-if="respondError && lastAttemptedId === review.id" class="save-msg error">{{ respondError }}</p>
                        </div>
                    </article>
                </div>

                <!-- Pagination -->
                <div v-if="hasReviews" class="pagination">
                    <p class="pagination-label">
                        Showing {{ rangeStart }}-{{ rangeEnd }} of {{ meta.total }} review{{ meta.total === 1 ? '' : 's' }}
                    </p>
                    <div class="pagination-controls pagination-full">
                        <button class="page-btn" :disabled="meta.currentPage === 1" @click="goToPage(meta.currentPage - 1)">Previous</button>
                        <button
                            v-for="p in pageNumbers"
                            :key="p"
                            class="page-btn"
                            :class="{ active: p === meta.currentPage }"
                            @click="goToPage(p)"
                        >
                            {{ p }}
                        </button>
                        <button class="page-btn" :disabled="meta.currentPage === meta.lastPage" @click="goToPage(meta.currentPage + 1)">Next</button>
                    </div>
                    <div class="pagination-controls pagination-simple">
                        <button class="page-btn" :disabled="meta.currentPage === 1" @click="goToPage(meta.currentPage - 1)">Previous</button>
                        <span class="pagination-page-indicator">Page {{ meta.currentPage }} of {{ meta.lastPage }}</span>
                        <button class="page-btn" :disabled="meta.currentPage === meta.lastPage" @click="goToPage(meta.currentPage + 1)">Next</button>
                    </div>
                </div>
            </section>
        </div>

        <!-- Image preview modal -->
        <div
            v-if="previewImages.length"
            class="modal-overlay"
            role="dialog"
            aria-modal="true"
            aria-label="Customer photo preview"
            @click.self="closePreview"
        >
            <div class="feedback-image-modal">
                <button type="button" class="modal-close feedback-image-close" aria-label="Close preview" @click="closePreview">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
                </button>
                <button v-if="previewImages.length > 1" type="button" class="feedback-image-nav prev" aria-label="Previous photo" @click="prevPreviewImage">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <img :src="previewImages[previewIndex]" alt="Customer photo" />
                <button v-if="previewImages.length > 1" type="button" class="feedback-image-nav next" aria-label="Next photo" @click="nextPreviewImage">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6" /></svg>
                </button>
            </div>
        </div>

        <!-- Confirm edit modal -->
        <div v-if="confirmEditId" class="modal-overlay" @click.self="cancelEditConfirm">
            <div class="modal-panel">
                <div class="modal-header">
                    <h3>Update your response?</h3>
                    <button type="button" class="modal-close" aria-label="Close" @click="cancelEditConfirm">
                        <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                    </button>
                </div>
                <p class="modal-desc">
                    This replaces your published reply to {{ confirmEditBuyerName }}. This can't be undone.
                </p>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" style="flex: 1" @click="cancelEditConfirm">Cancel</button>
                    <button type="button" class="btn-primary" style="flex: 1" :disabled="!!respondingId" @click="confirmEditSubmit">
                        {{ respondingId ? 'Updating…' : 'Update Response' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useFeedback } from '../composables/useFeedback';

const {
    reviews,
    meta,
    isLoadingReviews,
    loadError,
    hasReviews,
    hasAnyReviewsAtAll,
    hasActiveFilters,
    summary,
    filters,
    setFilter,
    clearFilters,
    respondingId,
    respondError,
    respondToReview,
    isExporting,
    exportError,
    exportCsv,
    loadReviews,
    loadSummary,
} = useFeedback();

const statusTabs = [
    { value: 'all', label: 'All' },
    { value: 'unanswered', label: 'Unanswered' },
    { value: 'low_rating', label: 'Low Rating' },
    { value: 'responded', label: 'Responded' },
];

// "Most helpful" is intentionally not a sort option — there is no
// helpful-vote column or table backing it (see
// SellerFeedbackController's docblock). Adding it here would mean
// either faking numbers or shipping a control that silently does
// nothing, both worse than just not offering it yet.
const quickReplies = [
    { label: 'Thank you', text: "Thank you so much for your feedback — we really appreciate you taking the time to share it!" },
    { label: 'Troubleshooting help', text: "Sorry for the trouble! Please reach out to us via Messages and we'll help get this sorted quickly." },
    { label: 'Apology + replacement', text: "We're really sorry to hear this. Please contact us through Messages so we can arrange a replacement or refund." },
];

const controlsCard = ref(null);
const searchInput = ref('');
const ratingFilterValue = ref(null);
const dateFromValue = ref('');
const dateToValue = ref('');
const showMoreFilters = ref(false);
const expandedComments = ref(new Set());
const editingId = ref(null);
const drafts = ref({});
const lastAttemptedId = ref(null);
const justSubmittedId = ref(null);
const confirmEditId = ref(null);
const pendingEditText = ref('');
const previewImages = ref([]);
const previewIndex = ref(0);

const today = new Date().toISOString().slice(0, 10);

const responseRateTooltip = computed(() => {
    const hours = summary.value?.avgResponseTimeHours;
    const base = 'Share of your reviews that have a seller response.';
    return hours != null ? `${base} You typically reply within ${hours}h.` : base;
});

const ratingTrendValue = computed(() => summary.value?.trend?.ratingChange ?? null);
const countTrendValue = computed(() => summary.value?.trend?.reviewCountChange ?? null);

function trendClass(v) {
    return v > 0 ? 'up' : v < 0 ? 'down' : 'flat';
}
function trendArrow(v) {
    return v > 0 ? '↑' : v < 0 ? '↓' : '→';
}

function tabCount(value) {
    const counts = meta.value.statusCounts || {};
    if (value === 'all') return counts.all ?? 0;
    if (value === 'unanswered') return counts.unanswered ?? 0;
    if (value === 'low_rating') return counts.lowRating ?? 0;
    if (value === 'responded') return counts.responded ?? 0;
    return 0;
}

function distBarClass(rating) {
    if (rating === 5) return 'bar-teal';
    if (rating === 4) return 'bar-teal-soft';
    if (rating === 3) return 'bar-amber';
    if (rating === 2) return 'bar-red';
    return 'bar-red-strong';
}

const hasSecondaryFilters = computed(() => !!(filters.value.rating || filters.value.dateFrom || filters.value.dateTo));

const rangeStart = computed(() => (meta.value.total === 0 ? 0 : (meta.value.currentPage - 1) * meta.value.perPage + 1));
const rangeEnd = computed(() => Math.min(meta.value.currentPage * meta.value.perPage, meta.value.total));

const pageNumbers = computed(() => {
    const last = meta.value.lastPage || 1;
    const current = meta.value.currentPage || 1;
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }
    let start = Math.max(1, current - 2);
    const end = Math.min(last, start + 4);
    start = Math.max(1, end - 4);
    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
});

const confirmEditBuyerName = computed(() => {
    const r = reviews.value.find((r) => r.id === confirmEditId.value);
    return r?.buyer?.name || 'this customer';
});

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

function scrollToControls() {
    controlsCard.value?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
}

// ---- search (debounced) ----
let searchDebounce = null;
function onSearchInput(value) {
    searchInput.value = value;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        setFilter({ search: value.trim() });
    }, 350);
}

// ---- rating / date filters ----
function onRatingSelect() {
    setFilter({ rating: ratingFilterValue.value });
}
function applyDateRange() {
    setFilter({ dateFrom: dateFromValue.value, dateTo: dateToValue.value });
}
function onDistRowClick(rating) {
    ratingFilterValue.value = filters.value.rating === rating ? null : rating;
    setFilter({ rating: ratingFilterValue.value });
    scrollToControls();
}
function viewUnanswered() {
    ratingFilterValue.value = null;
    setFilter({ status: 'unanswered', sort: 'lowest', rating: null });
    scrollToControls();
}
function onClearFilters() {
    searchInput.value = '';
    ratingFilterValue.value = null;
    dateFromValue.value = '';
    dateToValue.value = '';
    showMoreFilters.value = false;
    clearFilters();
}
function goToPage(p) {
    if (p < 1 || p > meta.value.lastPage || p === meta.value.currentPage) return;
    setFilter({ page: p });
    scrollToControls();
}

// ---- comment "show more" ----
function isLong(text) {
    return !!text && text.length > 240;
}
function toggleExpand(id) {
    const next = new Set(expandedComments.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    expandedComments.value = next;
}

// ---- drafts (in-memory + localStorage, so an unfinished reply survives
// a refresh or a filter change "when practical" per the design brief) ----
function draftKey(id) {
    return `nexmart_seller_feedback_draft_${id}`;
}
function persistDraft(id) {
    try {
        const text = drafts.value[id] || '';
        if (text.trim()) {
            localStorage.setItem(draftKey(id), text);
        } else {
            localStorage.removeItem(draftKey(id));
        }
    } catch {
        // best-effort only (e.g. private browsing can throw) — losing a
        // draft on refresh is a minor inconvenience, not worth surfacing
        // an error for.
    }
}
function clearDraft(id) {
    drafts.value[id] = '';
    try {
        localStorage.removeItem(draftKey(id));
    } catch {
        // see persistDraft
    }
}
function hydrateDrafts(list) {
    for (const r of list) {
        if (drafts.value[r.id] === undefined) {
            let saved = '';
            try {
                saved = localStorage.getItem(draftKey(r.id)) || '';
            } catch {
                saved = '';
            }
            drafts.value[r.id] = saved;
        }
    }
}
watch(reviews, hydrateDrafts, { immediate: true });

function applyQuickReply(review, template) {
    drafts.value[review.id] = template.text;
    persistDraft(review.id);
}

// ---- responding / editing ----
function startEditing(review) {
    editingId.value = review.id;
    lastAttemptedId.value = null;
    if (!drafts.value[review.id]) {
        drafts.value[review.id] = review.sellerResponse || '';
    }
}
function cancelEditing(review) {
    editingId.value = null;
    lastAttemptedId.value = null;
    clearDraft(review.id);
}
function canSubmit(review) {
    const text = (drafts.value[review.id] || '').trim();
    return text.length >= 2 && text.length <= 2000 && !respondingId.value;
}
function flashSuccess(id) {
    justSubmittedId.value = id;
    setTimeout(() => {
        if (justSubmittedId.value === id) justSubmittedId.value = null;
    }, 3000);
}
async function onSubmit(review) {
    const text = (drafts.value[review.id] || '').trim();
    if (!text || respondingId.value) return;

    if (review.isResponded) {
        // Editing a published response — confirm before overwriting it.
        pendingEditText.value = text;
        confirmEditId.value = review.id;
        return;
    }

    lastAttemptedId.value = review.id;
    const result = await respondToReview(review.id, text);
    if (result) {
        clearDraft(review.id);
        lastAttemptedId.value = null;
        flashSuccess(review.id);
    }
}
async function confirmEditSubmit() {
    const id = confirmEditId.value;
    const text = pendingEditText.value;
    if (!id || !text) return;

    lastAttemptedId.value = id;
    const result = await respondToReview(id, text);
    confirmEditId.value = null;
    pendingEditText.value = '';

    if (result) {
        editingId.value = null;
        clearDraft(id);
        lastAttemptedId.value = null;
        flashSuccess(id);
    }
}
function cancelEditConfirm() {
    confirmEditId.value = null;
    pendingEditText.value = '';
}

// ---- image preview modal ----
function onPreviewKeydown(e) {
    if (e.key === 'Escape') closePreview();
    else if (e.key === 'ArrowLeft') prevPreviewImage();
    else if (e.key === 'ArrowRight') nextPreviewImage();
}
function openPreview(review, index) {
    previewImages.value = review.images || [];
    previewIndex.value = index;
    window.addEventListener('keydown', onPreviewKeydown);
}
function closePreview() {
    previewImages.value = [];
    window.removeEventListener('keydown', onPreviewKeydown);
}
function prevPreviewImage() {
    previewIndex.value = (previewIndex.value - 1 + previewImages.value.length) % previewImages.value.length;
}
function nextPreviewImage() {
    previewIndex.value = (previewIndex.value + 1) % previewImages.value.length;
}

// ---- URL sync: read filters from the query string on load, and keep
// them reflected there as they change, so a refresh or a shared link
// reproduces the same view. SellerLayout's router only reads
// window.location.pathname (never .search) to decide which page is
// showing, so rewriting just the query string here can't break
// navigation — see SellerLayout.vue's resolveSection(). ----
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    filters.value.search = params.get('search') || '';
    filters.value.rating = params.get('rating') ? Number(params.get('rating')) : null;
    filters.value.status = params.get('status') || 'all';
    filters.value.sort = params.get('sort') || 'newest';
    filters.value.dateFrom = params.get('date_from') || '';
    filters.value.dateTo = params.get('date_to') || '';
    filters.value.page = params.get('page') ? Number(params.get('page')) : 1;

    searchInput.value = filters.value.search;
    ratingFilterValue.value = filters.value.rating;
    dateFromValue.value = filters.value.dateFrom;
    dateToValue.value = filters.value.dateTo;

    loadSummary();
    loadReviews();
});

watch(
    filters,
    (f) => {
        const params = new URLSearchParams();
        if (f.search) params.set('search', f.search);
        if (f.rating) params.set('rating', f.rating);
        if (f.status && f.status !== 'all') params.set('status', f.status);
        if (f.sort && f.sort !== 'newest') params.set('sort', f.sort);
        if (f.dateFrom) params.set('date_from', f.dateFrom);
        if (f.dateTo) params.set('date_to', f.dateTo);
        if (f.page && f.page > 1) params.set('page', f.page);

        const qs = params.toString();
        const newUrl = window.location.pathname + (qs ? `?${qs}` : '');
        if (newUrl !== window.location.pathname + window.location.search) {
            window.history.replaceState(window.history.state, '', newUrl);
        }
    },
    { deep: true },
);

onBeforeUnmount(() => {
    clearTimeout(searchDebounce);
    window.removeEventListener('keydown', onPreviewKeydown);
});
</script>