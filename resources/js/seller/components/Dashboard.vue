<!-- resources/js/seller/components/Dashboard.vue -->
<template>
    <div>
        <!-- Quick Actions Launchpad -->
        <div class="card launchpad">
            <div class="launchpad-head">
                <span class="launchpad-icon">
                    <svg
                        width="18"
                        height="18"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path d="M13 2 3 14h7l-1 8 10-12h-7l1-8Z" />
                    </svg>
                </span>
                <p class="launchpad-label">Quick Actions Launchpad</p>
            </div>
            <div class="launchpad-actions">
                <button class="btn-primary" @click="goTo('inventory')">
                    <svg
                        class="icon"
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >
                        <circle cx="10" cy="10" r="8" />
                        <path d="M10 6v8M6 10h8" />
                    </svg>
                    Add New Product
                </button>
                <button class="btn-outline" @click="goTo('orders')">
                    <svg
                        class="icon"
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                    >
                        <rect x="4" y="3" width="12" height="15" rx="1.5" />
                        <path d="M7.5 1.5h5v3h-5zM7 9h6M7 12h6M7 15h4" />
                    </svg>
                    Manage Active Orders
                </button>
                <button class="btn-blue-outline" @click="goTo('reports')">
                    <svg
                        class="icon"
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                    >
                        <path
                            d="M6 2.5h6l3 3v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1z"
                        />
                        <path d="M8 10h5M8 13h5M12 2.5V6h3.5" />
                    </svg>
                    Generate Sales Report
                </button>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="metric-grid grid">
            <div class="metric-card">
                <div class="metric-card-top">
                    <span class="metric-icon emerald"
                        ><svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M3 17l6-6 4 4 8-8" />
                            <path d="M15 7h6v6" /></svg
                    ></span>
                    <span class="metric-chip" :class="changeChipClass(salesChangeLabel)">{{
                        salesChangeLabel
                    }}</span>
                </div>
                <p class="metric-label">Total Sales</p>
                <h4 class="metric-value">{{ formatCurrencyValue(totalSales) }}</h4>
                <p class="metric-sub">Gross value of every order</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <span class="metric-icon sky"
                        ><svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                d="M12 2v20M17 5.5c0-1.9-2.2-3.5-5-3.5s-5 1.6-5 3.5S9.2 9 12 9s5 1.6 5 3.5-2.2 3.5-5 3.5-5-1.6-5-3.5"
                            /></svg
                    ></span>
                    <span class="metric-chip" :class="changeChipClass(revenueChangeLabel)">{{
                        revenueChangeLabel
                    }}</span>
                </div>
                <p class="metric-label">Total Revenue</p>
                <h4 class="metric-value">{{ formatCurrencyValue(totalRevenue) }}</h4>
                <p class="metric-sub">Collected from paid orders</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <span class="metric-icon orange"
                        ><svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"
                            />
                            <path d="M3 6h18M16 10a4 4 0 0 1-8 0" /></svg
                    ></span>
                    <span
                        class="metric-chip"
                        :class="thisWeekOrders.length > 0 ? 'up' : 'flat'"
                    >{{ thisWeekOrders.length > 0 ? '+' + thisWeekOrders.length + ' this week' : 'No new' }}</span>
                </div>
                <p class="metric-label">Total Orders</p>
                <h4 class="metric-value">{{ orders.length }} Orders</h4>
                <p class="metric-sub">All-time, every status</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <span class="metric-icon amber"
                        ><svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 7v5l3 3" /></svg
                    ></span>
                    <span class="metric-chip" :class="pendingOrdersCount > 0 ? 'up' : 'flat'"
                        >{{ pendingOrdersCount > 0 ? 'Needs Action' : 'All Clear' }}</span
                    >
                </div>
                <p class="metric-label">Pending Orders</p>
                <h4 class="metric-value">{{ pendingOrdersCount }} Orders</h4>
                <p class="metric-sub">Placed, awaiting acceptance</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <span class="metric-icon emerald"
                        ><svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                            <path d="m9 11 3 3L22 4" /></svg
                    ></span>
                    <span class="metric-chip flat">{{ fulfillmentRateLabel }}</span>
                </div>
                <p class="metric-label">Completed Orders</p>
                <h4 class="metric-value">{{ completedOrdersCount }} Orders</h4>
                <p class="metric-sub">Delivered to the buyer</p>
            </div>

            <div class="metric-card">
                <div class="metric-card-top">
                    <span class="metric-icon blue"
                        ><svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="m12 2 9 5-9 5-9-5 9-5Z" />
                            <path d="m3 12 9 5 9-5" />
                            <path d="m3 17 9 5 9-5" /></svg
                    ></span>
                    <span
                        class="metric-chip"
                        :class="lowStockProductsCount > 0 ? 'down' : 'flat'"
                    >{{ lowStockProductsCount > 0 ? lowStockProductsCount + ' low' : 'Healthy' }}</span>
                </div>
                <p class="metric-label">Inventory</p>
                <h4 class="metric-value">{{ activeProductsCount }} Listed</h4>
                <p class="metric-sub">
                    <template v-if="lowStockProductsCount > 0">
                        {{ lowStockProductsCount }} product{{ lowStockProductsCount === 1 ? '' : 's' }} need restocking
                    </template>
                    <template v-else>All products in stock</template>
                </p>
            </div>
        </div>

        <!-- Sales Trend + Order Breakdown -->
        <div class="chart-row grid">
            <div class="card chart-card">
                <div class="chart-card-head">
                    <div>
                        <p class="chart-title">Sales Performance Trend</p>
                        <p class="chart-sub">Revenue over the past 7 days</p>
                    </div>
                    <div class="chart-toggle">
                        <button class="active" type="button">Weekly</button>
                        <button type="button">Monthly</button>
                    </div>
                </div>
                <div style="height: 220px; width: 100%; position: relative">
                    <svg
                        viewBox="0 0 800 200"
                        style="width: 100%; height: 100%; overflow: visible"
                    >
                        <defs>
                            <linearGradient
                                id="seller-line-gradient"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stop-color="#1b9ba8"
                                    stop-opacity="0.3"
                                ></stop>
                                <stop
                                    offset="100%"
                                    stop-color="#1b9ba8"
                                    stop-opacity="0"
                                ></stop>
                            </linearGradient>
                        </defs>
                        <path
                            :d="salesTrendLinePath"
                            fill="none"
                            stroke="#1b9ba8"
                            stroke-width="4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        ></path>
                        <path
                            :d="salesTrendAreaPath"
                            fill="url(#seller-line-gradient)"
                        ></path>
                        <g v-for="(pt, idx) in salesTrendPoints" :key="idx">
                            <circle
                                :cx="pt.x"
                                :cy="pt.y"
                                :r="pt === salesTrendPeak ? 6 : 4"
                                fill="#1b9ba8"
                                stroke="white"
                                stroke-width="3"
                            ></circle>
                            <circle
                                :cx="pt.x"
                                :cy="pt.y"
                                r="18"
                                fill="transparent"
                                style="cursor: pointer"
                            >
                                <title>{{ pt.label }}: {{ formatCurrencyValue(pt.total) }}</title>
                            </circle>
                        </g>
                    </svg>
                </div>
                <div class="chart-x-labels">
                    <span v-for="(day, idx) in salesTrendDays" :key="idx">{{
                        day.label
                    }}</span>
                </div>
            </div>

            <div class="card chart-card">
                <div class="chart-card-head">
                    <p class="chart-title">Order Breakdown</p>
                    <span class="chart-live-tag">Live</span>
                </div>
                <div
                    class="flex items-center justify-center"
                    style="flex-direction: column"
                >
                    <div
                        style="
                            position: relative;
                            width: 9.5rem;
                            height: 9.5rem;
                            margin-bottom: 1.5rem;
                        "
                    >
                        <svg
                            viewBox="0 0 36 36"
                            style="
                                width: 100%;
                                height: 100%;
                                transform: rotate(-90deg);
                            "
                        >
                            <circle
                                cx="18"
                                cy="18"
                                r="15.9"
                                fill="transparent"
                                stroke="#e2e8f0"
                                stroke-width="4"
                            ></circle>
                            <circle
                                v-for="seg in orderDonutSegments"
                                :key="seg.key"
                                cx="18"
                                cy="18"
                                r="15.9"
                                fill="transparent"
                                :stroke="seg.color"
                                stroke-width="4"
                                :stroke-dasharray="`${seg.pct} ${100 - seg.pct}`"
                                :stroke-dashoffset="seg.dashoffset"
                            ></circle>
                        </svg>
                        <div
                            class="flex items-center justify-center"
                            style="
                                position: absolute;
                                inset: 0;
                                flex-direction: column;
                            "
                        >
                            <span class="donut-center-value">{{ orderBreakdownTotal }}</span>
                            <span class="donut-center-label">Orders</span>
                        </div>
                    </div>
                    <div
                        v-if="orderBreakdownTotal === 0"
                        class="empty-state"
                        style="padding: 0 0 1rem"
                    >
                        <p>No orders yet.</p>
                    </div>
                    <div v-else style="width: 100%">
                        <div
                            v-for="seg in orderDonutSegments"
                            :key="seg.key"
                            class="donut-legend-row"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="legend-dot"
                                    :style="{ background: seg.color }"
                                ></span
                                ><span>{{ seg.label }} ({{ seg.count }})</span>
                            </div>
                            <strong>{{ seg.pct }}%</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Sales Records + Live Store Activity -->
        <div class="bottom-grid mb-6 grid">
            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Recent Sales Records</h3>
                    <a
                        href="#"
                        class="panel-link"
                        @click.prevent="goTo('orders')"
                        >View All Transactions</a
                    >
                </div>
                <div style="overflow-x: auto">
                    <table class="sales-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="recentOrders.length === 0">
                                <td colspan="6" style="text-align: center; color: #94a3b8; padding: 1.5rem 0">
                                    No orders yet.
                                </td>
                            </tr>
                            <tr v-for="row in recentOrders" :key="row.id">
                                <td class="order-id">{{ row.id }}</td>
                                <td class="customer">{{ row.customer }}</td>
                                <td class="item-name">{{ orderItemsSummary(row) }}</td>
                                <td>{{ row.date }}</td>
                                <td class="amount">{{ formatCurrency(row.total) }}</td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="orderStatusBadgeClass(row.status)"
                                        >{{ row.status }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Live Store Activity</h3>
                    <div class="flex items-center gap-3">
                        <button
                            class="notif-btn"
                            title="Refresh status"
                            style="padding: 0.3rem"
                            @click="refresh"
                            :disabled="isRefreshing"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                :style="{
                                    animation: isRefreshing
                                        ? 'spin 0.7s linear infinite'
                                        : 'none',
                                }"
                            >
                                <path
                                    d="M3 10a7 7 0 0 1 12-5l1.5 1.5M17 10a7 7 0 0 1-12 5L3.5 13.5"
                                />
                                <path d="M14.5 3v3.5H11M5.5 17v-3.5H9" />
                            </svg>
                        </button>
                        <span class="live-dot"></span>
                    </div>
                </div>
                <div class="activity-panel">
                    <div
                        v-if="activityLog.length === 0"
                        class="empty-state"
                        style="padding: 1rem 0"
                    >
                        <p>No activity yet.</p>
                        <p class="empty-hint">
                            Account status changes will show up here.
                        </p>
                    </div>
                    <div
                        v-for="item in visibleActivityLog"
                        :key="item.id"
                        class="activity-row"
                    >
                        <div class="activity-icon-badge teal">
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v5l3 3" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="activity-text">
                                Status changed to
                                <span style="text-transform: capitalize">{{
                                    item.new_status
                                }}</span>
                            </p>
                            <p class="activity-time">
                                {{ formatDateTime(item.created_at)
                                }}<span v-if="item.reason">
                                    — {{ item.reason }}</span
                                >
                            </p>
                        </div>
                    </div>
                    <button
                        v-if="visibleActivityLog.length"
                        class="activity-clear-btn"
                        @click="visibleActivityLog = []"
                    >
                        Clear Activity Log
                    </button>
                </div>
            </div>
        </div>

        <!-- Best-Selling Products + Low-Stock Products -->
        <div class="bottom-grid mb-6 grid">
            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Best-Selling Products</h3>
                    <a href="#" class="panel-link" @click.prevent="goTo('inventory')">View Inventory</a>
                </div>

                <ol v-if="bestSellers.length" class="rank-list">
                    <li v-for="(p, i) in bestSellers" :key="p.name" class="rank-item">
                        <span class="rank-num">{{ i + 1 }}</span>
                        <div class="rank-body">
                            <div class="rank-row">
                                <span class="rank-name">{{ p.name }}</span>
                                <span class="rank-units">{{ p.units }} sold</span>
                            </div>
                            <div class="rank-bar"><span :style="{ width: p.pct + '%' }"></span></div>
                            <span class="rank-sub">{{ formatCurrency(p.revenue) }} in sales</span>
                        </div>
                    </li>
                </ol>
                <div v-else class="empty-state" style="padding: 1.5rem 0">
                    <p>No sales yet.</p>
                </div>
            </div>

            <div class="card panel-card">
                <div class="panel-head">
                    <h3>Low-Stock Products</h3>
                    <a href="#" class="panel-link" @click.prevent="goTo('inventory')">Manage Stock</a>
                </div>

                <ul v-if="lowStockItems.length" class="stock-list">
                    <li v-for="p in lowStockItems" :key="p.id" class="stock-item">
                        <span class="stock-name">{{ p.name }}</span>
                        <span
                            class="stock-qty"
                            :class="p.stock === 0 ? 'is-out' : 'is-low'"
                        >{{ p.stock === 0 ? 'Out of stock' : p.stock + ' left' }}</span>
                    </li>
                </ul>
                <div v-else class="empty-state" style="padding: 1.5rem 0">
                    <p>Every product is well stocked.</p>
                </div>
            </div>
        </div>

        <!-- Account & Compliance (existing onboarding functionality, preserved) -->
        <p class="section-label" style="margin-bottom: 0.85rem">
            Account &amp; Compliance
        </p>
        <div class="grid-2col mb-6 grid">
            <div class="card" style="padding: 1.4rem 1.5rem">
                <div
                    class="flex items-center justify-between"
                    style="margin-bottom: 1rem"
                >
                    <div>
                        <p class="section-label">Store Readiness</p>
                        <p class="section-sub">
                            Complete these steps to keep your store in good
                            standing.
                        </p>
                    </div>
                </div>
                <div class="checklist">
                    <div class="checklist-item">
                        <span
                            class="checklist-icon"
                            :class="hasProfileInfo ? 'done' : 'todo'"
                        >
                            <svg
                                class="icon-xs"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M4 10l4 4 8-8" />
                            </svg>
                        </span>
                        <div>
                            <p class="checklist-title">Personal information</p>
                            <p class="checklist-desc">
                                Name, sex, birthday, and contact number on file.
                            </p>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <span
                            class="checklist-icon"
                            :class="hasAddress ? 'done' : 'todo'"
                        >
                            <svg
                                class="icon-xs"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M4 10l4 4 8-8" />
                            </svg>
                        </span>
                        <div>
                            <p class="checklist-title">Store address</p>
                            <p class="checklist-desc">
                                Province, municipality, barangay, and street
                                address.
                            </p>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <span
                            class="checklist-icon"
                            :class="hasBusinessInfo ? 'done' : 'todo'"
                        >
                            <svg
                                class="icon-xs"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M4 10l4 4 8-8" />
                            </svg>
                        </span>
                        <div>
                            <p class="checklist-title">Business details</p>
                            <p class="checklist-desc">
                                Business name and line of business selected.
                            </p>
                        </div>
                    </div>
                    <div class="checklist-item">
                        <span
                            class="checklist-icon"
                            :class="
                                verifiedDocsCount === totalDocsCount &&
                                totalDocsCount > 0
                                    ? 'done'
                                    : 'todo'
                            "
                        >
                            <svg
                                class="icon-xs"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M4 10l4 4 8-8" />
                            </svg>
                        </span>
                        <div>
                            <p class="checklist-title">Compliance documents</p>
                            <p class="checklist-desc">
                                Valid ID and business permit verified by admin.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="padding: 1.4rem 1.5rem">
                <p class="section-label">Document Compliance</p>
                <p class="section-sub" style="margin-bottom: 1rem">Today</p>

                <div v-if="totalDocsCount === 0" class="empty-state">
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                    >
                        <path
                            d="M6 2.5h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-17a1 1 0 0 1 1-1z"
                        />
                    </svg>
                    <p>No documents submitted yet.</p>
                </div>
                <div v-else class="donut-wrap">
                    <svg width="130" height="130" viewBox="0 0 130 130">
                        <circle
                            v-for="(seg, idx) in donutSegments"
                            :key="idx"
                            cx="65"
                            cy="65"
                            r="54"
                            fill="none"
                            :stroke="seg.color"
                            stroke-width="16"
                            :stroke-dasharray="`${seg.dash} ${circumference - seg.dash}`"
                            :stroke-dashoffset="seg.offset"
                            transform="rotate(-90 65 65)"
                        />
                    </svg>
                    <div class="donut-legend">
                        <div class="donut-legend-item">
                            <span
                                class="donut-legend-dot"
                                style="background: var(--teal-500)"
                            ></span
                            >Verified ({{ verifiedDocsCount }})
                        </div>
                        <div class="donut-legend-item">
                            <span
                                class="donut-legend-dot"
                                style="background: #f59e0b"
                            ></span
                            >Pending ({{ pendingDocsCount }})
                        </div>
                        <div class="donut-legend-item">
                            <span
                                class="donut-legend-dot"
                                style="background: #ef4444"
                            ></span
                            >Rejected ({{ rejectedDocsCount }})
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documents -->
        <div class="card" style="padding: 1.4rem 1.5rem">
            <p class="section-label" style="margin-bottom: 1rem">
                My Documents
            </p>
            <div v-if="documents.length === 0" class="empty-state">
                <svg
                    class="icon-lg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                >
                    <path
                        d="M6 2.5h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-17a1 1 0 0 1 1-1z"
                    />
                </svg>
                <p>No documents on file.</p>
                <p class="empty-hint">
                    Documents you submitted during registration will appear
                    here.
                </p>
            </div>
            <div v-else class="doc-list">
                <div v-for="doc in documents" :key="doc.id" class="doc-row">
                    <div class="doc-info">
                        <div class="avatar">
                            {{
                                docTypeLabel(doc.doc_type)
                                    .slice(0, 2)
                                    .toUpperCase()
                            }}
                        </div>
                        <div>
                            <p class="doc-type">
                                {{ docTypeLabel(doc.doc_type) }}
                            </p>
                            <p class="doc-date">
                                Submitted {{ formatDate(doc.created_at) }}
                            </p>
                        </div>
                    </div>
                    <span class="badge" :class="statusBadgeClass(doc.status)">{{
                        doc.status
                    }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useOrders } from '../composables/useOrders';
