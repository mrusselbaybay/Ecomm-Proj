<!-- resources/js/seller/components/Reports.vue -->
<template>
    <div class="report-page">
        <!-- ============ HEADER ============ -->
        <header class="prep-header">
            <div>
                <div class="prep-breadcrumb" style="margin: 0 0 0.35rem">
                    <span>Seller Portal</span>
                    <span>/</span>
                    <span>Reports</span>
                </div>
                <h2 class="prep-title">Sales &amp; Performance Reports</h2>
            </div>
        </header>

        <!-- ============ TOOLBAR (date range + export) ============
             Relocated here from the old KPI-report layout: hourly volume
             and new-vs-returning buyers below are both scoped to this
             range, so it needs to live above every widget that reads it,
             not buried in a section that no longer exists. -->
        <div class="report-toolbar">
            <div class="report-toolbar-left">
                <div class="report-daterange" ref="rangeMenuEl">
                    <button
                        type="button"
                        class="report-daterange-btn"
                        :aria-expanded="showRangeMenu"
                        aria-haspopup="true"
                        @click="showRangeMenu = !showRangeMenu"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
                        <span>{{ rangeLabel }}</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6" /></svg>
                    </button>

                    <div v-if="showRangeMenu" class="report-daterange-menu" role="menu">
                        <button
                            v-for="p in presetOptions"
                            :key="p.value"
                            type="button"
                            class="report-daterange-option"
                            :class="{ active: preset === p.value }"
                            role="menuitemradio"
                            :aria-checked="preset === p.value"
                            @click="selectPreset(p.value)"
                        >
                            {{ p.label }}
                        </button>

                        <div class="report-daterange-custom">
                            <p class="report-daterange-custom-label">Custom range</p>
                            <div class="report-daterange-custom-fields">
                                <label class="field-label" for="report-from">From</label>
                                <input id="report-from" type="date" class="field-input" v-model="customFromInput" :max="customToInput || undefined" />
                                <label class="field-label" for="report-to">To</label>
                                <input id="report-to" type="date" class="field-input" v-model="customToInput" :min="customFromInput || undefined" />
                            </div>
                            <p v-if="customRangeError" class="report-daterange-error">{{ customRangeError }}</p>
                            <button type="button" class="btn-primary btn-sm" style="width: 100%; margin-top: 0.5rem" @click="submitCustomRange">
                                Apply
                            </button>
                        </div>

                        <button
                            v-if="preset === 'custom'"
                            type="button"
                            class="report-daterange-reset"
                            @click="resetFilters(); showRangeMenu = false"
                        >
                            Reset filters
                        </button>
                    </div>
                </div>

                <p class="report-updated-note">
                    <template v-if="summary">Report data as of {{ lastUpdatedLabel }}</template>
                </p>
            </div>

            <button type="button" class="btn-primary report-export-btn" :disabled="isExporting" @click="exportCsv">
                <svg v-if="!isExporting" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
                <span v-else class="loading-spinner" style="width: 1rem; height: 1rem; border-width: 2px"></span>
                {{ isExporting ? 'Preparing export…' : 'Export CSV' }}
            </button>
        </div>
        <p v-if="exportError" class="save-msg error">{{ exportError }}</p>

        <!-- ================================================================
         WEEKLY REPORTS — adapted from the reference's bento layout.
         Every number here is real (see useReports.js / SellerReportController
         for the exact query behind each one). Two of the reference's
         widgets had nothing real to draw from and were replaced rather
         than faked:
           - "Store Performance"'s 72/100 score has no defined formula in
             the reference — this uses a disclosed real composite instead
             (see storeScore below), the same kind of applied-rule-over-
             real-data pattern the Deliveries page's on-time-rate uses.
           - "Storefront Visits" assumed a traffic/analytics table that
             doesn't exist anywhere in this schema — replaced with New vs.
             Returning Buyers, a real answer to a similar question ("who's
             actually buying here") instead of fabricated visitor counts.
         ================================================================ -->
        <p class="wr-section-label" style="margin-top: 0.3rem">KPI Store Weekly</p>
        <div v-if="summaryError && !isLoadingSummary" class="empty-state" style="margin-bottom: 1rem">
            <p style="font-weight: 700; color: #f7a49f">Couldn't load your performance summary</p>
            <p class="empty-hint">{{ summaryError }}</p>
            <button type="button" class="btn-outline btn-sm" style="margin-top: 0.5rem" @click="loadSummary">Try again</button>
        </div>
        <div class="wr-kpi-grid">
            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Orders Fulfilled</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.metrics?.deliveredOrders?.value ?? '—' }}</span>
                        <svg v-if="fulfilledSparkPoints" class="wr-kpi-viz" width="74" height="40" viewBox="0 0 74 40">
                            <g fill="#5eead4"><rect v-for="(b, i) in fulfilledSparkPoints" :key="i" :x="i * 8" :y="40 - b" width="5" height="b" rx="1" /></g>
                        </svg>
                    </div>
                    <div class="wr-kpi-foot-row">
                        vs previous period
                        <span v-if="summary?.metrics?.deliveredOrders?.trend != null" class="wr-delta" :class="trendClass(summary.metrics.deliveredOrders.trend)">{{ formatTrend(summary.metrics.deliveredOrders.trend) }}</span>
                        <span v-else class="wr-delta flat">No comparison data</span>
                    </div>
                </div>
                <button type="button" class="wr-kpi-cta" @click="goToOrdersFiltered('Delivered')">
                    See Orders <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </article>

            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Active Listings</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.activeListings ?? '—' }}</span>
                        <svg v-if="summary" class="wr-kpi-viz" width="42" height="42" viewBox="0 0 40 40">
                            <circle cx="20" cy="20" r="15" fill="none" stroke="var(--rp-surface-2)" stroke-width="6" />
                            <circle
                                cx="20" cy="20" r="15" fill="none" stroke="#5eead4" stroke-width="6" stroke-linecap="round"
                                :stroke-dasharray="`${activeListingsShare} ${94.2 - activeListingsShare}`"
                                transform="rotate(-90 20 20)"
                            />
                        </svg>
                    </div>
                    <div class="wr-kpi-foot-row">
                        <template v-if="summary">of {{ summary.totalListings }} total listings</template>
                    </div>
                </div>
                <button type="button" class="wr-kpi-cta" @click="goToInventory">
                    See Inventory <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </article>

            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Orders Placed</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.metrics?.ordersPlaced?.value ?? '—' }}</span>
                    </div>
                    <div class="wr-kpi-foot-row">
                        vs previous period
                        <span v-if="summary?.metrics?.ordersPlaced?.trend != null" class="wr-delta" :class="trendClass(summary.metrics.ordersPlaced.trend)">{{ formatTrend(summary.metrics.ordersPlaced.trend) }}</span>
                        <span v-else class="wr-delta flat">No comparison data</span>
                    </div>
                </div>
                <button type="button" class="wr-kpi-cta" @click="goToOrdersFiltered(null)">
                    See Orders <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </article>

            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Pending Returns</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.metrics?.pendingReturns?.value ?? '—' }}</span>
                    </div>
                    <div class="wr-kpi-foot-row">
                        vs previous period
                        <span v-if="summary?.metrics?.pendingReturns?.trend != null" class="wr-delta" :class="trendClass(summary.metrics.pendingReturns.trend)">{{ formatTrend(summary.metrics.pendingReturns.trend) }}</span>
                        <span v-else class="wr-delta flat">No comparison data</span>
                    </div>
                </div>
            </article>
        </div>

        <p class="wr-section-label">Report Weekly</p>
        <div class="wr-perf-grid">
            <div class="wr-card">
                <div class="wr-card-head">
                    <h3>Store Performance</h3>
                </div>
                <div class="wr-card-body wr-gauge-wrap">
                    <svg width="230" height="128" viewBox="0 0 230 128">
                        <path d="M15 118 A100 100 0 0 1 215 118" fill="none" stroke="var(--rp-surface-2)" stroke-width="18" stroke-linecap="round" />
                        <path
                            d="M15 118 A100 100 0 0 1 215 118" fill="none" stroke="#5eead4" stroke-width="18" stroke-linecap="round"
                            :stroke-dasharray="`${storeScore == null ? 0 : (storeScore / 100) * 314} 314`"
                        />
                        <text x="115" y="106" text-anchor="middle" class="num" style="font-size: 32px; font-weight: 800; fill: var(--rp-ink-900)">{{ storeScore == null ? '—' : `${storeScore}%` }}</text>
                    </svg>
                    <p class="wr-gauge-caption">{{ storeScore == null ? 'Not enough data yet this period' : 'avg. of fulfillment, non-cancellation & rating' }}</p>
                    <p class="wr-perf-title">{{ storeHeadline }}</p>
                    <p class="wr-perf-text">{{ storeNarrative }}</p>
                </div>
            </div>

            <div class="wr-card">
                <div class="wr-card-head">
                    <h3>Weekly Fulfillment</h3>
                </div>
                <div class="wr-card-body">
                    <div class="wr-legend">
                        <span><i style="background: var(--rp-surface-2)"></i>Pending</span>
                        <span><i style="background: #5eead4"></i>Shipped</span>
                    </div>
                    <div v-if="isLoadingWeeklyFulfillment && !weeklyFulfillment.length" class="report-chart-skeleton" aria-hidden="true">
                        <div class="report-skeleton-line" style="width: 100%; height: 160px; border-radius: 0.75rem"></div>
                    </div>
                    <div v-else-if="weeklyFulfillmentError" class="empty-state">
                        <p style="font-weight: 700; color: #f7a49f">Couldn't load the weekly trend</p>
                        <button type="button" class="btn-outline btn-sm" style="margin-top: 0.5rem" @click="loadWeeklyFulfillment">Try again</button>
                    </div>
                    <div v-else style="height: 180px">
                        <canvas ref="weeklyCanvasEl" role="img" aria-label="Pending vs shipped orders, last 8 weeks"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <p class="wr-section-label">View Report Weekly</p>
        <div class="wr-view-grid">
            <div class="wr-card">
                <div class="wr-card-head">
                    <h3>Order Volume by Hour</h3>
                </div>
                <div class="wr-card-body">
                    <div v-if="isLoadingHourlyVolume && !hourlyVolume" class="report-chart-skeleton" aria-hidden="true">
                        <div class="report-skeleton-line" style="width: 100%; height: 140px; border-radius: 0.75rem"></div>
                    </div>
                    <div v-else-if="hourlyVolumeError" class="empty-state">
                        <p style="font-weight: 700; color: #f7a49f">Couldn't load order volume</p>
                        <button type="button" class="btn-outline btn-sm" style="margin-top: 0.5rem" @click="loadHourlyVolume">Try again</button>
                    </div>
                    <div v-else-if="hourlyVolume && hourlyVolume.totalOrders === 0" class="empty-state">
                        <p class="empty-hint">No orders in this period to chart.</p>
                    </div>
                    <div v-else-if="hourlyVolume" class="wr-heat">
                        <div></div>
                        <div v-for="d in hourlyVolume.days" :key="d" class="h-col">{{ d }}</div>
                        <template v-for="row in hourlyVolume.rows" :key="row.label">
                            <div class="h-row">{{ row.label }}</div>
                            <div v-for="cell in row.cells" :key="cell.day" class="wr-cell" :class="cell.intensity" :title="`${cell.day}, ${row.label}: ${cell.count} order${cell.count === 1 ? '' : 's'}`"></div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="wr-card">
                <div class="wr-card-head">
                    <h3>New vs. Returning Buyers</h3>
                </div>
                <div class="wr-card-body">
                    <p class="field-hint" style="margin: -0.3rem 0 0.8rem">Replaces "storefront visits" — this project doesn't track page traffic, so this answers the closest real question instead.</p>
                    <div v-if="isLoadingCustomerMix && !customerMix" class="report-chart-skeleton" aria-hidden="true">
                        <div class="report-skeleton-line" style="width: 8rem; height: 8rem; border-radius: 50%; margin: 0 auto" />
                    </div>
                    <div v-else-if="customerMixError" class="empty-state">
                        <p style="font-weight: 700; color: #f7a49f">Couldn't load buyer mix</p>
                        <button type="button" class="btn-outline btn-sm" style="margin-top: 0.5rem" @click="loadCustomerMix">Try again</button>
                    </div>
                    <div v-else-if="customerMix && customerMix.total === 0" class="empty-state">
                        <p class="empty-hint">No orders in this period yet.</p>
                    </div>
                    <template v-else-if="customerMix">
                        <div class="wr-visit-num num">{{ customerMix.total }} <small>orders</small></div>
                        <div class="wr-visit-row">
                            <div class="wr-visit-legend">
                                <div class="wr-vl"><span class="k"><i style="background: #9dc2ef"></i>New Buyers</span><b class="num">{{ customerMix.new }} ({{ customerMix.newPercent }}%)</b></div>
                                <div class="wr-vl"><span class="k"><i style="background: #5eead4"></i>Returning Buyers</span><b class="num">{{ customerMix.returning }} ({{ customerMix.returningPercent }}%)</b></div>
                            </div>
                            <svg width="110" height="110" viewBox="0 0 120 120" style="flex-shrink: 0">
                                <circle cx="60" cy="60" r="46" fill="none" stroke="var(--rp-surface-2)" stroke-width="16" />
                                <circle
                                    cx="60" cy="60" r="46" fill="none" stroke="#5eead4" stroke-width="16" stroke-linecap="round"
                                    :stroke-dasharray="`${(customerMix.returningPercent / 100) * 289} 289`"
                                    transform="rotate(-90 60 60)"
                                />
                                <circle
                                    cx="60" cy="60" r="46" fill="none" stroke="#9dc2ef" stroke-width="16"
                                    :stroke-dasharray="`${(customerMix.newPercent / 100) * 289} 289`"
                                    :stroke-dashoffset="`${-(customerMix.returningPercent / 100) * 289}`"
                                    transform="rotate(-90 60 60)"
                                />
                            </svg>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ================================================================
         SALES & QUALITY — the metrics sellers expect on every report page
         (net revenue, order value, cancellations, ratings, top products)
         that the KPI/Weekly Fulfillment/Volume/Buyer-mix widgets above
         don't cover. Every value is pulled from the same real summary /
         top-products endpoints the rest of this page already uses — no
         new backend queries. Cancellation Rate is the one metric here
         where a rise is bad, not good, so its trend badge intentionally
         mirrors the sign (trendClass(-trend)) while the printed number
         (formatTrend(trend)) still states the real, unmirrored change.
         ================================================================ -->
        <p class="wr-section-label">Sales &amp; Quality</p>
        <div class="wr-kpi-grid">
            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Net Revenue</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.metrics?.netRevenue?.value != null ? formatCurrency(summary.metrics.netRevenue.value) : '—' }}</span>
                    </div>
                    <div class="wr-kpi-foot-row">
                        vs previous period
                        <span v-if="summary?.metrics?.netRevenue?.trend != null" class="wr-delta" :class="trendClass(summary.metrics.netRevenue.trend)">{{ formatTrend(summary.metrics.netRevenue.trend) }}</span>
                        <span v-else class="wr-delta flat">No comparison data</span>
                    </div>
                </div>
            </article>

            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Average Order Value</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.metrics?.averageOrderValue?.value != null ? formatCurrency(summary.metrics.averageOrderValue.value) : '—' }}</span>
                    </div>
                    <div class="wr-kpi-foot-row">
                        vs previous period
                        <span v-if="summary?.metrics?.averageOrderValue?.trend != null" class="wr-delta" :class="trendClass(summary.metrics.averageOrderValue.trend)">{{ formatTrend(summary.metrics.averageOrderValue.trend) }}</span>
                        <span v-else class="wr-delta flat">No comparison data</span>
                    </div>
                </div>
            </article>

            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Cancellation Rate</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.metrics?.cancellationRate?.value != null ? `${summary.metrics.cancellationRate.value}%` : '—' }}</span>
                    </div>
                    <div class="wr-kpi-foot-row">
                        vs previous period
                        <span v-if="summary?.metrics?.cancellationRate?.trend != null" class="wr-delta" :class="trendClass(-summary.metrics.cancellationRate.trend)">{{ formatTrend(summary.metrics.cancellationRate.trend) }}</span>
                        <span v-else class="wr-delta flat">No comparison data</span>
                    </div>
                </div>
                <button type="button" class="wr-kpi-cta" @click="goToOrdersFiltered('Cancelled')">
                    See Orders <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </article>

            <article class="wr-kpi">
                <div class="wr-kpi-body">
                    <div class="wr-kpi-label">Average Rating</div>
                    <div class="wr-kpi-mid">
                        <span class="wr-kpi-num num">{{ summary?.metrics?.averageRating?.value != null ? `${summary.metrics.averageRating.value}/5.0` : '—' }}</span>
                    </div>
                    <div class="wr-kpi-foot-row">
                        <template v-if="summary?.metrics?.averageRating?.value != null">
                            vs previous period
                            <span v-if="summary.metrics.averageRating.trend != null" class="wr-delta" :class="trendClass(summary.metrics.averageRating.trend)">{{ formatTrend(summary.metrics.averageRating.trend) }}</span>
                            <span v-else class="wr-delta flat">No comparison data</span>
                        </template>
                        <template v-else>No reviews yet this period</template>
                    </div>
                </div>
                <button type="button" class="wr-kpi-cta" @click="goToFeedback">
                    See Reviews <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </article>
        </div>

        <div class="wr-card" style="margin-top: 1.1rem">
            <div class="wr-card-head">
                <h3>Top Performing Products</h3>
            </div>
            <div class="wr-card-body">
                <div v-if="isLoadingTopProducts && !topProducts.length" class="report-chart-skeleton" aria-hidden="true">
                    <div v-for="n in 3" :key="n" class="report-skeleton-line" style="width: 100%; height: 2.75rem; margin-bottom: 0.6rem; border-radius: 0.6rem"></div>
                </div>
                <div v-else-if="topProductsError" class="empty-state">
                    <p style="font-weight: 700; color: #f7a49f">Couldn't load product performance</p>
                    <button type="button" class="btn-outline btn-sm" style="margin-top: 0.5rem" @click="loadTopProducts()">Try again</button>
                </div>
                <div v-else-if="!topProducts.length" class="empty-state">
                    <p class="empty-hint">No product sales in this period yet.</p>
                </div>
                <ul v-else class="wr-product-list">
                    <li v-for="p in topProducts.slice(0, 5)" :key="p.productId" class="wr-product-row">
                        <img v-if="p.image" :src="p.image" :alt="p.name" class="wr-product-thumb" />
                        <div v-else class="wr-product-thumb wr-product-thumb--empty" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></svg>
                        </div>
                        <div class="wr-product-info">
                            <p class="wr-product-name">{{ p.name }}</p>
                            <p class="wr-product-meta">{{ p.unitsSold }} sold · {{ formatCurrency(p.revenue) }}</p>
                        </div>
                        <span class="wr-delta" :class="trendClass(p.growth)">
                            <template v-if="p.growth === null">No comparison data</template>
                            <template v-else>{{ formatTrend(p.growth) }}</template>
                        </span>
                    </li>
                </ul>
            </div>
            <button type="button" class="wr-kpi-cta" @click="goToInventory">
                View All Products <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </div>
    </div>
