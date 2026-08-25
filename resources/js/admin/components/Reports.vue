<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">
                    Generate reports
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    Review completed sales and export auditable CSV reports.
                </p>
            </div>
            <button
                class="btn-primary"
                :disabled="exporting"
                @click="exportReport"
            >
                {{ exporting ? 'Preparing...' : 'Export CSV' }}
            </button>
        </div>

        <div
            v-if="message"
            class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            {{ message }}
        </div>

        <div class="inline-flex rounded-lg bg-slate-100 p-1">
            <button
                v-for="option in reportTypes"
                :key="option.id"
                class="report-tab"
                :class="{ active: reportType === option.id }"
                @click="selectType(option.id)"
            >
                {{ option.label }}
            </button>
        </div>

        <div class="flex flex-wrap gap-3">
            <input
                v-model="fromDate"
                type="date"
                class="field-input w-44"
                aria-label="Start date"
                @change="loadReport(1)"
            />
            <input
                v-model="toDate"
                type="date"
                class="field-input w-44"
                aria-label="End date"
                @change="loadReport(1)"
            />
            <button
                v-if="fromDate || toDate"
                class="btn-outline"
                @click="clearDates"
            >
                Clear dates
            </button>
        </div>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="item in summaryCards"
                :key="item.label"
                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
            >
                <p
                    class="text-xs font-semibold tracking-wide text-slate-500 uppercase"
                >
                    {{ item.label }}
                </p>
                <p class="mt-2 text-2xl font-bold text-slate-900">
                    {{ item.value }}
                </p>
            </article>
        </section>

        <div
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white"
        >
            <table class="admin-table">
                <thead>
                    <tr v-if="reportType === 'sales'">
                        <th>Order</th>
                        <th>Seller</th>
                        <th>Order date</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>Net merchandise</th>
                        <th>Order total</th>
                    </tr>
                    <tr v-else>
                        <th>Order</th>
                        <th>Seller</th>
                        <th>Order date</th>
                        <th>Commission basis</th>
                        <th>Rate</th>
                        <th>Platform commission</th>
                        <th>Seller proceeds</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            Loading report...
                        </td>
                    </tr>
                    <tr v-else-if="records.length === 0">
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            No completed sales were found for this period.
                        </td>
                    </tr>
                    <tr v-for="record in records" :key="record.order_number">
                        <td class="font-semibold text-slate-800">
                            {{ record.order_number }}
                        </td>
                        <td>
                            <p>
                                {{
                                    record.seller?.full_name || 'Unknown seller'
                                }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ record.seller?.email }}
                            </p>
                        </td>
                        <td>{{ formatDate(record.placed_at) }}</td>
                        <template v-if="reportType === 'sales'">
                            <td>{{ money(record.subtotal) }}</td>
                            <td class="text-red-600">
                                -{{ money(record.discount) }}
                            </td>
                            <td>{{ money(record.net_merchandise) }}</td>
                            <td class="font-semibold">
                                {{ money(record.total) }}
                            </td>
                        </template>
                        <template v-else>
                            <td>{{ money(record.net_merchandise) }}</td>
                            <td>10%</td>
                            <td class="font-bold text-teal-700">
                                {{ money(record.commission) }}
                            </td>
                            <td>{{ money(record.seller_proceeds) }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pagination.last_page > 1"
            class="flex items-center justify-between text-sm text-slate-500"
        >
            <span
                >Page {{ pagination.current_page }} of
                {{ pagination.last_page }}</span
            >
            <div class="flex gap-2">
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === 1"
                    @click="loadReport(pagination.current_page - 1)"
                >
                    Previous</button
                ><button
                    class="btn-outline"
                    :disabled="pagination.current_page === pagination.last_page"
                    @click="loadReport(pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onActivated, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { adminFetch } = useAdmin();
const reportType = ref('sales');
const fromDate = ref('');
const toDate = ref('');
const records = ref([]);
const loading = ref(false);
const exporting = ref(false);
const message = ref('');
const summary = ref({
    orders: 0,
    gross_merchandise: 0,
    discounts: 0,
    net_merchandise: 0,
    platform_commission: 0,
    seller_proceeds: 0,
});
const pagination = ref({ current_page: 1, last_page: 1 });
const reportTypes = [
    { id: 'sales', label: 'Sales summary' },
    { id: 'commission', label: 'Commission report' },
];

const summaryCards = computed(() =>
    reportType.value === 'sales'
        ? [
              { label: 'Completed orders', value: summary.value.orders },
              {
                  label: 'Gross merchandise',
                  value: money(summary.value.gross_merchandise),
              },
              { label: 'Discounts', value: money(summary.value.discounts) },
              {
                  label: 'Net merchandise',
                  value: money(summary.value.net_merchandise),
              },
          ]
        : [
              { label: 'Completed orders', value: summary.value.orders },
              {
                  label: 'Commission basis',
                  value: money(summary.value.net_merchandise),
              },
              {
                  label: 'Platform commission',
                  value: money(summary.value.platform_commission),
              },
              {
                  label: 'Seller proceeds',
                  value: money(summary.value.seller_proceeds),
              },
          ],
);

function money(value) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value || 0));
}