import { useSeller } from '../composables/useSeller';
import { useSellerProducts } from '../composables/useSellerProducts';

const {
    profile,
    address,
    sellerDetails,
    documents,
    activityLog,
    verifiedDocsCount,
    pendingDocsCount,
    totalDocsCount,
    refreshAll,
    formatDate,
    formatDateTime,
    docTypeLabel,
    statusBadgeClass,
} = useSeller();

const {
    orders,
    loadOrders,
    statusBadgeClass: orderStatusBadgeClass,
    formatCurrency,
} = useOrders();

const {
    products,
    loadProducts,
    stockStatusOf,
} = useSellerProducts();

onMounted(() => {
    loadOrders();
    loadProducts();
});

const isRefreshing = ref(false);

async function refresh() {
    isRefreshing.value = true;

    try {
        await Promise.all([refreshAll(), loadOrders(), loadProducts()]);
    } finally {
        isRefreshing.value = false;
    }
}

function goTo(section) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: section }));
}

// ---------------------------------------------------------------
// REAL METRICS — sourced from the same orders/products the Orders and
// Inventory pages use (useOrders / useSellerProducts), not mock data.
// ---------------------------------------------------------------

function formatCurrencyValue(n) {
    return `₱${Number(n || 0).toFixed(2)}`;
}