</template>

<script setup>
import {
    Chart,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    LineController,
    LineElement,
    PointElement,
    Filler,
} from 'chart.js';
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useOrders } from '../composables/useOrders';
import { useReports } from '../composables/useReports';

Chart.register(LinearScale, CategoryScale, Tooltip, Legend, LineController, LineElement, PointElement, Filler);

const {
    preset,
    range,
    rangeLabel,
    applyPreset,
    applyCustomRange,
    resetFilters,
    initFromUrl,

    summary,
    isLoadingSummary,
    summaryError,
    loadSummary,

    // Only the raw bucket data is needed here (see fulfilledSparkPoints
    // below) — the granularity toggle and its own chart lived in the old
    // Revenue Trend card, now removed, so isLoadingRevenueTrend /
    // revenueTrendError / loadRevenueTrend / granularity aren't pulled in.
    revenueTrend,

    topProducts,
    isLoadingTopProducts,
    topProductsError,
    loadTopProducts,

    weeklyFulfillment,
    isLoadingWeeklyFulfillment,
    weeklyFulfillmentError,
    loadWeeklyFulfillment,

    hourlyVolume,
    isLoadingHourlyVolume,
    hourlyVolumeError,
    loadHourlyVolume,

    customerMix,
    isLoadingCustomerMix,
    customerMixError,
    loadCustomerMix,

    loadAll,

    isExporting,
    exportError,
    exportCsv,
} = useReports();

