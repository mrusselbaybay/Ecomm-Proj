<script setup>
/*
| Seller Vouchers modal (Seller\VoucherController), opened from the
| Inventory toolbar. Active (active, scheduled, fully redeemed) / Inactive
| (deactivated, expired) tabs, server-paginated 5 at a time, with
| scope/type/status filters; a ⋯ menu per voucher (Deactivate · Reactivate
| · Add stock · Clone · Delete) and a step-by-step create flow: Type →
| Scope → Products → Rules → Stacking → Confirm. Shipping vouchers are
| always free shipping, so their Rules skip the discount fields.
| Vouchers are immutable after creation,
| except adding usage stock to a Fully Redeemed one.
| Themed with the inventory page's dark tokens (--inv-*) + teal accents.
*/
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { apiRequest } from '../../shared/accountApi';
import { createClient } from '../../shared/backendClient';
import { useSellerProducts } from '../composables/useSellerProducts';

const emit = defineEmits(['close']);

const MAX_PRODUCTS = 500;
const PICKER_RENDER_LIMIT = 200;

const STATUS_META = {
    active: { label: 'Active', tone: 'good' },
    scheduled: { label: 'Scheduled', tone: 'info' },
    deactivated: { label: 'Deactivated', tone: 'muted' },
    fully_redeemed: { label: 'Fully Redeemed', tone: 'warn' },
    expired: { label: 'Expired', tone: 'muted' },
};

const { products, loadProducts } = useSellerProducts();

const vouchers = ref([]);
const page = ref(1);
const lastPage = ref(1);
const counts = ref({ active: 0, inactive: 0 });
const isLoading = ref(false);
const loadError = ref('');
const notice = ref(null);

const tab = ref('active');
const filters = reactive({ scope: '', type: '', status: '' });
const menuOpenId = ref(null);
const busyId = ref(null);

// Confirm dialog for every lifecycle action (state-aware copy).
const confirmState = ref(null);
// Add-stock dialog (Fully Redeemed only).
const stockDialog = reactive({ voucher: null, value: '', error: '' });

function call(path, options) {
    return apiRequest(createClient(), `/api/seller${path}`, options);
}