function sumTotals(list) {
    return list.reduce((sum, o) => sum + (Number(o.total) || 0), 0);
}

// orders.*.placedAt is a real ISO timestamp (SellerOrderController@
// transformSummary) — used here instead of the pre-formatted `date`
// display string so week-over-week comparisons are actually reliable.
function ordersPlacedBetween(startMsAgo, endMsAgo) {
    const now = Date.now();
    const start = now - startMsAgo;
    const end = now - endMsAgo;

    return orders.value.filter((o) => {
        if (!o.placedAt) {
            return false;
        }

        const t = new Date(o.placedAt).getTime();

        return t > start && t <= end;
    });
}

const DAY_MS = 24 * 60 * 60 * 1000;
const thisWeekOrders = computed(() => ordersPlacedBetween(7 * DAY_MS, 0));
const lastWeekOrders = computed(() => ordersPlacedBetween(14 * DAY_MS, 7 * DAY_MS));

function pctChangeLabel(current, previous) {
    if (previous === 0) {
        return current > 0 ? '+100.0%' : '0.0%';
    }

    const change = ((current - previous) / previous) * 100;

    return `${change >= 0 ? '+' : ''}${change.toFixed(1)}%`;
}

function changeChipClass(label) {
    if (label.startsWith('+')) {
return 'up';
}

    if (label.startsWith('-')) {
return 'down';
}

    return 'flat';
}