const { formatCurrency } = useOrders();

const presetOptions = [
    { value: 'today', label: 'Today' },
    { value: 'last7', label: 'Last 7 Days' },
    { value: 'last30', label: 'Last 30 Days' },
    { value: 'thisMonth', label: 'This Month' },
    { value: 'lastMonth', label: 'Last Month' },
];

const showRangeMenu = ref(false);
const rangeMenuEl = ref(null);
const customFromInput = ref('');
const customToInput = ref('');
const customRangeError = ref('');

function selectPreset(value) {
    applyPreset(value);
    showRangeMenu.value = false;
}

function submitCustomRange() {
    customRangeError.value = '';

    if (!customFromInput.value || !customToInput.value) {
        customRangeError.value = 'Pick both a start and end date.';

        return;
    }

    if (customToInput.value < customFromInput.value) {
        customRangeError.value = 'End date must be on or after the start date.';

        return;
    }

    applyCustomRange(customFromInput.value, customToInput.value);
    showRangeMenu.value = false;
}

function onDocClick(e) {
    if (showRangeMenu.value && rangeMenuEl.value && !rangeMenuEl.value.contains(e.target)) {
        showRangeMenu.value = false;
    }
}
function onEscKey(e) {
    if (e.key === 'Escape' && showRangeMenu.value) {
        showRangeMenu.value = false;
    }
}

