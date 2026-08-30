<template>
    <div class="space-y-5">
        <div>
            <h2 class="text-xl font-bold text-slate-900">Seller compliance</h2>
            <p class="mt-1 text-sm text-slate-500">
                Verify category alignment, review inappropriate products, issue
                warnings, and suspend sellers for serious violations.
            </p>
        </div>

        <div
            v-if="message"
            class="rounded-lg border px-4 py-3 text-sm"
            :class="
                messageType === 'error'
                    ? 'border-red-200 bg-red-50 text-red-700'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700'
            "
        >
            {{ message }}
        </div>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
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

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-3">
                <input
                    v-model="search"
                    type="search"
                    class="field-input w-72"
                    :placeholder="
                        showingHistory
                            ? 'Search compliance history...'
                            : 'Search products or sellers...'
                    "
                    @input="scheduleLoad"
                />
                <select
                    v-if="!showingHistory"
                    v-model="categoryState"
                    class="field-input w-52"
                    @change="loadProducts(1)"
                >
                    <option value="">All category checks</option>
                    <option value="match">Category matches</option>
                    <option value="mismatch">Category mismatch</option>
                </select>
                <select
                    v-if="!showingHistory"
                    v-model="productStatus"
                    class="field-input w-44"
                    @change="loadProducts(1)"
                >
                    <option value="">All product statuses</option>
                    <option value="pending_review">Pending review</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <button class="btn-outline" type="button" @click="toggleHistory">
                {{
                    showingHistory
                        ? 'Back to compliance review'
                        : 'Compliance history'
                }}
            </button>
        </div>

        <div v-if="showingHistory">
            <h3 class="font-semibold text-slate-800">Compliance history</h3>
            <p class="text-sm text-slate-500">
                Verified and removed products are retained here for auditing.
                Archived products can be restored to pending review.
            </p>
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white"
        >
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Seller</th>
                        <th>Registered category</th>
                        <th>Product category</th>
                        <th>
                            {{ showingHistory ? 'Latest action' : 'Review' }}
                        </th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <SkeletonRows v-if="loading && !hasLoadedOnce" :columns="6" :rows="5" />
                    <tr v-else-if="products.length === 0">
                        <td colspan="6" class="py-8 text-center text-slate-500">
                            No products match these filters.
                        </td>
                    </tr>
                    <tr v-for="product in products" :key="product.id">
                        <td>
                            <p class="font-semibold text-slate-800">
                                {{ product.name }}
                            </p>
                            <p class="max-w-xs truncate text-xs text-slate-500">
                                {{ product.description || 'No description' }}
                            </p>
                            <span
                                class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                :class="statusBadgeClass(product.status)"
                            >
                                {{ product.status }}
                            </span>
                        </td>
                        <td>
                            <p class="font-medium text-slate-700">
                                {{ product.seller?.business_name }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ product.seller?.full_name }}
                            </p>
                        </td>
                        <td>
                            {{
                                product.registered_category || 'Not registered'
                            }}
                        </td>
                        <td>{{ product.category || 'Uncategorized' }}</td>
                        <td>
                            <div v-if="showingHistory" class="space-y-1">
                                <span
                                    class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 capitalize"
                                >
                                    {{
                                        historyAction(product)?.action ||
                                        'Reviewed'
                                    }}
                                </span>
                                <p class="text-xs text-slate-500">
                                    {{
                                        historyAction(product)?.admin ||
                                        'Administrator'
                                    }}
                                    ·
                                    {{
                                        formatDate(
                                            historyAction(product)?.created_at,
                                        )
                                    }}
                                </p>
                            </div>
                            <span
                                v-else
                                class="rounded-full px-2 py-1 text-xs font-semibold"
                                :class="
                                    product.category_matches
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-red-100 text-red-700'
                                "
                            >
                                {{
                                    product.category_matches
                                        ? 'Matches'
                                        : 'Mismatch'
                                }}
                            </span>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-if="
                                        !showingHistory &&
                                        product.status !== 'archived'
                                    "
                                    class="btn-success"
                                    @click="openAction(product, 'verify')"
                                >
                                    Verify
                                </button>
                                <button
                                    v-if="
                                        !showingHistory &&
                                        product.status !== 'archived'
                                    "
                                    class="btn-warning"
                                    @click="openAction(product, 'warn')"
                                >
                                    Warn
                                </button>
                                <button
                                    v-if="
                                        !showingHistory &&
                                        product.status !== 'archived'
                                    "
                                    class="btn-danger"
                                    @click="openAction(product, 'remove')"
                                >
                                    Remove
                                </button>
                                <button
                                    v-if="product.status === 'archived'"
                                    class="btn-outline"
                                    @click="openAction(product, 'restore')"
                                >
                                    Restore
                                </button>
                                <button
                                    v-if="
                                        !showingHistory &&
                                        product.status !== 'archived' &&
                                        product.seller?.account_status !==
                                            'suspended'
                                    "
                                    class="btn-danger"
                                    @click="openAction(product, 'suspend')"
                                >
                                    Suspend seller
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pagination.last_page > 1"
            class="flex items-center justify-between text-sm text-slate-500"
        >
            <span>
                Page {{ pagination.current_page }} of {{ pagination.last_page }}
            </span>
            <div class="flex gap-2">
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === 1"
                    @click="loadProducts(pagination.current_page - 1)"
                >
                    Previous
                </button>
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === pagination.last_page"
                    @click="loadProducts(pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>

        <div
            v-if="selectedProduct"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeAction"
        >
            <form
                class="modal-card w-full max-w-lg p-6"
                @submit.prevent="submitAction"
            >
                <h3 class="text-lg font-bold text-slate-900 capitalize">
                    {{ actionLabels[selectedAction] }}
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    Product: {{ selectedProduct.name }} · Seller:
                    {{ selectedProduct.seller?.full_name }}
                </p>

                <div
                    v-if="!['verify', 'restore'].includes(selectedAction)"
                    class="mt-4"
                >
                    <label class="field-label" for="compliance-reason">
                        Violation or reason
                    </label>
                    <textarea
                        id="compliance-reason"
                        v-model="reason"
                        class="field-input"
                        rows="4"
                        required
                        minlength="5"
                        placeholder="Describe the prohibited content, category issue, or policy violation..."
                    ></textarea>
                </div>

                <div class="mt-4">
                    <label class="field-label" for="compliance-notes">
                        Internal notes
                    </label>
                    <textarea
                        id="compliance-notes"
                        v-model="notes"
                        class="field-input"
                        rows="3"
                        placeholder="Optional notes for other administrators..."
                    ></textarea>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn-outline"
                        @click="closeAction"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="saving"
                    >
                        {{ saving ? 'Saving...' : 'Confirm action' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, onActivated, onBeforeUnmount, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';
import SkeletonRows from './SkeletonRows.vue';

const { adminFetch } = useAdmin();

const products = ref([]);
const search = ref('');
const categoryState = ref('');
const productStatus = ref('');
const showingHistory = ref(false);
const loading = ref(false);
// This component stays kept-alive across tab switches, so onActivated below
// reruns loadProducts() on every revisit, not just the first. Without this
// flag, the skeleton would wipe out rows that are already loaded and on
// screen just because a background refresh set "loading" true again —
// gating it on "loading && !hasLoadedOnce" instead lets that refresh
// update the table in place once it resolves.
const hasLoadedOnce = ref(false);
const saving = ref(false);
const selectedProduct = ref(null);
const selectedAction = ref('');
const reason = ref('');
const notes = ref('');
const message = ref('');
const messageType = ref('success');
const summary = ref({
    total: 0,
    active: 0,
    pending: 0,
    archived: 0,
    warnings: 0,
});
const pagination = ref({
    current_page: 1,
    last_page: 1,
});

let searchTimer;

const summaryCards = computed(() => [
    { label: 'Total products', value: summary.value.total },
    { label: 'Active products', value: summary.value.active },
    { label: 'Pending review', value: summary.value.pending },
    { label: 'Archived products', value: summary.value.archived },
    { label: 'Warnings issued', value: summary.value.warnings },
]);

const actionLabels = {
    verify: 'Verify product',
    warn: 'Issue warning',
    remove: 'Remove prohibited product',
    restore: 'Restore archived product',
    suspend: 'Suspend seller',
};

function statusBadgeClass(status) {
    if (status === 'active') {
        return 'bg-emerald-100 text-emerald-700';
    }

    if (status === 'pending_review') {
        return 'bg-amber-100 text-amber-700';
    }

    if (status === 'archived') {
        return 'bg-red-100 text-red-700';
    }

    return 'bg-slate-100 text-slate-600';
}

function historyAction(product) {
    return (
        product.compliance_actions?.find((action) =>
            ['verify', 'remove'].includes(action.action),
        ) || null
    );
}

function formatDate(value) {
    if (!value) {
        return 'Unknown date';
    }

    return new Date(value).toLocaleString();
}

function showMessage(text, type = 'success') {
    message.value = text;
    messageType.value = type;
}

function scheduleLoad() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadProducts(1), 350);
}

