<template>
    <div class="space-y-5">
        <div>
            <h2 class="text-xl font-bold text-slate-900">Seller compliance</h2>
            <p class="mt-1 text-sm text-slate-500">
                Verify category alignment, review inappropriate products, flag
                or remove violations, and suspend sellers for serious cases.
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

            <div class="flex flex-wrap gap-2">
                <button
                    v-if="!showingHistory && summary.pending > 0"
                    class="btn-primary"
                    type="button"
                    @click="openVerifyAll"
                >
                    Verify all ({{ summary.pending }})
                </button>
                <button
                    class="btn-outline"
                    type="button"
                    @click="toggleHistory"
                >
                    {{
                        showingHistory
                            ? 'Back to compliance review'
                            : 'Compliance history'
                    }}
                </button>
            </div>
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
                        <th>AI review</th>
                        <th>
                            {{ showingHistory ? 'Latest action' : 'Review' }}
                        </th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            Loading products...
                        </td>
                    </tr>
                    <tr v-else-if="products.length === 0">
                        <td colspan="7" class="py-8 text-center text-slate-500">
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
                            <div
                                v-if="product.moderation"
                                class="space-y-1"
                                :title="product.moderation.reasoning"
                            >
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                    :class="
                                        aiStatusBadgeClass(
                                            product.moderation.ai_status,
                                        )
                                    "
                                >
                                    {{
                                        product.moderation.ai_status.toLowerCase()
                                    }}
                                    ·
                                    {{
                                        Math.round(
                                            product.moderation
                                                .confidence_score * 100,
                                        )
                                    }}%
                                </span>
                                <p
                                    v-if="
                                        product.moderation.flagged_signals
                                            .length
                                    "
                                    class="text-xs text-red-600"
                                >
                                    Flagged:
                                    {{
                                        product.moderation.flagged_signals.join(
                                            ', ',
                                        )
                                    }}
                                </p>
                                <p
                                    v-else
                                    class="text-xs text-slate-500 capitalize"
                                >
                                    {{
                                        product.moderation.final_status.replaceAll(
                                            '_',
                                            ' ',
                                        )
                                    }}
                                </p>
                            </div>
                            <span v-else class="text-xs text-slate-400">
                                Not yet reviewed
                            </span>
                        </td>
                        <td>
                            <div v-if="showingHistory" class="space-y-1">
                                <span
                                    class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700"
                                >
                                    {{
                                        historyActionLabel(
                                            historyAction(product)?.action,
                                        )
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
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    class="btn-outline"
                                    type="button"
                                    @click="openView(product)"
                                >
                                    View
                                </button>
                                <button
                                    v-if="
                                        !showingHistory &&
                                        product.status !== 'archived'
                                    "
                                    class="btn-warning"
                                    type="button"
                                    @click="openAction(product, 'warn')"
                                >
                                    Flagged
                                </button>
                                <button
                                    v-if="product.status === 'archived'"
                                    class="btn-outline"
                                    type="button"
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
                                    type="button"
                                    @click="openAction(product, 'suspend')"
                                >
                                    Suspend
                                </button>
                                <button
                                    v-if="
                                        !showingHistory &&
                                        product.status !== 'archived'
                                    "
                                    class="btn-icon-danger"
                                    type="button"
                                    title="Remove product"
                                    aria-label="Remove product"
                                    @click="openAction(product, 'remove')"
                                >
                                    <svg
                                        width="15"
                                        height="15"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path d="M3 6h18" />
                                        <path
                                            d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"
                                        />
                                        <path
                                            d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"
                                        />
                                        <line x1="10" y1="11" x2="10" y2="17" />
                                        <line x1="14" y1="11" x2="14" y2="17" />
                                    </svg>
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

        <Transition name="modal-fade">
            <div
                v-if="viewingProduct"
                class="modal-overlay"
                @click.self="closeView"
            >
                <div class="modal-panel modal-panel-lg">
                    <div class="modal-header">
                        <div>
                            <h3>{{ viewingProduct.name }}</h3>
                            <p class="modal-subtitle">
                                {{ viewingProduct.category || 'Uncategorized' }}
                                <span v-if="viewingProduct.price">
                                    · ₱{{
                                        Number(
                                            viewingProduct.price,
                                        ).toLocaleString()
                                    }}
                                </span>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="closeView"
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

                    <div
                        v-if="viewingProduct.images?.length"
                        class="photo-grid"
                    >
                        <button
                            v-for="(image, index) in viewingProduct.images"
                            :key="index"
                            type="button"
                            class="photo-thumb"
                            :aria-label="`View ${viewingProduct.name} photo ${index + 1} of ${viewingProduct.images.length}`"
                            @click="openLightbox(index)"
                        >
                            <img
                                :src="image.url || image"
                                :alt="`${viewingProduct.name} photo ${index + 1}`"
                                loading="lazy"
                                decoding="async"
                            />
                        </button>
                    </div>
                    <p v-else class="modal-desc">
                        No photos uploaded for this product.
                    </p>

                    <div class="mt-4">
                        <p class="field-label">Description</p>
                        <p
                            class="mt-1 text-sm whitespace-pre-line text-slate-700"
                        >
                            {{
                                viewingProduct.description ||
                                'No description provided.'
                            }}
                        </p>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="field-label">Registered category</p>
                            <p class="mt-1 text-slate-700">
                                {{
                                    viewingProduct.registered_category ||
                                    'Not registered'
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="field-label">Seller</p>
                            <p class="mt-1 text-slate-700">
                                {{ viewingProduct.seller?.business_name }}
                                ({{ viewingProduct.seller?.full_name }})
                            </p>
                        </div>
                    </div>

                    <div v-if="viewingProduct.moderation" class="mt-4">
                        <p class="field-label">AI review</p>
                        <p class="mt-1 text-sm text-slate-700">
                            {{ viewingProduct.moderation.reasoning }}
                        </p>
                    </div>

                    <!-- Manual override: only relevant for a product AI
                         flagged (or hasn't decided on) — an already
                         active/archived product has nothing to verify. -->
                    <button
                        v-if="
                            !['active', 'archived'].includes(
                                viewingProduct.status,
                            )
                        "
                        type="button"
                        class="btn-success mt-5"
                        style="width: 100%"
                        @click="verifyFromView"
                    >
                        Verify product
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Verify All confirmation -->
        <Transition name="modal-fade">
            <div
                v-if="verifyAllOpen"
                class="modal-overlay"
                @click.self="closeVerifyAll"
            >
                <div class="modal-panel">
                    <div class="modal-header">
                        <h3>Verify all pending products?</h3>
                        <button
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="closeVerifyAll"
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
                        This will mark
                        <strong>{{ summary.pending }}</strong>
                        product{{ summary.pending === 1 ? '' : 's' }}
                        currently pending review as verified and made active —
                        the same as verifying each one individually. Every
                        affected seller will be notified. This cannot be undone
                        in bulk; you'd need to remove any of them individually
                        afterward.
                    </p>
                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-outline"
                            style="flex: 1"
                            @click="closeVerifyAll"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="btn-primary"
                            style="flex: 1"
                            :disabled="verifyingAll"
                            @click="confirmVerifyAll"
                        >
                            {{
                                verifyingAll
                                    ? 'Verifying...'
                                    : 'Yes, verify all'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Full-size photo lightbox, opened from the photo grid above -->
        <Transition name="modal-fade">
            <div
                v-if="lightboxIndex !== null"
                class="modal-overlay"
                role="dialog"
                aria-modal="true"
                aria-label="Product photo preview"
                @click.self="closeLightbox"
            >
                <div class="image-lightbox">
                    <button
                        type="button"
                        class="modal-close lightbox-close"
                        aria-label="Close preview"
                        @click="closeLightbox"
                    >
                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>
                    <button
                        v-if="viewingProduct?.images?.length > 1"
                        type="button"
                        class="lightbox-nav prev"
                        aria-label="Previous photo"
                        @click="prevImage"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                    <img
                        :src="lightboxImage"
                        :alt="`${viewingProduct?.name} photo ${lightboxIndex + 1}`"
                    />
                    <button
                        v-if="viewingProduct?.images?.length > 1"
                        type="button"
                        class="lightbox-nav next"
                        aria-label="Next photo"
                        @click="nextImage"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>

        <Transition name="modal-fade">
            <div
                v-if="selectedProduct"
                class="modal-overlay"
                @click.self="closeAction"
            >
                <form class="modal-panel" @submit.prevent="submitAction">
                    <div class="modal-header">
                        <h3 class="capitalize">
                            {{ actionLabels[selectedAction] }}
                        </h3>
                        <button
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="closeAction"
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

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-outline"
                            style="flex: 1"
                            @click="closeAction"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="btn-primary"
                            style="flex: 1"
                            :disabled="saving"
                        >
                            {{ saving ? 'Saving...' : 'Confirm action' }}
                        </button>
                    </div>
                </form>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { adminFetch } = useAdmin();

const products = ref([]);
const search = ref('');
const categoryState = ref('');
const productStatus = ref('');
const showingHistory = ref(false);
const loading = ref(false);
const saving = ref(false);
const selectedProduct = ref(null);
const selectedAction = ref('');
const viewingProduct = ref(null);
const lightboxIndex = ref(null);
const verifyAllOpen = ref(false);
const verifyingAll = ref(false);
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

const lightboxImage = computed(() => {
    if (lightboxIndex.value === null) {
        return null;
    }

    const image = viewingProduct.value?.images?.[lightboxIndex.value];

    return image?.url || image || null;
});

const actionLabels = {
    verify: 'Verify product',
    warn: 'Flag product',
    remove: 'Remove prohibited product',
    restore: 'Restore archived product',
    suspend: 'Suspend seller',
};

const historyActionLabels = {
    verify: 'Verified',
    warn: 'Flagged',
    remove: 'Removed',
    restore: 'Restored',
    suspend: 'Suspended',
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

function aiStatusBadgeClass(aiStatus) {
    if (aiStatus === 'APPROVE') {
        return 'bg-emerald-100 text-emerald-700';
    }

    if (aiStatus === 'REJECT') {
        return 'bg-red-100 text-red-700';
    }

    return 'bg-amber-100 text-amber-700';
}

function historyAction(product) {
    return (
        product.compliance_actions?.find((action) =>
            ['verify', 'remove'].includes(action.action),
        ) || null
    );
}

function historyActionLabel(action) {
    return historyActionLabels[action] || 'Reviewed';
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
    }
}

function openView(product) {
    viewingProduct.value = product;
}

function closeView() {
    viewingProduct.value = null;
    lightboxIndex.value = null;
}

function openLightbox(index) {
    lightboxIndex.value = index;
}

function closeLightbox() {
    lightboxIndex.value = null;
}

function prevImage() {
    const total = viewingProduct.value?.images?.length || 0;
    lightboxIndex.value = (lightboxIndex.value - 1 + total) % total;
}

function nextImage() {
    const total = viewingProduct.value?.images?.length || 0;
    lightboxIndex.value = (lightboxIndex.value + 1) % total;
}

function openAction(product, action) {
    selectedProduct.value = product;
    selectedAction.value = action;
    reason.value = '';
    notes.value = '';
}

function verifyFromView() {
    const product = viewingProduct.value;
    closeView();
    openAction(product, 'verify');
}

function openVerifyAll() {
    verifyAllOpen.value = true;
}

function closeVerifyAll() {
    verifyAllOpen.value = false;
}

async function confirmVerifyAll() {
    verifyingAll.value = true;

    try {
        const params = new URLSearchParams();

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        const response = await adminFetch(
            `/api/admin/compliance/products/verify-all?${params.toString()}`,
            { method: 'POST' },
        );
        const payload = await response.json();

        closeVerifyAll();
        showMessage(payload.message);
        await loadProducts(1);
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        verifyingAll.value = false;
    }
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

onMounted(() => loadProducts());

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
/* ---- modal system, matched to the seller portal's .modal-* pattern
   (resources/css/seller/layout.css) so admin dialogs share the same
   feel: blurred backdrop, rounded panel, fade + settle transition,
   ghost X close button instead of a text "Close" button. ---- */
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(2px);
}
.modal-panel {
    width: 28rem;
    max-width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
    border-radius: 1rem;
    background: white;
    box-shadow: 0 20px 50px rgb(15 23 42 / 25%);
}
.modal-panel-lg {
    width: 40rem;
}
.modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
}
.modal-header h3 {
    margin: 0;
    color: #134e4a;
    font-weight: 800;
    font-size: 1rem;
}
.modal-subtitle {
    margin: 0.15rem 0 0;
    color: #64748b;
    font-size: 0.8rem;
}
.modal-desc {
    margin-bottom: 1rem;
    color: #64748b;
    font-size: 0.85rem;
    line-height: 1.45;
}
.modal-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 1.25rem;
}
.modal-close {
    flex-shrink: 0;
    padding: 0.25rem;
    border: none;
    border-radius: 0.4rem;
    background: none;
    color: #94a3b8;
    cursor: pointer;
}
.modal-close:hover {
    background: #f1f5f9;
    color: #334155;
}

/* ---- product photo grid + lightbox, matched to the seller Feedback
   page's .feedback-image-thumb / .feedback-image-modal pattern ---- */
.photo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}
.photo-thumb {
    width: 4.5rem;
    height: 4.5rem;
    padding: 0;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    background: #f1f5f9;
    cursor: pointer;
}
.photo-thumb:hover {
    border-color: #14b8a6;
}
.photo-thumb img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.image-lightbox {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    max-width: min(90vw, 40rem);
    max-height: 85vh;
}
.image-lightbox img {
    max-width: 100%;
    max-height: 85vh;
    border-radius: 0.75rem;
    background: #0f172a;
    object-fit: contain;
}
.lightbox-close {
    position: absolute;
    top: -2.5rem;
    right: 0;
    background: rgba(15, 23, 42, 0.6);
    color: white;
}
.lightbox-close:hover {
    background: rgba(15, 23, 42, 0.8);
}
.lightbox-nav {
    position: absolute;
    top: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.4rem;
    height: 2.4rem;
    border: none;
    border-radius: 50%;
    background: rgba(15, 23, 42, 0.55);
    color: white;
    cursor: pointer;
    transform: translateY(-50%);
}
.lightbox-nav:hover {
    background: rgba(15, 23, 42, 0.75);
}
.lightbox-nav.prev {
    left: -1.2rem;
}
.lightbox-nav.next {
    right: -1.2rem;
}

/* ---- modal enter/leave: backdrop fades, panel fades + settles in ---- */
.modal-fade-enter-active,
.modal-fade-leave-active {
    transition: opacity 0.2s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
    opacity: 0;
}
.modal-fade-enter-active .modal-panel,
.modal-fade-enter-active .image-lightbox,
.modal-fade-leave-active .modal-panel,
.modal-fade-leave-active .image-lightbox {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}
.modal-fade-enter-from .modal-panel,
.modal-fade-enter-from .image-lightbox,
.modal-fade-leave-to .modal-panel,
.modal-fade-leave-to .image-lightbox {
    opacity: 0;
    transform: translateY(8px) scale(0.98);
}
@media (prefers-reduced-motion: reduce) {
    .modal-fade-enter-active .modal-panel,
    .modal-fade-enter-active .image-lightbox,
    .modal-fade-leave-active .modal-panel,
    .modal-fade-leave-active .image-lightbox {
        transition: opacity 0.15s ease;
    }
    .modal-fade-enter-from .modal-panel,
    .modal-fade-enter-from .image-lightbox,
    .modal-fade-leave-to .modal-panel,
    .modal-fade-leave-to .image-lightbox {
        transform: none;
    }
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
.btn-icon-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.9rem;
    height: 1.9rem;
    border: 1px solid #fecaca;
    border-radius: 0.4rem;
    background: #fef2f2;
    color: #b91c1c;
    cursor: pointer;
}
.btn-icon-danger:hover {
    background: #fee2e2;
    border-color: #fca5a5;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>