const lastUpdatedLabel = computed(() => {
    if (!summary.value?.generatedAt) {
return '';
}

    return new Date(summary.value.generatedAt).toLocaleString(undefined, {
        month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    });
});

function formatTrend(v) {
    if (v === null || v === undefined) {
return 'No comparison data';
}

    const sign = v > 0 ? '+' : '';

    return `${sign}${v}%`;
}
function trendClass(v) {
    if (v === null || v === undefined) {
return 'flat';
}

    return v > 0 ? 'up' : v < 0 ? 'down' : 'flat';
}

function goToOrdersFiltered(status) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section: 'orders', statusFilter: status } }));
}
function goToInventory() {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: 'inventory' }));
}
function goToFeedback() {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: 'feedback' }));
}

// ---- Weekly Reports section ----

// Real per-bucket Delivered-order counts, already fetched for the
// revenue chart above (see SellerReportController::bucketedRevenue) —
// reused here rather than a second request for the same underlying
// data. Scaled to the same 0–40px bar height the reference's sparkline
// uses; null (not an empty/flat chart) until revenueTrend has loaded.
const fulfilledSparkPoints = computed(() => {
    if (!revenueTrend.value?.current?.length) {
return null;
}

    const counts = revenueTrend.value.current.map((b) => b.orderCount);
    const max = Math.max(...counts, 1);

    return counts.slice(-9).map((c) => Math.max(2, Math.round((c / max) * 36)));
});