// "Total Sales" = gross value of every order placed (sales volume);
// "Total Revenue" = value of orders actually paid — two genuinely
// different real numbers rather than the same figure twice.
const totalSales = computed(() => sumTotals(orders.value));
const totalRevenue = computed(
    () => sumTotals(orders.value.filter((o) => o.paymentStatus === 'Paid')),
);
const salesChangeLabel = computed(() =>
    pctChangeLabel(sumTotals(thisWeekOrders.value), sumTotals(lastWeekOrders.value)),
);
const revenueChangeLabel = computed(() =>
    pctChangeLabel(
        sumTotals(thisWeekOrders.value.filter((o) => o.paymentStatus === 'Paid')),
        sumTotals(lastWeekOrders.value.filter((o) => o.paymentStatus === 'Paid')),
    ),
);

const activeProductsCount = computed(
    () => products.value.filter((p) => p.status === 'active').length,
);
const lowStockProductsCount = computed(
    () => products.value.filter((p) => stockStatusOf(p) === 'low_stock').length,
);

// The actual low/out-of-stock products (not just a count) so the
// dashboard can list what needs restocking, worst first.
const lowStockItems = computed(() =>
    products.value
        .filter((p) => ['low_stock', 'out_of_stock'].includes(stockStatusOf(p)))
        .map((p) => ({ id: p.id, name: p.name, stock: Number(p.stock) || 0 }))
        .sort((a, b) => a.stock - b.stock)
        .slice(0, 6),
);

