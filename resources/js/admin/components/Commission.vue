<template>
    <div class="space-y-5">
        <div>
            <h2 class="text-xl font-bold text-slate-900">
                Platform commission
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Track the 10% platform commission from completed, non-refunded
                sales.
            </p>
        </div>

        <div
            v-if="message"
            class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            {{ message }}
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

        <div class="flex flex-wrap gap-3">
            <input
                v-model="search"
                type="search"
                class="field-input w-72"
                placeholder="Search order or seller..."
                @input="scheduleLoad"
            />
            <input
                v-model="fromDate"
                type="date"
                class="field-input w-44"
                aria-label="Start date"
                @change="loadCommissions(1)"
            />
            <input
                v-model="toDate"
                type="date"
                class="field-input w-44"
                aria-label="End date"
                @change="loadCommissions(1)"
            />
            <button
                v-if="fromDate || toDate || search"
                class="btn-outline"
                @click="clearFilters"
            >
                Clear filters
            </button>
        </div>

        <div
            class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700"
        >
            Commission basis = merchandise subtotal minus discounts. Shipping
            and tax are excluded. Refunded and incomplete orders are excluded.
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white"
        >
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Seller</th>
                        <th>Order date</th>
                        <th>Merchandise</th>
                        <th>Discount</th>
                        <th>Commission basis</th>
                        <th>Platform share</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            Loading commissions...
                        </td>
                    </tr>
                    <tr v-else-if="orders.length === 0">
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            No eligible delivered orders match these filters.
                        </td>
                    </tr>
                    <tr v-for="order in orders" :key="order.id">
                        <td>
                            <p class="font-semibold text-slate-800">
                                {{ order.order_number }}
                            </p>
                            <span class="badge">{{
                                order.payment_status
                            }}</span>
                        </td>
                        <td>
                            <p class="font-medium text-slate-700">
                                {{
                                    order.seller?.full_name || 'Unknown seller'
                                }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ order.seller?.email }}
                            </p>
                        </td>
                        <td>{{ formatDate(order.placed_at) }}</td>
                        <td>{{ money(order.subtotal) }}</td>
                        <td class="text-red-600">
                            -{{ money(order.discount) }}
                        </td>
                        <td>{{ money(order.commission_basis) }}</td>
                        <td class="font-bold text-teal-700">
                            {{ money(order.commission) }}
                        </td>
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
                    @click="loadCommissions(pagination.current_page - 1)"
                >
                    Previous
                </button>
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === pagination.last_page"
                    @click="loadCommissions(pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { adminFetch } = useAdmin();
const orders = ref([]);
const search = ref('');
const fromDate = ref('');
const toDate = ref('');
const loading = ref(false);
const message = ref('');
const summary = ref({
    eligible_orders: 0,
    gross_sales: 0,
    commission_basis: 0,
    platform_commission: 0,
});
const pagination = ref({ current_page: 1, last_page: 1 });
let searchTimer;

const summaryCards = computed(() => [
    { label: 'Eligible orders', value: summary.value.eligible_orders },
    { label: 'Gross merchandise', value: money(summary.value.gross_sales) },
    { label: 'Commission basis', value: money(summary.value.commission_basis) },
    {
        label: 'Platform commission',
        value: money(summary.value.platform_commission),
    },
]);

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

function scheduleLoad() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadCommissions(1), 350);
}

function clearFilters() {
    search.value = '';
    fromDate.value = '';
    toDate.value = '';
    loadCommissions(1);
}

async function loadCommissions(page = 1) {
    loading.value = true;
    message.value = '';

    try {
        const params = new URLSearchParams({ page: String(page) });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        if (fromDate.value) {
            params.set('from', fromDate.value);
        }

        if (toDate.value) {
            params.set('to', toDate.value);
        }

        const response = await adminFetch(`/api/admin/commissions?${params}`);
        const payload = await response.json();
        orders.value = payload.orders.data;
        summary.value = payload.summary;
        pagination.value = {
            current_page: payload.orders.current_page,
            last_page: payload.orders.last_page,
        };
    } catch (error) {
        message.value = error.message;
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadCommissions());
onBeforeUnmount(() => clearTimeout(searchTimer));
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
.badge {
    display: inline-flex;
    margin-top: 0.25rem;
    border-radius: 9999px;
    background: #dcfce7;
    padding: 0.15rem 0.5rem;
    color: #15803d;
    font-size: 0.68rem;
    font-weight: 700;
}
.btn-outline {
    border: 1px solid #d1d5db;
    border-radius: 0.4rem;
    background: white;
    padding: 0.4rem 0.7rem;
    color: #334155;
    font-size: 0.75rem;
    font-weight: 600;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>