// Real share of this seller's catalog that's Active, out of the total
// listing count — drawn as an arc length against a fixed 94.2px
// circumference (2πr, r=15, matching the SVG circle below).
const activeListingsShare = computed(() => {
    if (!summary.value?.totalListings) {
return 0;
}

    return Math.round((summary.value.activeListings / summary.value.totalListings) * 94.2 * 10) / 10;
});

// A disclosed real composite, not a fabricated score: the average of
// whichever of {fulfillment rate, non-cancellation rate, rating-as-%}
// are actually available this period — same "applied rule over real
// data, not invented data" pattern as SellerDeliveryController's
// ON_TIME_THRESHOLD_DAYS. Null (not 0%) when none of the three inputs
// have enough data yet.
const storeScore = computed(() => {
    const m = summary.value?.metrics;

    if (!m) {
return null;
}

    const parts = [];

    if (m.fulfillmentRate.value != null) {
parts.push(m.fulfillmentRate.value);
}

    if (m.cancellationRate.value != null) {
parts.push(100 - m.cancellationRate.value);
}

    if (m.averageRating.value != null) {
parts.push((m.averageRating.value / 5) * 100);
}

    return parts.length ? Math.round(parts.reduce((a, b) => a + b, 0) / parts.length) : null;
});

const storeHeadline = computed(() => {
    if (storeScore.value == null) {
return 'Not enough data yet';
}

    if (storeScore.value >= 85) {
return 'Your store is in great shape!';
}

    if (storeScore.value >= 60) {
return 'Your store is doing well.';
}

    return 'A few areas need attention.';
});