// "New" = placed but not yet accepted by the seller — the real
// equivalent of "pending" for orders (this used to accidentally show
// pendingDocsCount, a completely unrelated document-verification
// figure — fixed here to use real order data).
const pendingOrdersCount = computed(
    () => orders.value.filter((o) => o.status === 'New').length,
);
// "Completed" = fulfilled all the way to Delivered.
const completedOrdersCount = computed(
    () => orders.value.filter((o) => o.status === 'Delivered').length,
);
// Delivered / (Delivered + Cancelled) — orders still in flight aren't
// counted either way, matching Reports' fulfillment-rate definition.
const fulfillmentRateLabel = computed(() => {
    const delivered = completedOrdersCount.value;
    const cancelled = orders.value.filter((o) => o.status === 'Cancelled').length;
    const settled = delivered + cancelled;

    return settled === 0 ? '—' : `${Math.round((delivered / settled) * 100)}% fulfilled`;
});

// ---- Best-Selling Products (units sold across every non-cancelled
// order, from the same order data the Orders page loads) ----
const bestSellers = computed(() => {
    const tally = new Map();

    for (const order of orders.value) {
        if (order.status === 'Cancelled' || !order.items?.length) {
            continue;
        }

        for (const item of order.items) {
            const key = item.name || 'Unnamed product';
            const row = tally.get(key) || { name: key, units: 0, revenue: 0 };

            row.units += Number(item.qty) || 0;
            row.revenue += (Number(item.qty) || 0) * (Number(item.price) || 0);
            tally.set(key, row);
        }
    }

    const rows = [...tally.values()].sort((a, b) => b.units - a.units).slice(0, 5);
    const top = rows[0]?.units || 1;

    return rows.map((r) => ({ ...r, pct: Math.round((r.units / top) * 100) }));
});