function peso(n) {
    return `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
}

function shortDate(iso) {
    return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Digits only — plus one "." and up to `decimals` places for money. The
// field is rewritten in place so typed/pasted letters and symbols never stick.
function cleanNumber(raw, decimals) {
    let value = String(raw).replace(decimals ? /[^\d.]/g : /\D/g, '');

    if (decimals) {
        const [whole, ...rest] = value.split('.');
        value = rest.length ? `${whole}.${rest.join('').slice(0, decimals)}` : whole;
    }

    return value.replace(/^0+(?=\d)/, '');
}

function onNumber(event, target, key, decimals = 0) {
    const clean = cleanNumber(event.target.value, decimals);
    target[key] = clean;

    if (event.target.value !== clean) {
        event.target.value = clean;
    }
}

function flash(type, text) {
    notice.value = { type, text };
}

let loadSeq = 0;

async function load() {
    const seq = ++loadSeq;
    const params = new URLSearchParams({ tab: tab.value, page: String(page.value) });

    for (const [key, value] of Object.entries(filters)) {
        if (value) {
            params.set(key, value);
        }
    }

    isLoading.value = true;
    loadError.value = '';
    const { data, error } = await call(`/vouchers?${params}`);

    // A newer tab/filter/page request superseded this one.
    if (seq !== loadSeq) {
        return;
    }

    isLoading.value = false;

    if (error) {
        loadError.value = error.message;

        return;
    }

    vouchers.value = data.items || [];
    counts.value = data.counts;
    lastPage.value = data.meta.lastPage;

    // Last row of the last page removed/moved: step back a page.
    if (!vouchers.value.length && page.value > 1) {
        page.value = data.meta.lastPage;
    }
}

watch(page, load);
watch([tab, () => filters.scope, () => filters.type, () => filters.status], () => {
    if (page.value === 1) {
        load();
    } else {
        page.value = 1; // the page watcher reloads
    }
});

// Status options that can exist on each tab.
const statusOptions = computed(() => (tab.value === 'active'
    ? [{ value: 'active', label: 'Active' }, { value: 'fully_redeemed', label: 'Fully Redeemed' }]
    : [{ value: 'deactivated', label: 'Deactivated' }, { value: 'expired', label: 'Expired' }]));

function switchTab(key) {
    filters.status = '';
    tab.value = key;
}

onMounted(() => {
    load();
    loadProducts();
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

function onKeydown(e) {
    if (e.key !== 'Escape') {
        return;
    }

    if (confirmState.value || stockDialog.voucher || overlap.value) {
        confirmState.value = null;
        stockDialog.voucher = null;
        overlap.value = null;
    } else if (menuOpenId.value) {
        menuOpenId.value = null;
    } else if (view.value === 'create') {
        view.value = 'list';
    } else {
        emit('close');
    }
}

/* ---------------------------------------------------------------- list */

const hasFilters = computed(() => filters.scope || filters.type || filters.status);

function clearFilters() {
    filters.scope = '';
    filters.type = '';
    filters.status = '';
}

function scopeText(v) {
    const where = v.scope === 'shop' ? 'Shop-wide' : `${v.productsCount} product${v.productsCount === 1 ? '' : 's'}`;

    return v.type === 'shipping' ? `Shipping fee · ${where}` : where;
}

function productPreview(v) {
    const names = v.productPreview || [];
    const more = v.productsCount - names.length;

    return names.join(', ') + (more > 0 ? ` and ${more} more` : '');
}

function unavailableHint(v) {
    if (v.scope !== 'product' || !v.unavailableCount) {
        return '';
    }

    return v.unavailableCount >= v.productsCount
        ? 'All products unavailable — hidden from buyers'
        : `${v.unavailableCount} of ${v.productsCount} products unavailable`;
}

function usagePercent(v) {
    return v.usageLimit ? Math.min(100, Math.round((v.usedCount / v.usageLimit) * 100)) : 0;
}

function actionsFor(v) {
    const del = { key: 'delete', label: 'Delete', danger: true, disabled: !v.canDelete, hint: v.canDelete ? '' : 'This voucher has been redeemed and cannot be deleted. You can deactivate it instead.' };

    switch (v.status) {
        case 'active':
        case 'scheduled':
            return [{ key: 'deactivate', label: 'Deactivate' }, del];
        case 'deactivated':
            return [{ key: 'reactivate', label: 'Reactivate' }, del];
        case 'fully_redeemed':
            return [{ key: 'stock', label: 'Edit (add stock)' }, { key: 'deactivate', label: 'Deactivate' }, del];
        default:
            return [{ key: 'clone', label: 'Clone' }, del];
    }
}

const CONFIRM_COPY = {
    deactivate: { title: 'Deactivate voucher?', message: 'Deactivating will hide this voucher from buyers. Continue?', confirm: 'Deactivate' },
    reactivate: { title: 'Reactivate voucher?', message: 'Reactivating will make this voucher visible to buyers again. Continue?', confirm: 'Reactivate' },
    delete: { title: 'Delete voucher?', message: 'This will permanently delete the voucher. This cannot be undone. Continue?', confirm: 'Delete', danger: true },
    clone: { title: 'Clone voucher?', message: 'This will create a copy of this voucher. You can adjust the details before saving. Continue?', confirm: 'Clone' },
};

function runAction(v, action) {
    menuOpenId.value = null;

    if (action.disabled) {
        return;
    }

    if (action.key === 'stock') {
        stockDialog.voucher = v;
        stockDialog.value = String((v.usageLimit || 0) + Math.max(10, Math.round((v.usageLimit || 0) * 0.5)));
        stockDialog.error = '';

        return;
    }

    confirmState.value = { action: action.key, voucher: v, ...CONFIRM_COPY[action.key] };
}

async function confirmAction() {
    const { action, voucher } = confirmState.value;
    confirmState.value = null;

    if (action === 'clone') {
        await startClone(voucher);

        return;
    }

    busyId.value = voucher.id;
    const { error } = action === 'delete'
        ? await call(`/vouchers/${voucher.id}`, { method: 'DELETE' })
        : await call(`/vouchers/${voucher.id}/${action}`, { method: 'POST' });
    busyId.value = null;

    if (error) {
        flash('error', error.message);

        return;
    }

    // Deactivate/reactivate move the voucher between tabs; reload this page.
    await load();
    flash('success', action === 'delete'
        ? `Voucher ${voucher.code} deleted.`
        : `Voucher ${voucher.code} ${action === 'deactivate' ? 'deactivated — moved to Inactive' : 'reactivated — moved to Active'}.`);
}

const stockConfirming = ref(false);

function submitStock() {
    const value = Number(stockDialog.value);

    if (!Number.isInteger(value) || value <= stockDialog.voucher.usageLimit) {
        stockDialog.error = `New limit must be a whole number above ${stockDialog.voucher.usageLimit}.`;

        return;
    }

    stockDialog.error = '';
    stockConfirming.value = true;
}

async function confirmStock() {
    const v = stockDialog.voucher;
    stockConfirming.value = false;
    busyId.value = v.id;
    const { data, error } = await call(`/vouchers/${v.id}/stock`, { method: 'POST', body: { usage_limit: Number(stockDialog.value) } });
    busyId.value = null;

    if (error) {
        stockDialog.error = error.message;

        return;
    }

    stockDialog.voucher = null;
    await load();
    flash('success', `Voucher ${v.code} is active again with ${data.usageLimit} total uses.`);
}

function toggleMenu(id) {
    menuOpenId.value = menuOpenId.value === id ? null : id;
}

function onDocClick(e) {
    if (menuOpenId.value && !e.target.closest?.('.svm-menu-wrap')) {
        menuOpenId.value = null;
    }
}
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));

/* -------------------------------------------------------------- create */

const view = ref('list');
const step = ref('type');
const isSaving = ref(false);
const formError = ref('');
const overlap = ref(null);

function todayIso(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function blankForm() {
    return {
        type: '',
        scope: '',
        productIds: [],
        discount_type: 'percentage',
        discount_value: '',
        max_discount: '',
        min_spend: '',
        starts_on: todayIso(),
        ends_on: todayIso(30),
        usage_limit: '100',
        per_user_limit: '1',
        budget_cap: '',
        stackable: true,
    };
}

const form = reactive(blankForm());

const steps = computed(() => {
    const list = [{ key: 'type', label: 'Type' }, { key: 'scope', label: 'Scope' }];

    if (form.scope === 'product') {
        list.push({ key: 'products', label: 'Products' });
    }

    list.push({ key: 'rules', label: 'Rules' }, { key: 'stacking', label: 'Stacking' }, { key: 'confirm', label: 'Confirm' });

    return list;
});

const stepIndex = computed(() => steps.value.findIndex(s => s.key === step.value));
const isShipping = computed(() => form.type === 'shipping');
const isPct = computed(() => !isShipping.value && form.discount_type === 'percentage');
const needsMinSpend = computed(() => form.scope === 'shop');

function openCreate() {
    Object.assign(form, blankForm());
    resetBudget();
    step.value = 'type';
    formError.value = '';
    productSearch.value = '';
    view.value = 'create';
}

async function startClone(v) {
    busyId.value = v.id;
    const { data, error } = await call(`/vouchers/${v.id}`);
    busyId.value = null;

    if (error) {
        flash('error', error.message);

        return;
    }

    Object.assign(form, blankForm(), {
        type: data.type,
        scope: data.scope,
        productIds: data.productIds || [],
        discount_type: data.type === 'shipping' ? 'percentage' : data.discountType,
        discount_value: data.type === 'shipping' ? '' : String(data.discountValue),
        max_discount: data.maxDiscount != null ? String(data.maxDiscount) : '',
        min_spend: data.minSpend ? String(data.minSpend) : '',
        usage_limit: String(data.usageLimit || 100),
        per_user_limit: String(data.perUserLimit || 1),
        budget_cap: data.budgetCap != null ? String(data.budgetCap) : '',
        stackable: data.stackable,
    });
    // Keep the cloned cap unless it matches what we'd compute anyway.
    budgetEdited.value = form.budget_cap !== '' && Number(form.budget_cap) !== suggestedBudget.value;
    formError.value = '';
    productSearch.value = '';
    // Dates always need a fresh look on a clone.
    step.value = 'rules';
    view.value = 'create';
}

function chooseType(type) {
    if (form.type !== type) {
        form.scope = '';
        form.productIds = [];
    }

    form.type = type;
    next();
}

function chooseScope(scope) {
    form.scope = scope;
    next();
}

// Costliest delivery option (Same Day) — keep in sync with CheckoutService::SHIPPING_OPTIONS.
const MAX_SHIPPING_FEE = 220;

// Budget = every use at its maximum value: the max discount (or fixed
// amount), or the costliest delivery fee for free shipping.
const perUseMax = computed(() => {
    if (isShipping.value) {
        return MAX_SHIPPING_FEE;
    }

    return isPct.value ? Number(form.max_discount) : Number(form.discount_value);
});

const suggestedBudget = computed(() => {
    const uses = Number(form.usage_limit);

    return perUseMax.value > 0 && uses > 0 ? Math.round(perUseMax.value * uses * 100) / 100 : null;
});

// Auto-filled until the seller types their own (lower) cap.
const budgetEdited = ref(false);

watch(suggestedBudget, (value) => {
    if (!budgetEdited.value && value) {
        form.budget_cap = String(value);
    }
}, { immediate: true });

function onBudgetInput(event) {
    budgetEdited.value = true;
    onNumber(event, form, 'budget_cap', 2);
}

function resetBudget() {
    budgetEdited.value = false;
    form.budget_cap = suggestedBudget.value ? String(suggestedBudget.value) : '';
}

function rulesError() {
    const value = Number(form.discount_value);

    if (!isShipping.value && !(value > 0)) {
        return 'Enter a discount value.';
    }

    if (isPct.value && value > 100) {
        return 'Percentage must be between 1 and 100.';
    }

    if (isPct.value && !(Number(form.max_discount) > 0)) {
        return 'Set a max discount cap for percentage vouchers.';
    }

    if (needsMinSpend.value && form.min_spend === '') {
        return 'Set a minimum spend (₱0 for none).';
    }

    if (!form.starts_on || !form.ends_on || form.ends_on < form.starts_on) {
        return 'End date must be on or after the start date.';
    }

    if (form.ends_on < todayIso()) {
        return 'End date must be today or later.';
    }

    if (!(Number(form.usage_limit) >= 1) || !(Number(form.per_user_limit) >= 1)) {
        return 'Usage limits must be at least 1.';
    }

    if (Number(form.per_user_limit) > Number(form.usage_limit)) {
        return "Per-buyer limit can't be more than the total usage limit.";
    }

    if (!(Number(form.budget_cap) > 0)) {
        return 'Set a budget cap.';
    }

    return '';
}

function next() {
    formError.value = '';

    if (step.value === 'scope' && !form.scope) {
        formError.value = 'Choose a scope.';

        return;
    }

    if (step.value === 'products' && !form.productIds.length) {
        formError.value = 'Select at least one product.';

        return;
    }

    if (step.value === 'rules') {
        formError.value = rulesError();

        if (formError.value) {
            return;
        }
    }

    step.value = steps.value[stepIndex.value + 1]?.key || step.value;
}

function back() {
    formError.value = '';

    if (stepIndex.value <= 0) {
        view.value = 'list';

        return;
    }

    step.value = steps.value[stepIndex.value - 1].key;
}

function payload(confirmOverlap = false) {
    // Today starts now; later dates start at midnight. Ends at the end of the day.
    const startsAt = form.starts_on === todayIso() ? new Date() : new Date(`${form.starts_on}T00:00:00`);

    return {
        type: form.type,
        scope: form.scope,
        product_ids: form.scope === 'product' ? form.productIds : [],
        // Shipping is always free shipping; the server fills its terms.
        discount_type: isShipping.value ? null : form.discount_type,
        discount_value: isShipping.value ? null : Number(form.discount_value),
        max_discount: isPct.value ? Number(form.max_discount) : null,
        min_spend: form.min_spend === '' ? (needsMinSpend.value ? null : 0) : Number(form.min_spend),
        starts_at: startsAt.toISOString(),
        expires_at: new Date(`${form.ends_on}T23:59:59`).toISOString(),
        usage_limit: Number(form.usage_limit),
        per_user_limit: Number(form.per_user_limit),
        budget_cap: Number(form.budget_cap),
        stackable: form.stackable,
        confirm_overlap: confirmOverlap,
    };
}

async function save(confirmOverlap = false) {
    isSaving.value = true;
    formError.value = '';
    const { data, error } = await call('/vouchers', { method: 'POST', body: payload(confirmOverlap) });
    isSaving.value = false;

    if (error?.status === 409) {
        overlap.value = error.payload?.conflicts || [];

        return;
    }

    if (error) {
        formError.value = error.message;

        return;
    }

    overlap.value = null;
    view.value = 'list';
    filters.status = '';

    if (tab.value === 'active' && page.value === 1) {
        await load();
    } else {
        tab.value = 'active';
        page.value = 1;
    }

    flash('success', `Voucher ${data.code} created.`);
}

/* --------------------------------------------------- product selection */

const productSearch = ref('');
const productById = computed(() => new Map(products.value.map(p => [String(p.id), p])));
const selectedSet = computed(() => new Set(form.productIds));

const pickerProducts = computed(() => {
    const q = productSearch.value.trim().toLowerCase();

    return products.value.filter(p => p.status !== 'archived' && (!q || p.name?.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q)));
});

const allFilteredSelected = computed(() =>
    pickerProducts.value.length > 0 && pickerProducts.value.every(p => selectedSet.value.has(String(p.id)))
);

function toggleProduct(p) {
    const id = String(p.id);

    if (selectedSet.value.has(id)) {
        form.productIds = form.productIds.filter(x => x !== id);
    } else if (form.productIds.length < MAX_PRODUCTS) {
        form.productIds = [...form.productIds, id];
    } else {
        formError.value = `A product voucher can cover at most ${MAX_PRODUCTS} products.`;
    }
}

function selectAll() {
    const ids = new Set(form.productIds);

    for (const p of pickerProducts.value) {
        if (ids.size >= MAX_PRODUCTS) {
            formError.value = `Selected the first ${MAX_PRODUCTS} products — the maximum per voucher.`;
            break;
        }

        ids.add(String(p.id));
    }

    form.productIds = [...ids];
}

function clearSelection() {
    form.productIds = [];
    formError.value = '';
}

function stockOf(p) {
    return typeof p.effective_stock === 'number' ? p.effective_stock : Number(p.stock ?? 0);
}

/* ------------------------------------------------------------- summary */

const valueLabel = computed(() => {
    if (isShipping.value) {
        return 'Free Shipping';
    }

    const v = Number(form.discount_value) || 0;
    const off = isPct.value ? `${v}% OFF` : `${peso(v)} OFF`;

    return form.type === 'shipping' ? (isPct.value && v >= 100 ? 'Free Shipping' : `${off} Shipping`) : off;
});

const selectedNames = computed(() => form.productIds.map(id => productById.value.get(id)?.name || 'Unknown product'));

const confirmCopy = computed(() => {
    if (isShipping.value && form.scope === 'shop') {
        return 'Are you sure you want to create this free shipping voucher? It waives the delivery fee on orders from your shop.';
    }

    if (form.scope === 'shop') {
        return 'Are you sure you want to create this shop voucher? It applies to every product in your shop.';
    }

    return 'Are you sure you want to apply this voucher to the products below:';
});

const summaryRows = computed(() => [
    { label: 'Type', value: form.type === 'shipping' ? 'Shipping fee' : 'Discount' },
    { label: 'Scope', value: form.scope === 'shop' ? 'Shop-wide' : `${form.productIds.length} product${form.productIds.length === 1 ? '' : 's'}` },
    { label: 'Value', value: isShipping.value ? 'Free shipping (full delivery fee)' : valueLabel.value + (isPct.value && form.max_discount ? ` · max ${peso(form.max_discount)}` : '') },
    { label: 'Min. spend', value: Number(form.min_spend) > 0 ? peso(form.min_spend) : 'None' },
    { label: 'Valid', value: `${shortDate(`${form.starts_on}T00:00:00`)} – ${shortDate(`${form.ends_on}T00:00:00`)}` },
    { label: 'Usage', value: `${form.usage_limit} total · ${form.per_user_limit} per buyer` },
    { label: 'Budget cap', value: peso(form.budget_cap) },
    { label: 'Stacking', value: form.stackable ? 'Can combine with other vouchers' : 'Only voucher on the order' },
]);

watch(() => form.discount_type, (type) => {
    if (type === 'fixed') {
        form.max_discount = '';
    }
});
</script>

<template>
    <div class="svm-overlay" @click.self="emit('close')">
        <div class="svm" role="dialog" aria-modal="true" aria-labelledby="svm-title">
            <!-- ======================= HEADER ======================= -->
            <header class="svm-head">
                <button v-if="view === 'create'" type="button" class="svm-icon-btn" aria-label="Back" @click="back">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <span v-else class="svm-head-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" /></svg>
                </span>
                <div class="svm-head-text">
                    <h2 id="svm-title">{{ view === 'create' ? 'Create voucher' : 'Vouchers' }}</h2>
                    <p v-if="view === 'list'">Seller-funded vouchers buyers claim and apply at checkout.</p>
                    <p v-else>Step {{ stepIndex + 1 }} of {{ steps.length }} · {{ steps[stepIndex]?.label }}</p>
                </div>
                <button v-if="view === 'list'" type="button" class="svm-btn primary" @click="openCreate">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" /></svg>
                    Create Voucher
                </button>
                <button type="button" class="svm-icon-btn" aria-label="Close" @click="emit('close')">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15" /></svg>
                </button>
            </header>

            <Transition name="svm-fade">
                <div v-if="notice" class="svm-notice" :class="notice.type" role="status">
                    <span>{{ notice.text }}</span>
                    <button type="button" aria-label="Dismiss" @click="notice = null">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15" /></svg>
                    </button>
                </div>
            </Transition>

            <!-- ======================= LIST ======================= -->
            <template v-if="view === 'list'">
                <div class="svm-toolbar">
                    <div class="svm-tabs" role="tablist">
                        <button
                            v-for="t in [{ key: 'active', label: 'Active' }, { key: 'inactive', label: 'Inactive' }]"
                            :key="t.key"
                            type="button"
                            role="tab"
                            class="svm-tab"
                            :class="{ active: tab === t.key }"
                            :aria-selected="tab === t.key"
                            @click="switchTab(t.key)"
                        >
                            {{ t.label }} <span class="svm-tab-count">{{ counts[t.key] }}</span>
                        </button>
                    </div>
                    <div class="svm-filters">
                        <select v-model="filters.scope" aria-label="Scope">
                            <option value="">All scopes</option>
                            <option value="shop">Shop</option>
                            <option value="product">Product</option>
                        </select>
                        <select v-model="filters.type" aria-label="Type">
                            <option value="">All types</option>
                            <option value="discount">Discount</option>
                            <option value="shipping">Shipping</option>
                        </select>
                        <select v-model="filters.status" aria-label="Status">
                            <option value="">All statuses</option>
                            <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                        <button v-if="hasFilters" type="button" class="svm-link" @click="clearFilters">Clear</button>
                    </div>
                </div>

                <div class="svm-body">
                    <div v-if="isLoading && !vouchers.length" class="svm-list" aria-busy="true">
                        <div v-for="n in 3" :key="n" class="svm-row svm-skeleton" />
                    </div>

                    <div v-else-if="loadError" class="svm-empty">
                        <p>{{ loadError }}</p>
                        <button type="button" class="svm-btn" @click="load">Try again</button>
                    </div>

                    <div v-else-if="!vouchers.length" class="svm-empty">
                        <span class="svm-empty-icon" aria-hidden="true">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" /></svg>
                        </span>
                        <template v-if="hasFilters">
                            <p>No vouchers match these filters.</p>
                            <button type="button" class="svm-btn" @click="clearFilters">Clear filters</button>
                        </template>
                        <template v-else-if="tab === 'inactive'">
                            <p>No deactivated or expired vouchers.</p>
                        </template>
                        <template v-else>
                            <p>No vouchers yet. Create one to boost sales — buyers claim it and it applies at checkout.</p>
                            <button type="button" class="svm-btn primary" @click="openCreate">Create Voucher</button>
                        </template>
                    </div>

                    <ul v-else class="svm-list" :class="{ loading: isLoading }">
                        <li v-for="v in vouchers" :key="v.id" class="svm-row" :class="[`is-${v.status}`, { busy: busyId === v.id }]">
                            <span class="svm-row-icon" :class="v.type" aria-hidden="true">
                                <svg v-if="v.type === 'shipping'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" /><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14" /><circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" /></svg>
                                <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 3H5a2 2 0 0 0-2 2v6l8.6 8.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8L11 3Z" /><circle cx="7.5" cy="7.5" r="1" /></svg>
                            </span>

                            <div class="svm-row-main">
                                <div class="svm-row-top">
                                    <strong class="svm-row-label">{{ v.label }}</strong>
                                    <span class="svm-pill" :class="STATUS_META[v.status]?.tone">{{ STATUS_META[v.status]?.label }}</span>
                                    <code class="svm-code">{{ v.code }}</code>
                                </div>
                                <p class="svm-row-sub">
                                    {{ scopeText(v) }}
                                    <template v-if="v.minSpend > 0"> · Min. spend {{ peso(v.minSpend) }}</template>
                                    <template v-if="v.maxDiscount"> · Max {{ peso(v.maxDiscount) }}</template>
                                    <template v-if="!v.stackable"> · Not stackable</template>
                                </p>
                                <p v-if="v.scope === 'product' && v.productPreview?.length" class="svm-row-products" :title="productPreview(v)">{{ productPreview(v) }}</p>
                                <p v-if="unavailableHint(v)" class="svm-row-warn">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 9v4M12 17h.01" /><circle cx="12" cy="12" r="9" /></svg>
                                    {{ unavailableHint(v) }}
                                </p>
                            </div>

                            <div class="svm-row-stats">
                                <span class="svm-row-date">
                                    <template v-if="v.status === 'expired'">Expired {{ shortDate(v.expiredOn) }}</template>
                                    <template v-else-if="v.status === 'scheduled'">Starts {{ shortDate(v.startsAt) }}</template>
                                    <template v-else>Until {{ shortDate(v.expiresAt) }}</template>
                                </span>
                                <div class="svm-meter" :title="`${v.usedCount} of ${v.usageLimit ?? '∞'} used`">
                                    <span :style="{ width: usagePercent(v) + '%' }" />
                                </div>
                                <span class="svm-row-usage">{{ v.usedCount }}/{{ v.usageLimit ?? '∞' }} used<template v-if="v.budgetCap"> · {{ peso(v.budgetUsed) }} of {{ peso(v.budgetCap) }}</template></span>
                            </div>

                            <div class="svm-menu-wrap">
                                <button
                                    type="button"
                                    class="svm-icon-btn"
                                    :aria-label="`Actions for voucher ${v.code}`"
                                    :aria-expanded="menuOpenId === v.id"
                                    :disabled="busyId === v.id"
                                    @click.stop="toggleMenu(v.id)"
                                >
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                                </button>
                                <div v-if="menuOpenId === v.id" class="svm-menu" role="menu">
                                    <button
                                        v-for="a in actionsFor(v)"
                                        :key="a.key"
                                        type="button"
                                        role="menuitem"
                                        :class="{ danger: a.danger }"
                                        :disabled="a.disabled"
                                        :title="a.hint || undefined"
                                        @click="runAction(v, a)"
                                    >
                                        {{ a.label }}
                                        <small v-if="a.hint">{{ a.hint }}</small>
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <nav v-if="lastPage > 1" class="svm-pager" aria-label="Voucher pages">
                        <button type="button" class="svm-btn" :disabled="page <= 1 || isLoading" aria-label="Previous page" @click="page--">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6" /></svg>
                        </button>
                        <span>Page {{ page }} of {{ lastPage }} · {{ counts[tab] }} vouchers</span>
                        <button type="button" class="svm-btn" :disabled="page >= lastPage || isLoading" aria-label="Next page" @click="page++">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6" /></svg>
                        </button>
                    </nav>
                </div>
            </template>

            <!-- ======================= CREATE ======================= -->
            <template v-else>
                <ol class="svm-steps" aria-label="Progress">
                    <li v-for="(s, i) in steps" :key="s.key" :class="{ done: i < stepIndex, current: i === stepIndex }">
                        <span>{{ i + 1 }}</span>{{ s.label }}
                    </li>
                </ol>

                <div class="svm-body">
                    <!-- Step: Type -->
                    <div v-if="step === 'type'" class="svm-choices">
                        <button type="button" class="svm-choice" :class="{ active: form.type === 'discount' }" @click="chooseType('discount')">
                            <span class="svm-row-icon discount"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 3H5a2 2 0 0 0-2 2v6l8.6 8.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8L11 3Z" /><circle cx="7.5" cy="7.5" r="1" /></svg></span>
                            <strong>Discount</strong>
                            <small>Takes money off the item price — shop-wide or on selected products.</small>
                        </button>
                        <button type="button" class="svm-choice" :class="{ active: form.type === 'shipping' }" @click="chooseType('shipping')">
                            <span class="svm-row-icon shipping"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" /><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14" /><circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" /></svg></span>
                            <strong>Shipping Fee</strong>
                            <small>Free shipping — waives the delivery fee, shop-wide or on selected products.</small>
                        </button>
                    </div>

                    <!-- Step: Scope -->
                    <div v-else-if="step === 'scope'" class="svm-choices">
                        <button type="button" class="svm-choice" :class="{ active: form.scope === 'shop' }" @click="chooseScope('shop')">
                            <span class="svm-row-icon discount"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7" /><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" /><path d="M2 7h20v3a2 2 0 0 1-2 2 2.7 2.7 0 0 1-2-1 2.7 2.7 0 0 1-4 0 2.7 2.7 0 0 1-4 0 2.7 2.7 0 0 1-4 0 2.7 2.7 0 0 1-2 1 2 2 0 0 1-2-2Z" /></svg></span>
                            <strong>{{ isShipping ? 'Shop-wide' : 'Shop Voucher' }}</strong>
                            <small>{{ isShipping ? 'Free shipping on any order from your shop. Needs a minimum spend.' : 'Applies to any product in your shop, including sale items. Needs a minimum spend.' }}</small>
                        </button>
                        <button type="button" class="svm-choice" :class="{ active: form.scope === 'product' }" @click="chooseScope('product')">
                            <span class="svm-row-icon discount"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 9 5-9 5-9-5 9-5Z" /><path d="m3 12 9 5 9-5" /><path d="m3 17 9 5 9-5" /></svg></span>
                            <strong>{{ isShipping ? 'Selected products' : 'Product Voucher' }}</strong>
                            <small>{{ isShipping ? 'Free shipping on orders containing a product you pick' : 'Applies only to the products you pick' }} (up to {{ MAX_PRODUCTS }}).</small>
                        </button>
                    </div>

                    <!-- Step: Products -->
                    <div v-else-if="step === 'products'" class="svm-picker">
                        <div class="svm-picker-bar">
                            <label class="svm-search">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                                <input v-model="productSearch" type="search" placeholder="Search products or SKU" aria-label="Search products">
                            </label>
                            <span class="svm-counter" :class="{ full: form.productIds.length >= MAX_PRODUCTS }">{{ form.productIds.length }} / {{ MAX_PRODUCTS }} selected</span>
                            <button v-if="allFilteredSelected" type="button" class="svm-btn" @click="clearSelection">Clear</button>
                            <button v-else type="button" class="svm-btn" :disabled="!pickerProducts.length" @click="selectAll">Select All</button>
                        </div>

                        <p v-if="!products.length" class="svm-hint">No products yet — add products to your inventory first.</p>
                        <ul v-else class="svm-picker-list">
                            <li v-for="p in pickerProducts.slice(0, PICKER_RENDER_LIMIT)" :key="p.id">
                                <label class="svm-pick" :class="{ active: selectedSet.has(String(p.id)) }">
                                    <input type="checkbox" :checked="selectedSet.has(String(p.id))" @change="toggleProduct(p)">
                                    <img v-if="p.images?.[0]?.url" :src="p.images[0].url" alt="" width="36" height="36" loading="lazy">
                                    <span v-else class="svm-pick-ph" aria-hidden="true" />
                                    <span class="svm-pick-name">{{ p.name }}</span>
                                    <span v-if="stockOf(p) <= 0" class="svm-pill muted">Out of stock</span>
                                    <span class="svm-pick-price">{{ peso(p.price) }}</span>
                                </label>
                            </li>
                        </ul>
                        <p v-if="pickerProducts.length > PICKER_RENDER_LIMIT" class="svm-hint">
                            Showing {{ PICKER_RENDER_LIMIT }} of {{ pickerProducts.length }} — search to narrow down. "Select All" includes every match.
                        </p>
                    </div>

                    <!-- Step: Rules -->
                    <div v-else-if="step === 'rules'" class="svm-form">
                        <p v-if="isShipping" class="svm-field full svm-ship-note">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 5 5 9-10" /></svg>
                            Free shipping — buyers pay ₱0 delivery on eligible orders.
                        </p>
                        <fieldset v-if="!isShipping" class="svm-field full">
                            <legend>Discount</legend>
                            <div class="svm-seg">
                                <button type="button" :class="{ active: isPct }" @click="form.discount_type = 'percentage'">Percentage (%)</button>
                                <button type="button" :class="{ active: !isPct }" @click="form.discount_type = 'fixed'">Fixed amount (₱)</button>
                            </div>
                        </fieldset>
                        <label v-if="!isShipping" class="svm-field">
                            <span>{{ isPct ? 'Discount (%)' : 'Discount (₱)' }}</span>
                            <input :value="form.discount_value" type="text" inputmode="decimal" maxlength="10" :placeholder="isPct ? 'e.g. 10' : 'e.g. 50'" @input="onNumber($event, form, 'discount_value', 2)">
                        </label>
                        <label v-if="isPct" class="svm-field">
                            <span>Max discount cap (₱)</span>
                            <input :value="form.max_discount" type="text" inputmode="decimal" maxlength="10" placeholder="e.g. 100" @input="onNumber($event, form, 'max_discount', 2)">
                        </label>
                        <label class="svm-field">
                            <span>Min. spend (₱){{ needsMinSpend ? '' : ' · optional' }}</span>
                            <input :value="form.min_spend" type="text" inputmode="decimal" maxlength="10" placeholder="0 for none" @input="onNumber($event, form, 'min_spend', 2)">
                        </label>
                        <label class="svm-field">
                            <span>Start date</span>
                            <input v-model="form.starts_on" type="date" :min="todayIso()">
                        </label>
                        <label class="svm-field">
                            <span>End date</span>
                            <input v-model="form.ends_on" type="date" :min="form.starts_on || todayIso()">
                        </label>
                        <label class="svm-field">
                            <span>Total usage limit</span>
                            <input :value="form.usage_limit" type="text" inputmode="numeric" maxlength="7" @input="onNumber($event, form, 'usage_limit')">
                        </label>
                        <label class="svm-field">
                            <span>Per-buyer limit</span>
                            <input :value="form.per_user_limit" type="text" inputmode="numeric" maxlength="4" @input="onNumber($event, form, 'per_user_limit')">
                        </label>
                        <label class="svm-field">
                            <span>Budget cap (₱)</span>
                            <input :value="form.budget_cap" type="text" inputmode="decimal" maxlength="12" :placeholder="isShipping ? 'Total shipping you\'ll fund' : 'Total discount you\'ll fund'" @input="onBudgetInput">
                            <small v-if="!budgetEdited && suggestedBudget">
                                Auto: {{ form.usage_limit }} uses × {{ peso(perUseMax) }}{{ isShipping ? ' (costliest delivery)' : '' }}. Lower it to cap your total spend.
                            </small>
                            <button v-else-if="suggestedBudget" type="button" class="svm-link sm" @click="resetBudget">
                                Reset to {{ peso(suggestedBudget) }} ({{ form.usage_limit }} × {{ peso(perUseMax) }})
                            </button>
                            <small>The voucher expires once this much has been given away.</small>
                        </label>
                    </div>

                    <!-- Step: Stacking -->
                    <div v-else-if="step === 'stacking'" class="svm-choices">
                        <button type="button" class="svm-choice" :class="{ active: form.stackable }" @click="form.stackable = true">
                            <strong>Allow stacking</strong>
                            <small>Can be combined with a voucher of the other kind (discount + shipping) and platform vouchers.</small>
                        </button>
                        <button type="button" class="svm-choice" :class="{ active: !form.stackable }" @click="form.stackable = false">
                            <strong>Don't allow stacking</strong>
                            <small>When off, this voucher cannot be combined with any other voucher.</small>
                        </button>
                    </div>

                    <!-- Step: Confirm -->
                    <div v-else class="svm-confirm">
                        <div class="svm-ticket">
                            <span class="svm-row-icon" :class="form.type">
                                <svg v-if="form.type === 'shipping'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" /><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14" /><circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" /></svg>
                                <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 3H5a2 2 0 0 0-2 2v6l8.6 8.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8L11 3Z" /><circle cx="7.5" cy="7.5" r="1" /></svg>
                            </span>
                            <strong>{{ valueLabel }}</strong>
                        </div>
                        <dl class="svm-summary">
                            <div v-for="row in summaryRows" :key="row.label">
                                <dt>{{ row.label }}</dt>
                                <dd>{{ row.value }}</dd>
                            </div>
                        </dl>
                        <p class="svm-confirm-copy">{{ confirmCopy }}</p>
                        <ul v-if="form.type === 'discount' && form.scope === 'product'" class="svm-confirm-products">
                            <li v-for="name in selectedNames.slice(0, 5)" :key="name">{{ name }}</li>
                            <li v-if="selectedNames.length > 5" class="more">and {{ selectedNames.length - 5 }} more</li>
                        </ul>
                        <p class="svm-hint">Vouchers can't be edited after creation (you can add stock once fully redeemed).</p>
                    </div>

                    <p v-if="formError" class="svm-error" role="alert">{{ formError }}</p>
                </div>

                <footer class="svm-foot">
                    <button type="button" class="svm-btn" @click="back">{{ stepIndex === 0 ? 'Cancel' : 'Back' }}</button>
                    <button v-if="step === 'confirm'" type="button" class="svm-btn primary" :disabled="isSaving" @click="save()">
                        {{ isSaving ? 'Creating…' : 'Create Voucher' }}
                    </button>
                    <button v-else-if="step !== 'type' && step !== 'scope'" type="button" class="svm-btn primary" @click="next">Continue</button>
                </footer>
            </template>

            <!-- ======================= DIALOGS ======================= -->
            <div v-if="confirmState" class="svm-dialog-backdrop" @click.self="confirmState = null">
                <div class="svm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="svm-confirm-title">
                    <h3 id="svm-confirm-title">{{ confirmState.title }}</h3>
                    <p>{{ confirmState.message }}</p>
                    <p class="svm-dialog-sub">{{ confirmState.voucher.label }} · {{ confirmState.voucher.code }}</p>
                    <div class="svm-dialog-actions">
                        <button type="button" class="svm-btn" @click="confirmState = null">Cancel</button>
                        <button type="button" class="svm-btn" :class="confirmState.danger ? 'danger' : 'primary'" @click="confirmAction">{{ confirmState.confirm }}</button>
                    </div>
                </div>
            </div>

            <div v-if="stockDialog.voucher" class="svm-dialog-backdrop" @click.self="stockDialog.voucher = null; stockConfirming = false">
                <div class="svm-dialog" role="dialog" aria-modal="true" aria-labelledby="svm-stock-title">
                    <template v-if="!stockConfirming">
                        <h3 id="svm-stock-title">Add stock</h3>
                        <p>{{ stockDialog.voucher.label }} has used all {{ stockDialog.voucher.usageLimit }} redemptions. Only the total usage limit can change.</p>
                        <label class="svm-field">
                            <span>New total usage limit</span>
                            <input :value="stockDialog.value" type="text" inputmode="numeric" maxlength="7" @input="onNumber($event, stockDialog, 'value')" @keydown.enter="submitStock">
                        </label>
                        <p v-if="stockDialog.error" class="svm-error">{{ stockDialog.error }}</p>
                        <div class="svm-dialog-actions">
                            <button type="button" class="svm-btn" @click="stockDialog.voucher = null">Cancel</button>
                            <button type="button" class="svm-btn primary" @click="submitStock">Continue</button>
                        </div>
                    </template>
                    <template v-else>
                        <h3 id="svm-stock-title">Add stock?</h3>
                        <p>Increasing the usage limit will reactivate this voucher. Continue?</p>
                        <p class="svm-dialog-sub">{{ stockDialog.voucher.usageLimit }} → {{ stockDialog.value }} total uses</p>
                        <div class="svm-dialog-actions">
                            <button type="button" class="svm-btn" @click="stockConfirming = false">Back</button>
                            <button type="button" class="svm-btn primary" :disabled="busyId === stockDialog.voucher.id" @click="confirmStock">Add stock</button>
                        </div>
                    </template>
                </div>
            </div>

            <div v-if="overlap" class="svm-dialog-backdrop" @click.self="overlap = null">
                <div class="svm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="svm-overlap-title">
                    <h3 id="svm-overlap-title">Overlapping product voucher</h3>
                    <p>Some selected products already have a product voucher during these dates. Buyers only get one discount voucher per order — the best one for them.</p>
                    <ul class="svm-overlap-list">
                        <li v-for="c in overlap" :key="c.id">
                            <strong>{{ c.label }}</strong> <code class="svm-code">{{ c.code }}</code>
                            <small>{{ c.products.join(', ') }}<template v-if="c.productsCount > c.products.length"> and {{ c.productsCount - c.products.length }} more</template></small>
                        </li>
                    </ul>
                    <div class="svm-dialog-actions">
                        <button type="button" class="svm-btn" @click="overlap = null">Go back</button>
                        <button type="button" class="svm-btn danger" :disabled="isSaving" @click="save(true)">Create anyway</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.svm-overlay {
    --c-surface: var(--inv-surface, #161b17);
    --c-surface-2: var(--inv-surface-2, #1d231e);
    --c-border: var(--inv-border, rgba(255, 255, 255, 0.08));
    --c-border-soft: var(--inv-border-soft, rgba(255, 255, 255, 0.06));
    --c-ink: var(--inv-ink-900, #f2f4f1);
    --c-ink-2: var(--inv-ink-700, #ced4cd);
    --c-muted: var(--inv-ink-500, #97a099);
    --c-faint: var(--inv-ink-400, #6d766e);
    --c-teal: #5eead4;
    --c-teal-strong: #14b8a6;
    --c-teal-bg: rgba(15, 118, 110, 0.18);
    --c-teal-border: rgba(20, 184, 166, 0.45);
    --c-amber: #fbbf24;
    --c-amber-bg: rgba(251, 191, 36, 0.14);
    --c-sky: #7dd3fc;
    --c-sky-bg: rgba(56, 189, 248, 0.14);
    --c-danger: #f87171;
    --c-danger-bg: rgba(248, 113, 113, 0.12);
    position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(2px);
}
.svm {
    position: relative; display: flex; flex-direction: column; width: min(880px, 100%); max-height: min(88vh, 900px);
    border: 1px solid var(--c-border); border-radius: 1.1rem; background: var(--c-surface); color: var(--c-ink);
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45); overflow: hidden;
}

/* ---------- Header ---------- */
.svm-head { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--c-border-soft); }
.svm-head-icon { display: inline-flex; padding: 0.55rem; border-radius: 0.7rem; background: var(--c-teal-bg); color: var(--c-teal); }
.svm-head-text { flex: 1; min-width: 0; }
.svm-head-text h2 { margin: 0; font-size: 1.05rem; font-weight: 700; }
.svm-head-text p { margin: 0.15rem 0 0; font-size: 0.8rem; color: var(--c-muted); }

/* ---------- Buttons ---------- */
.svm-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; min-height: 38px; padding: 0 0.95rem;
    border: 1px solid var(--c-border); border-radius: 0.65rem; background: var(--c-surface-2); color: var(--c-ink);
    font-size: 0.85rem; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background 0.15s ease, border-color 0.15s ease;
}
.svm-btn:hover:not(:disabled) { border-color: var(--c-teal-border); }
.svm-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.svm-btn.primary { border-color: transparent; background: var(--c-teal-strong); color: #04211d; }
.svm-btn.primary:hover:not(:disabled) { background: var(--c-teal); }
.svm-btn.danger { border-color: transparent; background: #dc2626; color: #fff; }
.svm-btn:focus-visible, .svm-icon-btn:focus-visible, .svm-choice:focus-visible, .svm-tab:focus-visible { outline: 2px solid var(--c-teal); outline-offset: 2px; }
.svm-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border: 0; border-radius: 0.6rem; background: none; color: var(--c-muted); cursor: pointer; }
.svm-icon-btn:hover:not(:disabled) { background: var(--c-surface-2); color: var(--c-ink); }
.svm-link { border: 0; background: none; color: var(--c-teal); font-size: 0.8rem; font-weight: 600; cursor: pointer; padding: 0.25rem; }
.svm-link.sm { align-self: flex-start; font-size: 0.75rem; padding: 0; }

/* ---------- Notice ---------- */
.svm-notice { display: flex; align-items: center; gap: 0.6rem; margin: 0.75rem 1.25rem 0; padding: 0.6rem 0.9rem; border-radius: 0.7rem; font-size: 0.85rem; font-weight: 600; }
.svm-notice span { flex: 1; }
.svm-notice button { display: inline-flex; border: 0; background: none; color: inherit; opacity: 0.7; cursor: pointer; }
.svm-notice.success { background: var(--c-teal-bg); color: var(--c-teal); }
.svm-notice.error { background: var(--c-danger-bg); color: #fca5a5; }
.svm-fade-enter-active, .svm-fade-leave-active { transition: opacity 0.2s ease; }
.svm-fade-enter-from, .svm-fade-leave-to { opacity: 0; }

/* ---------- Toolbar: tabs + filters ---------- */
.svm-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.85rem 1.25rem 0; }
.svm-tabs { display: inline-flex; padding: 3px; border-radius: 0.7rem; background: var(--c-surface-2); }
.svm-tab { display: inline-flex; align-items: center; gap: 0.4rem; min-height: 34px; padding: 0 0.9rem; border: 0; border-radius: 0.55rem; background: none; color: var(--c-muted); font-size: 0.85rem; font-weight: 600; cursor: pointer; }
.svm-tab.active { background: var(--c-surface); color: var(--c-ink); box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3); }
.svm-tab-count { padding: 0 0.45rem; border-radius: 999px; background: var(--c-border); font-size: 0.72rem; }
.svm-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.svm-filters select { min-height: 34px; padding: 0 0.6rem; border: 1px solid var(--c-border); border-radius: 0.55rem; background: var(--c-surface-2); color: var(--c-ink); font-size: 0.8rem; }

/* ---------- Body / list ---------- */
.svm-body { flex: 1; min-height: 0; overflow-y: auto; padding: 1rem 1.25rem 1.25rem; }
.svm-list { display: flex; flex-direction: column; gap: 0.6rem; margin: 0; padding: 0; list-style: none; }
.svm-row {
    display: grid; grid-template-columns: auto 1fr auto auto; align-items: center; gap: 0.9rem;
    padding: 0.85rem 0.9rem; border: 1px solid var(--c-border); border-radius: 0.85rem; background: var(--c-surface-2);
    transition: border-color 0.15s ease, opacity 0.15s ease;
}
.svm-row:hover { border-color: var(--c-teal-border); }
.svm-row.busy { opacity: 0.6; }
.svm-row.is-deactivated, .svm-row.is-expired { opacity: 0.75; }
.svm-row-icon { display: inline-flex; padding: 0.55rem; border-radius: 0.7rem; background: var(--c-teal-bg); color: var(--c-teal); }
.svm-row-icon.shipping { background: var(--c-sky-bg); color: var(--c-sky); }
.svm-row-main { min-width: 0; }
.svm-row-top { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.svm-row-label { font-size: 1rem; font-weight: 800; letter-spacing: -0.01em; }
.svm-row-sub, .svm-row-products { margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--c-muted); }
.svm-row-products { color: var(--c-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.svm-row-warn { display: inline-flex; align-items: center; gap: 0.3rem; margin: 0.3rem 0 0; font-size: 0.75rem; font-weight: 600; color: var(--c-amber); }
.svm-row-stats { display: flex; flex-direction: column; align-items: flex-end; gap: 0.3rem; min-width: 150px; font-size: 0.75rem; color: var(--c-muted); font-variant-numeric: tabular-nums; }
.svm-row-date { color: var(--c-ink-2); font-weight: 600; }
.svm-meter { width: 100%; height: 5px; border-radius: 999px; background: var(--c-border); overflow: hidden; }
.svm-meter span { display: block; height: 100%; border-radius: inherit; background: var(--c-teal-strong); }
.svm-row.is-fully_redeemed .svm-meter span { background: var(--c-amber); }
.svm-pill { padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; white-space: nowrap; }
.svm-pill.good { background: var(--c-teal-bg); color: var(--c-teal); }
.svm-pill.info { background: var(--c-sky-bg); color: var(--c-sky); }
.svm-pill.warn { background: var(--c-amber-bg); color: var(--c-amber); }
.svm-pill.muted { background: var(--c-border); color: var(--c-muted); }
.svm-code { padding: 0.1rem 0.45rem; border-radius: 0.4rem; background: var(--c-surface); color: var(--c-faint); font-size: 0.72rem; letter-spacing: 0.05em; }

/* ---------- ⋯ menu ---------- */
.svm-menu-wrap { position: relative; }
.svm-menu {
    position: absolute; right: 0; top: calc(100% + 4px); z-index: 5; display: flex; flex-direction: column; min-width: 210px; padding: 0.35rem;
    border: 1px solid var(--c-border); border-radius: 0.75rem; background: var(--c-surface); box-shadow: 0 12px 30px rgba(0, 0, 0, 0.45);
}
.svm-menu button { display: flex; flex-direction: column; align-items: flex-start; gap: 0.15rem; padding: 0.55rem 0.7rem; border: 0; border-radius: 0.5rem; background: none; color: var(--c-ink); font-size: 0.85rem; font-weight: 600; text-align: left; cursor: pointer; }
.svm-menu button:hover:not(:disabled) { background: var(--c-surface-2); }
.svm-menu button.danger { color: var(--c-danger); }
.svm-menu button:disabled { color: var(--c-faint); cursor: not-allowed; }
.svm-menu small { font-size: 0.7rem; font-weight: 500; color: var(--c-faint); line-height: 1.35; max-width: 220px; }

/* ---------- Empty / skeleton ---------- */
.svm-empty { display: flex; flex-direction: column; align-items: center; gap: 0.75rem; padding: 2.5rem 1rem; text-align: center; color: var(--c-muted); font-size: 0.9rem; }
.svm-empty p { margin: 0; max-width: 360px; }
.svm-empty-icon { display: inline-flex; padding: 0.8rem; border-radius: 1rem; background: var(--c-teal-bg); color: var(--c-teal); }
.svm-skeleton { height: 76px; background: linear-gradient(90deg, var(--c-surface-2) 25%, var(--c-border) 50%, var(--c-surface-2) 75%); background-size: 200% 100%; animation: svm-shimmer 1.2s infinite; }
@keyframes svm-shimmer { to { background-position: -200% 0; } }

/* ---------- Steps ---------- */
.svm-steps { display: flex; gap: 0.35rem; margin: 0; padding: 0.85rem 1.25rem 0; list-style: none; overflow-x: auto; }
.svm-steps li { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.65rem 0.3rem 0.3rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; color: var(--c-faint); white-space: nowrap; }
.svm-steps li span { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: var(--c-border); font-size: 0.7rem; }
.svm-steps li.current { background: var(--c-teal-bg); color: var(--c-teal); }
.svm-steps li.current span, .svm-steps li.done span { background: var(--c-teal-strong); color: #04211d; }
.svm-steps li.done { color: var(--c-ink-2); }

.svm-choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.85rem; }
.svm-choice {
    display: flex; flex-direction: column; align-items: flex-start; gap: 0.5rem; padding: 1.1rem; text-align: left;
    border: 1px solid var(--c-border); border-radius: 0.9rem; background: var(--c-surface-2); color: var(--c-ink); cursor: pointer;
    transition: border-color 0.15s ease, transform 0.15s ease;
}
.svm-choice:hover { border-color: var(--c-teal-border); transform: translateY(-1px); }
.svm-choice.active { border-color: var(--c-teal-strong); box-shadow: 0 0 0 2px var(--c-teal-bg); }
.svm-choice strong { font-size: 0.95rem; }
.svm-choice small { font-size: 0.8rem; line-height: 1.45; color: var(--c-muted); }

/* ---------- Product picker ---------- */
.svm-picker-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; margin-bottom: 0.75rem; }
.svm-search { display: flex; flex: 1; min-width: 200px; align-items: center; gap: 0.45rem; padding: 0 0.7rem; border: 1px solid var(--c-border); border-radius: 0.6rem; background: var(--c-surface-2); color: var(--c-muted); }
.svm-search input { flex: 1; min-height: 38px; border: 0; background: none; color: var(--c-ink); font-size: 0.85rem; outline: none; }
.svm-counter { font-size: 0.8rem; font-weight: 700; color: var(--c-teal); font-variant-numeric: tabular-nums; }
.svm-counter.full { color: var(--c-amber); }
.svm-picker-list { display: flex; flex-direction: column; gap: 0.35rem; margin: 0; padding: 0; list-style: none; }
.svm-pick { display: flex; align-items: center; gap: 0.7rem; min-height: 52px; padding: 0.45rem 0.7rem; border: 1px solid var(--c-border-soft); border-radius: 0.65rem; background: var(--c-surface-2); cursor: pointer; }
.svm-pick.active { border-color: var(--c-teal-border); background: var(--c-teal-bg); }
.svm-pick input { width: 16px; height: 16px; accent-color: var(--c-teal-strong); }
.svm-pick img, .svm-pick-ph { width: 36px; height: 36px; flex-shrink: 0; border-radius: 0.45rem; object-fit: cover; background: var(--c-border); }
.svm-pick-name { flex: 1; min-width: 0; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.svm-pick-price { font-size: 0.8rem; color: var(--c-muted); font-variant-numeric: tabular-nums; }

/* ---------- Rules form ---------- */
.svm-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.9rem 1rem; }
.svm-field { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; margin: 0; padding: 0; border: 0; }
.svm-field.full { grid-column: 1 / -1; }
.svm-field > span, .svm-field legend { font-size: 0.78rem; font-weight: 600; color: var(--c-ink-2); padding: 0; }
.svm-field small { font-size: 0.72rem; color: var(--c-faint); }
.svm-field input { min-height: 40px; padding: 0 0.75rem; border: 1px solid var(--c-border); border-radius: 0.6rem; background: var(--c-surface-2); color: var(--c-ink); font-size: 0.9rem; color-scheme: dark; }
.svm-field input:focus { outline: none; border-color: var(--c-teal-strong); }
.svm-seg { display: inline-flex; padding: 3px; border-radius: 0.65rem; background: var(--c-surface-2); }
.svm-seg button { min-height: 34px; padding: 0 0.9rem; border: 0; border-radius: 0.5rem; background: none; color: var(--c-muted); font-size: 0.85rem; font-weight: 600; cursor: pointer; }
.svm-seg button.active { background: var(--c-teal-strong); color: #04211d; }

/* ---------- Confirm ---------- */
.svm-confirm { display: flex; flex-direction: column; gap: 1rem; }
.svm-ticket { display: flex; align-items: center; gap: 0.75rem; padding: 0.9rem 1rem; border: 1px dashed var(--c-teal-border); border-radius: 0.9rem; background: var(--c-teal-bg); }
.svm-ticket strong { font-size: 1.35rem; font-weight: 800; color: var(--c-teal); }
.svm-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem 1rem; margin: 0; }
.svm-summary dt { font-size: 0.72rem; color: var(--c-muted); }
.svm-summary dd { margin: 0.15rem 0 0; font-size: 0.85rem; font-weight: 600; color: var(--c-ink-2); }
.svm-confirm-copy { margin: 0; font-size: 0.9rem; font-weight: 600; }
.svm-confirm-products { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: -0.4rem 0 0; padding: 0; list-style: none; }
.svm-confirm-products li { padding: 0.25rem 0.65rem; border-radius: 999px; background: var(--c-surface-2); border: 1px solid var(--c-border); font-size: 0.78rem; }
.svm-confirm-products li.more { color: var(--c-teal); border-color: var(--c-teal-border); }

.svm-list.loading { opacity: 0.55; pointer-events: none; transition: opacity 0.15s ease; }
.svm-pager { display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-top: 1rem; font-size: 0.8rem; color: var(--c-muted); font-variant-numeric: tabular-nums; }
.svm-pager .svm-btn { width: 38px; padding: 0; }
.svm-ship-note { flex-direction: row; align-items: center; gap: 0.5rem; padding: 0.7rem 0.9rem; border-radius: 0.7rem; background: var(--c-sky-bg); color: var(--c-sky); font-size: 0.85rem; font-weight: 600; }
.svm-hint { margin: 0.75rem 0 0; font-size: 0.78rem; color: var(--c-faint); }
.svm-error { margin: 0.9rem 0 0; padding: 0.55rem 0.8rem; border-radius: 0.6rem; background: var(--c-danger-bg); color: #fca5a5; font-size: 0.82rem; font-weight: 600; }
.svm-foot { display: flex; justify-content: space-between; gap: 0.6rem; padding: 0.85rem 1.25rem; border-top: 1px solid var(--c-border-soft); }

/* ---------- Dialogs ---------- */
.svm-dialog-backdrop { position: absolute; inset: 0; z-index: 10; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0, 0, 0, 0.55); }
.svm-dialog { width: min(440px, 100%); padding: 1.25rem; border: 1px solid var(--c-border); border-radius: 1rem; background: var(--c-surface); box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5); }
.svm-dialog h3 { margin: 0 0 0.5rem; font-size: 1rem; }
.svm-dialog p { margin: 0 0 0.75rem; font-size: 0.875rem; line-height: 1.5; color: var(--c-ink-2); }
.svm-dialog .svm-dialog-sub { font-size: 0.8rem; color: var(--c-muted); }
.svm-dialog-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; }
.svm-overlap-list { display: flex; flex-direction: column; gap: 0.5rem; max-height: 200px; margin: 0; padding: 0; overflow-y: auto; list-style: none; }
.svm-overlap-list li { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; padding: 0.55rem 0.7rem; border-radius: 0.6rem; background: var(--c-surface-2); font-size: 0.85rem; }
.svm-overlap-list small { flex-basis: 100%; color: var(--c-muted); }

/* ---------- Small screens ---------- */
@media (max-width: 640px) {
    .svm-overlay { padding: 0; align-items: flex-end; }
    .svm { max-height: 94vh; border-radius: 1.1rem 1.1rem 0 0; }
    .svm-head .svm-btn.primary { padding: 0 0.7rem; }
    .svm-row { grid-template-columns: auto 1fr auto; }
    .svm-row-stats { grid-column: 2 / 4; grid-row: 2; align-items: stretch; min-width: 0; }
    .svm-form, .svm-summary { grid-template-columns: 1fr; }
}
</style>