// Names whichever real metric is actually the weakest this period,
// rather than a static sentence — same idea as Delivery.vue's
// courierInsight(), just for the store as a whole.
const storeNarrative = computed(() => {
    const m = summary.value?.metrics;

    if (!m || storeScore.value == null) {
        return 'Once you have some order activity this period, this card will summarize how fulfillment, cancellations, and ratings are trending.';
    }

    const candidates = [
        m.cancellationRate.value != null && m.cancellationRate.value >= 10
            ? `Cancellations are running at ${m.cancellationRate.value}% this period — worth a look at what's driving them.`
            : null,
        m.fulfillmentRate.value != null && m.fulfillmentRate.value < 90
            ? `Fulfillment rate is at ${m.fulfillmentRate.value}% — some orders aren't making it to Delivered.`
            : null,
        m.averageRating.value != null && m.averageRating.value < 4
            ? `Average rating is ${m.averageRating.value}/5.0 this period — recent reviews may be worth a read.`
            : null,
    ].filter(Boolean);

    if (candidates.length) {
return candidates[0];
}

    return 'Fulfillment, cancellations, and ratings are all holding up well this period — keep it up!';
});

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

// Chart.js doesn't pick up this page's CSS variables — its own default
// tick/label color is a dark gray, unreadable against the dark surface
// every chart on this reskinned page now sits on.
const CHART_TICK_COLOR = '#97a099';
const CHART_GRID_COLOR = 'rgba(255, 255, 255, 0.06)';

// ---- weekly Pending-vs-Shipped bar chart ----
const weeklyCanvasEl = ref(null);
let weeklyChart = null;

function renderWeeklyChart() {
    if (!weeklyCanvasEl.value || !weeklyFulfillment.value.length) {
return;
}

    const labels = weeklyFulfillment.value.map((w) => w.label.replace('Week ', 'Wk '));

    weeklyChart?.destroy();
    weeklyChart = new Chart(weeklyCanvasEl.value, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Pending',
                    data: weeklyFulfillment.value.map((w) => w.pending),
                    borderColor: '#6d766e',
                    backgroundColor: 'transparent',
                    borderDash: [5, 4],
                    tension: 0.3,
                    pointRadius: 2,
                    pointBackgroundColor: '#6d766e',
                    borderWidth: 2,
                },
                {
                    label: 'Shipped',
                    data: weeklyFulfillment.value.map((w) => w.shipped),
                    borderColor: '#5eead4',
                    backgroundColor: 'rgba(20, 184, 166, 0.14)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointBackgroundColor: '#5eead4',
                    borderWidth: 3,
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
                x: { grid: { display: false }, ticks: { color: CHART_TICK_COLOR } },
                y: { beginAtZero: true, grid: { color: CHART_GRID_COLOR }, ticks: { color: CHART_TICK_COLOR, precision: 0 } },
            },
        },
    });
}

// Watches both the data AND the canvas element itself (not just the
// data) — the canvas only exists in the DOM once loading/error states
// clear (see the v-else below), so if the ref becomes available on a
// different render tick than the data does, either change alone must
// still be able to trigger the first real draw.
watch([weeklyFulfillment, weeklyCanvasEl], () => nextTick(renderWeeklyChart));