// ---- Sales Performance Trend (last 7 days, real daily totals) ----
const salesTrendDays = computed(() => {
    const days = [];
    const now = new Date();

    for (let i = 6; i >= 0; i--) {
        const d = new Date(now);
        d.setDate(d.getDate() - i);
        d.setHours(0, 0, 0, 0);
        days.push(d);
    }

    return days.map((d) => {
        const total = orders.value.reduce((sum, o) => {
            if (!o.placedAt) {
return sum;
}

            const placed = new Date(o.placedAt);
            const sameDay =
                placed.getFullYear() === d.getFullYear() &&
                placed.getMonth() === d.getMonth() &&
                placed.getDate() === d.getDate();

            return sameDay ? sum + (Number(o.total) || 0) : sum;
        }, 0);

        return { label: d.toLocaleDateString('en-US', { weekday: 'short' }), total };
    });
});

const salesTrendMax = computed(() => {
    const max = Math.max(...salesTrendDays.value.map((d) => d.total), 0);

    return max > 0 ? max : 1; // avoid a divide-by-zero flatline when everything is 0
});

const salesTrendPoints = computed(() => {
    const days = salesTrendDays.value;
    const n = days.length;

    return days.map((d, idx) => ({
        x: n > 1 ? (idx / (n - 1)) * 800 : 0,
        y: 190 - (d.total / salesTrendMax.value) * 170,
        total: d.total,
        label: d.label,
    }));
});