function toggleHistory() {
    showingHistory.value = !showingHistory.value;
    categoryState.value = '';
    productStatus.value = '';
    loadProducts(1);
}

async function loadProducts(page = 1) {
    loading.value = true;

    try {
        const params = new URLSearchParams({ page: String(page) });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        if (!showingHistory.value && categoryState.value) {
            params.set('category_state', categoryState.value);
        }

        if (showingHistory.value) {
            params.set('history', '1');
        } else if (productStatus.value) {
            params.set('status', productStatus.value);
        }

        const response = await adminFetch(
            `/api/admin/compliance/products?${params.toString()}`,
        );
        const payload = await response.json();

        products.value = payload.products.data;
        summary.value = payload.summary;
        pagination.value = {
            current_page: payload.products.current_page,
            last_page: payload.products.last_page,
        };
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        loading.value = false;
        hasLoadedOnce.value = true;
    }
}

function openAction(product, action) {
    selectedProduct.value = product;
    selectedAction.value = action;
    reason.value = '';
    notes.value = '';
}

function closeAction() {
    selectedProduct.value = null;
    selectedAction.value = '';
}

async function submitAction() {
    saving.value = true;

    try {
        const response = await adminFetch(
            `/api/admin/compliance/products/${selectedProduct.value.id}/actions`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: selectedAction.value,
                    reason: reason.value.trim() || null,
                    notes: notes.value.trim() || null,
                }),
            },
        );
        const payload = await response.json();

        closeAction();
        showMessage(payload.message);
        await loadProducts(pagination.value.current_page);
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        saving.value = false;
    }
}

// This component is kept alive by AdminLayout's <KeepAlive>, so
// onActivated (not onMounted) fires both on first visit and every time the
// admin returns to this tab — reloading the current page/filters instead of
// showing a product list that may have since changed.
onActivated(() => loadProducts(pagination.value.current_page));

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
.field-label {
    display: block;
    margin-bottom: 0.25rem;
    color: #64748b;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
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
.modal-card {
    border-radius: 0.85rem;
    background: white;
    box-shadow: 0 24px 64px rgb(15 23 42 / 25%);
}
.btn-outline,
.btn-success,
.btn-warning,
.btn-danger,
.btn-primary {
    border-radius: 0.4rem;
    padding: 0.4rem 0.7rem;
    font-size: 0.75rem;
    font-weight: 600;
}
.btn-outline {
    border: 1px solid #d1d5db;
    background: white;
    color: #334155;
}
.btn-success,
.btn-primary {
    background: #0d9488;
    color: white;
}
.btn-warning {
    border: 1px solid #fcd34d;
    background: #fffbeb;
    color: #a16207;
}
.btn-danger {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>