onMounted(() => {
    initFromUrl();
    customFromInput.value = range.value.from;
    customToInput.value = range.value.to;
    loadAll();
    loadWeeklyFulfillment();
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onEscKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onEscKey);
    weeklyChart?.destroy();
});

// Same 30s poll rhythm as Orders.vue/Dashboard.vue — sales figures here
// shouldn't need a page reload to reflect an order placed a minute ago.
const REPORTS_POLL_MS = 30 * 1000;
let reportsPollTimer = null;

onMounted(() => {
    reportsPollTimer = setInterval(() => {
        loadAll();
        loadWeeklyFulfillment();
    }, REPORTS_POLL_MS);
});

onBeforeUnmount(() => {
    clearInterval(reportsPollTimer);
});
</script>

<style scoped>
/* ============================================================
   REPORTS — dark reskin (matches Dashboard / Orders / Inventory /
   Order Preparation / Order Details / Courier Handover / Delivery).
   Scoped to this component, so targeting shared class names here
   (.card, .report-kpi-card, .field-input, .btn-outline, .empty-state,
   .info-tip, .dot-*, ...) only ever affects what this page renders —
   every other page keeps using the same class names, unaffected, in
   their own light-mode originals.
   ============================================================ */
.report-page {
    --rp-surface: #161b17;
    --rp-surface-2: #1d231e;
    --rp-border: rgba(255, 255, 255, 0.08);
    --rp-border-soft: rgba(255, 255, 255, 0.06);
    --rp-ink-900: #f2f4f1;
    --rp-ink-700: #c6cbc5;
    --rp-ink-500: #97a099;
    --rp-ink-400: #6d766e;
    color: var(--rp-ink-900);
}

.report-page .field-hint {
    color: var(--rp-ink-500);
}

/* ============================================================
   WEEKLY REPORTS (adapted from the reference's .wr-* bento layout)
   ============================================================ */
.wr-section-label {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--rp-ink-900);
    margin: 1.7rem 0 0.9rem;
}
.wr-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.1rem;
    margin-top: 1.3rem;
}
.wr-kpi {
    background: var(--rp-surface);
    border: 1px solid var(--rp-border);
    border-radius: 1rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.wr-kpi-body {
    padding: 1.15rem 1.25rem 1.1rem;
    flex: 1;
}
.wr-kpi-label {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--rp-ink-700);
}
.wr-kpi-mid {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 0.5rem;
}
.wr-kpi-num {
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1;
    color: var(--rp-ink-900);
}
.wr-kpi-viz {
    flex-shrink: 0;
}
.wr-kpi-foot-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.7rem;
    font-size: 0.75rem;
    color: var(--rp-ink-500);
}
.wr-delta {
    display: inline-flex;
    align-items: center;
    gap: 0.15rem;
    font-size: 0.72rem;
    font-weight: 800;
    padding: 0.08rem 0.4rem;
    border-radius: 999px;
}
.wr-delta.up {
    color: #5eead4;
    background: rgba(15, 118, 110, 0.2);
}
.wr-delta.down {
    color: #f7a49f;
    background: rgba(200, 67, 61, 0.2);
}
.wr-delta.flat {
    color: var(--rp-ink-500);
    background: var(--rp-surface-2);
}
.wr-kpi-cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 0.8rem;
    border-top: 1px solid var(--rp-border-soft);
    background: var(--rp-surface-2);
    border-left: none;
    border-right: none;
    border-bottom: none;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--rp-ink-700);
    cursor: pointer;
    width: 100%;
}
.wr-kpi-cta:hover {
    color: #5eead4;
}

.wr-perf-grid {
    display: grid;
    grid-template-columns: 0.82fr 1.45fr;
    gap: 1.1rem;
    align-items: start;
}
.wr-view-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 1.1rem;
    align-items: start;
}
.wr-card {
    background: var(--rp-surface);
    border: 1px solid var(--rp-border);
    border-radius: 1rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.wr-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--rp-border-soft);
}
.wr-card-head h3 {
    font-size: 0.92rem;
    font-weight: 800;
    color: var(--rp-ink-900);
}
.wr-card-body {
    padding: 1.25rem;
    flex: 1;
}

.wr-gauge-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}
.wr-gauge-caption {
    font-size: 0.75rem;
    color: var(--rp-ink-500);
    margin-top: -0.4rem;
}
.wr-perf-title {
    font-size: 0.92rem;
    font-weight: 800;
    color: var(--rp-ink-900);
    margin: 1.1rem 0 0.35rem;
}
.wr-perf-text {
    font-size: 0.8rem;
    color: var(--rp-ink-500);
    line-height: 1.55;
}
.wr-improve {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 0.85rem;
    border-top: 1px solid var(--rp-border-soft);
    background: var(--rp-surface-2);
    border-left: none;
    border-right: none;
    border-bottom: none;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--rp-ink-700);
    cursor: pointer;
    width: 100%;
}
.wr-improve:hover {
    color: #5eead4;
}