const salesTrendLinePath = computed(() => {
    const pts = salesTrendPoints.value;

    if (!pts.length) {
return '';
}

    return pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
});

const salesTrendAreaPath = computed(() => {
    const pts = salesTrendPoints.value;

    if (!pts.length) {
return '';
}

    const first = pts[0];

    return `${salesTrendLinePath.value} V200 H${first.x.toFixed(1)} Z`;
});

const salesTrendPeak = computed(() => {
    const pts = salesTrendPoints.value;

    if (!pts.length) {
return null;
}

    return pts.reduce((max, p) => (p.total > max.total ? p : max), pts[0]);
});

// ---- Order Breakdown donut (real order status counts) ----
// The r=15.9 circle trick: circumference = 2π×15.9 ≈ 99.9 ≈ 100, so a
// percentage value can be used directly as the stroke-dasharray length
// without any extra circumference math.
const orderStatusCounts = computed(() => {
    const counts = { delivered: 0, inTransit: 0, processing: 0 };

    for (const o of orders.value) {
        if (o.status === 'Delivered') {
counts.delivered++;
} else if (o.status === 'In Transit') {
counts.inTransit++;
} else if (o.status === 'New' || o.status === 'Processing') {
counts.processing++;
}
        // Cancelled orders are excluded from this breakdown, matching
        // the original 3-segment design.
    }

    return counts;
});

const orderBreakdownTotal = computed(
    () =>
        orderStatusCounts.value.delivered +
        orderStatusCounts.value.inTransit +
        orderStatusCounts.value.processing,
);

const orderDonutSegments = computed(() => {
    const total = orderBreakdownTotal.value || 1;
    const counts = orderStatusCounts.value;
    const segments = [
        { key: 'delivered', label: 'Delivered', color: '#1b9ba8', count: counts.delivered },
        { key: 'inTransit', label: 'In Transit', color: '#2c5aa0', count: counts.inTransit },
        { key: 'processing', label: 'Processing', color: '#f87171', count: counts.processing },
    ];

    let offsetAcc = 0;

    return segments.map((s) => {
        const pct = Math.round((s.count / total) * 100);
        const seg = { ...s, pct, dashoffset: -offsetAcc };

        offsetAcc += pct;

        return seg;
    });
});