function formatDate(value) {
    return value
        ? new Date(value).toLocaleDateString('en-PH', {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
          })
        : 'N/A';
}

function params(page) {
    const values = new URLSearchParams({
        type: reportType.value,
        page: String(page),
    });

    if (fromDate.value) {
        values.set('from', fromDate.value);
    }

    if (toDate.value) {
        values.set('to', toDate.value);
    }

    return values;
}

function selectType(type) {
    reportType.value = type;
    loadReport(1);
}

function clearDates() {
    fromDate.value = '';
    toDate.value = '';
    loadReport(1);
}

async function loadReport(page = 1) {
    loading.value = true;
    message.value = '';

    try {
        const response = await adminFetch(`/api/admin/reports?${params(page)}`);
        const payload = await response.json();
        records.value = payload.records.data;
        summary.value = payload.summary;
        pagination.value = {
            current_page: payload.records.current_page,
            last_page: payload.records.last_page,
        };
    } catch (error) {
        message.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function exportReport() {
    exporting.value = true;
    message.value = '';

    try {
        const response = await adminFetch(
            `/api/admin/reports/export?${params(1)}`,
        );
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `nexmart-${reportType.value}-report.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        message.value = error.message;
    } finally {
        exporting.value = false;
    }
}

// This component is kept alive by AdminLayout's <KeepAlive>, so
// onActivated (not onMounted) fires both on first visit and every time the
// admin returns to this tab — reloading the current page/date range instead
// of showing figures that may be out of date.
onActivated(() => loadReport(pagination.value.current_page));
</script>

<style scoped>
.field-input {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    background: white;
    padding: 0.55rem 0.75rem;
    font-size: 0.85rem;
}
.field-input:focus {
    border-color: #0d9488;
    outline: none;
    box-shadow: 0 0 0 3px rgb(13 148 136 / 12%);
}
.report-tab {
    border-radius: 0.4rem;
    padding: 0.5rem 0.9rem;
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 700;
}
.report-tab.active {
    background: white;
    color: #0f766e;
    box-shadow: 0 1px 3px rgb(15 23 42 / 12%);
}
.admin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.admin-table th {
    border-bottom: 1px solid #e5e7eb;
    padding: 0.7rem 0.75rem;
    color: #64748b;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-align: left;
    text-transform: uppercase;
}
.admin-table td {
    border-bottom: 1px solid #f1f5f9;
    padding: 0.75rem;
    color: #334155;
    vertical-align: top;
}
.btn-outline,
.btn-primary {
    border-radius: 0.4rem;
    padding: 0.45rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
}
.btn-outline {
    border: 1px solid #d1d5db;
    background: white;
    color: #334155;
}
.btn-primary {
    background: #0d9488;
    color: white;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>