.wr-legend {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 0.72rem;
    color: var(--rp-ink-500);
    margin-bottom: 0.9rem;
}
.wr-legend span {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.wr-legend i {
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 0.2rem;
    display: inline-block;
}

.wr-heat {
    display: grid;
    grid-template-columns: 5.5rem repeat(7, 1fr);
    gap: 0.4rem;
    align-items: center;
}
.wr-heat .h-col {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--rp-ink-400);
    text-align: center;
}
.wr-heat .h-row {
    font-size: 0.72rem;
    color: var(--rp-ink-500);
    white-space: nowrap;
}
.wr-cell {
    height: 1.7rem;
    border-radius: 0.35rem;
    background: var(--rp-surface-2);
    cursor: default;
}
.wr-cell.mid {
    background: rgba(20, 184, 166, 0.35);
}
.wr-cell.on {
    background: #14b8a6;
}

.wr-visit-num {
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--rp-ink-900);
}
.wr-visit-num small {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--rp-ink-500);
}
.wr-visit-row {
    display: flex;
    align-items: center;
    gap: 1.1rem;
    margin-top: 0.6rem;
}
.wr-visit-legend {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    flex: 1;
}
.wr-vl {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.78rem;
}
.wr-vl .k {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: var(--rp-ink-700);
}
.wr-vl i {
    width: 0.6rem;
    height: 0.6rem;
    border-radius: 0.2rem;
    display: inline-block;
    flex-shrink: 0;
}
.wr-vl b {
    font-weight: 800;
    color: var(--rp-ink-900);
    white-space: nowrap;
}

.wr-product-list {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}
.wr-product-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.wr-product-thumb {
    width: 2.6rem;
    height: 2.6rem;
    border-radius: 0.5rem;
    object-fit: cover;
    flex-shrink: 0;
    background: var(--rp-surface-2);
}
.wr-product-thumb--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--rp-ink-400);
}
.wr-product-info {
    flex: 1;
    min-width: 0;
}
.wr-product-name {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--rp-ink-900);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wr-product-meta {
    font-size: 0.76rem;
    color: var(--rp-ink-500);
    margin-top: 0.15rem;
}

@media (max-width: 1180px) {
    .wr-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .wr-perf-grid,
    .wr-view-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 640px) {
    .wr-heat {
        grid-template-columns: 4rem repeat(7, minmax(1.6rem, 1fr));
        font-size: 0.65rem;
    }
}

.report-page .prep-title {
    color: var(--rp-ink-900);
}
.report-page .prep-breadcrumb {
    color: var(--rp-ink-500);
}
.report-page .field-input {
    background: var(--rp-surface-2);
    border-color: var(--rp-border);
    color: var(--rp-ink-900);
}
.report-page .field-label {
    color: var(--rp-ink-500);
}
.report-page .btn-outline {
    background: var(--rp-surface-2);
    border-color: var(--rp-border);
    color: var(--rp-ink-900);
}
.report-page .btn-outline:hover:not(:disabled) {
    background: var(--rp-surface);
}
.report-page .save-msg.error {
    color: #f7a49f;
}
.report-page .loading-spinner {
    border-color: var(--rp-border);
    border-top-color: #5eead4;
}
.report-page .report-skeleton-line {
    background: var(--rp-surface-2);
}

/* ---- toolbar / date range ---- */
.report-page .report-updated-note {
    color: var(--rp-ink-500);
}
.report-page .report-daterange-btn {
    background: var(--rp-surface-2);
    border-color: var(--rp-border);
    color: var(--rp-ink-900);
}
.report-page .report-daterange-menu {
    background: var(--rp-surface);
    border-color: var(--rp-border);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
}
.report-page .report-daterange-option {
    color: var(--rp-ink-700);
}
.report-page .report-daterange-option:hover {
    background: var(--rp-surface-2);
}
.report-page .report-daterange-option.active {
    background: rgba(20, 184, 166, 0.16);
    color: #5eead4;
}
.report-page .report-daterange-custom {
    border-top-color: var(--rp-border-soft);
}
.report-page .report-daterange-custom-label {
    color: var(--rp-ink-500);
}
.report-page .report-daterange-error {
    color: #f7a49f;
}
.report-page .report-daterange-reset {
    color: #5eead4;
}

/* ---- empty states (retry/empty messages inside the Weekly Reports
   cards, e.g. "No orders in this period yet.") ---- */
.report-page .empty-hint {
    color: var(--rp-ink-500);
}
</style>