// ---- Recent Sales Records (real orders, most recent first) ----
const recentOrders = computed(() =>
    [...orders.value]
        .sort((a, b) => new Date(b.placedAt || 0) - new Date(a.placedAt || 0))
        .slice(0, 5),
);

function orderItemsSummary(order) {
    if (!order.items?.length) {
return '—';
}

    if (order.items.length === 1) {
return order.items[0].name;
}

    return `${order.items[0].name} +${order.items.length - 1} more`;
}

// Local, non-destructive view of the real activity log so "Clear Activity
// Log" only clears what's on screen — it never mutates the underlying
// composable state or deletes anything server-side.
const visibleActivityLog = ref([]);
watch(
    activityLog,
    (val) => {
        visibleActivityLog.value = [...val];
    },
    { immediate: true },
);

const hasProfileInfo = computed(() =>
    Boolean(
        profile.value?.first_name &&
        profile.value?.last_name &&
        profile.value?.birthday &&
        profile.value?.contact_no,
    ),
);
const hasAddress = computed(() =>
    Boolean(
        address.value?.province_name &&
        address.value?.municipality_name &&
        address.value?.barangay,
    ),
);
const hasBusinessInfo = computed(() =>
    Boolean(
        sellerDetails.value?.business_name &&
        sellerDetails.value?.line_of_business,
    ),
);

const rejectedDocsCount = computed(
    () => documents.value.filter((d) => d.status === 'rejected').length,
);

const circumference = 2 * Math.PI * 54;

const donutSegments = computed(() => {
    const total = totalDocsCount.value || 1;
    const parts = [
        { value: verifiedDocsCount.value, color: 'var(--teal-500, #14b8a6)' },
        { value: pendingDocsCount.value, color: '#f59e0b' },
        { value: rejectedDocsCount.value, color: '#ef4444' },
    ].filter((p) => p.value > 0);

    let offsetAcc = 0;

    return parts.map((p) => {
        const dash = (p.value / total) * circumference;
        const seg = { color: p.color, dash, offset: -offsetAcc };
        offsetAcc += dash;

        return seg;
    });
});
</script>

<style scoped>
/* One-line context under each metric value, so the KPI cards explain
   themselves instead of relying on a vague chip. */
.metric-sub {
    margin-top: 0.3rem;
    font-size: 0.7rem;
    line-height: 1.35;
    color: #94a3b8;
}

/* Six evenly-sized KPI cards — no orphaned card on a second row. */
.metric-grid {
    grid-template-columns: repeat(6, minmax(0, 1fr));
}

@media (max-width: 1280px) {
    .metric-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 460px) {
    .metric-grid {
        grid-template-columns: 1fr;
    }
}

/* Best-Selling Products + Low-Stock Products panels (added to the
   dashboard). Uses the seller palette: teal #1b9ba8 accent, slate ink. */

.rank-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.rank-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.rank-num {
    flex-shrink: 0;
    width: 1.4rem;
    height: 1.4rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    background: #f1f5f9;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
}

.rank-body {
    flex: 1;
    min-width: 0;
}

.rank-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.75rem;
}

.rank-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.rank-units {
    flex-shrink: 0;
    font-size: 0.78rem;
    font-weight: 600;
    color: #1b9ba8;
}

.rank-bar {
    margin: 0.35rem 0 0.25rem;
    height: 6px;
    border-radius: 999px;
    background: #eef2f6;
    overflow: hidden;
}

.rank-bar > span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: #1b9ba8;
    transition: width 0.4s ease;
}

.rank-sub {
    font-size: 0.72rem;
    color: #94a3b8;
}

.stock-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}

.stock-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.7rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.stock-item:last-child {
    border-bottom: 0;
}

.stock-name {
    font-size: 0.85rem;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stock-qty {
    flex-shrink: 0;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
}

.stock-qty.is-low {
    background: #fef3c7;
    color: #b45309;
}

.stock-qty.is-out {
    background: #fee2e2;
    color: #b91c1c;
}
</style>