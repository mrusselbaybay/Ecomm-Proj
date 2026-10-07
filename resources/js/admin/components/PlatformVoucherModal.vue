<script setup>
/*
| Platform Vouchers modal (Admin\VoucherController), opened from the admin
| sidebar (Promotions → Vouchers) or the dashboard's "Create Voucher".
| Active (active, scheduled, fully redeemed, products unavailable) /
| Inactive (deactivated, expired) tabs, server-paginated 5 at a time, with
| scope/type/status filters and a ⋯ menu per voucher. Create flow:
| Type → Scope → Categories → Eligibility → Rules → Distribution →
| Stacking → Confirm. Always platform-funded; immutable after creation
| except adding usage stock to a Fully Redeemed voucher.
*/
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const props = defineProps({
    // Open straight into the create flow (dashboard quick action).
    startCreate: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

// Costliest delivery option (Same Day) — keep in sync with CheckoutService::SHIPPING_OPTIONS.
const MAX_SHIPPING_FEE = 220;

const STATUS_META = {
    active: { label: 'Active', tone: 'good' },
    scheduled: { label: 'Scheduled', tone: 'info' },
    fully_redeemed: { label: 'Fully Redeemed', tone: 'warn' },
    unavailable: { label: 'Products Unavailable', tone: 'warn' },
    deactivated: { label: 'Deactivated', tone: 'muted' },
    expired: { label: 'Expired', tone: 'muted' },
};

const ELIGIBILITY = {
    all: { label: 'All users', hint: 'Every buyer on BuyTheWay.' },
    new: { label: 'New users', hint: "Buyers who haven't placed an order yet." },
    lapsed: { label: 'Lapsed users', hint: "Buyers who've ordered before but not recently." },
    region: { label: 'By region', hint: 'Buyers shipping to the regions you pick.' },
};

const DISTRIBUTION = {
    claim: { label: 'Voucher center', hint: 'Buyers browse and tap Claim.' },
    auto_claim: { label: 'Auto-claim', hint: "Lands in every eligible buyer's wallet automatically." },
    push: { label: 'Direct push', hint: 'Pushed to the targeted segment only — best with New, Lapsed or Region.' },
    auto_apply: { label: 'Auto-apply at checkout', hint: 'No claim step — applies itself when the cart qualifies.' },
};

const { adminFetch, supabase } = useAdmin();

async function call(path, { method = 'GET', body } = {}) {
    try {
        const response = await adminFetch(`/api/admin${path}`, {
            method,
            headers: body !== undefined ? { 'Content-Type': 'application/json' } : {},
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });

        return { data: (await response.json()).data, error: null };
    } catch (error) {
        return { data: null, error };
    }
}

// adminFetch throws on non-2xx without the body; the create call needs the
// 409 conflicts and field errors, so it uses fetch through the same auth.
async function createVoucher(body) {
    const { data: { session } } = await supabase.auth.getSession();
    const response = await fetch('/api/admin/vouchers', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${session?.access_token}` },
        body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({}));

    return { status: response.status, payload };
}

function peso(n) {
    return `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
}

function shortDate(iso) {
    return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

/* ---------------------------------------------------------- notice */

const notice = ref(null);

function flash(type, text) {
    notice.value = { type, text };
}

/* ------------------------------------------------------------ list */

const vouchers = ref([]);
const page = ref(1);
const lastPage = ref(1);
const counts = ref({ active: 0, inactive: 0 });
const spend = ref(null);
const isLoading = ref(false);
const loadError = ref('');
const tab = ref('active');
const filters = reactive({ scope: '', type: '', status: '' });
const menuOpenId = ref(null);
const busyId = ref(null);
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
    spend.value = data.spend;
    lastPage.value = data.meta.lastPage;

    if (!vouchers.value.length && page.value > 1) {
        page.value = data.meta.lastPage;
    }
}

watch(page, load);
watch([tab, () => filters.scope, () => filters.type, () => filters.status], () => {
    if (page.value === 1) {
        load();
    } else {
        page.value = 1;
    }
});

const statusOptions = computed(() => (tab.value === 'active'
    ? [
        { value: 'active', label: 'Active' },
        { value: 'fully_redeemed', label: 'Fully Redeemed' },
        { value: 'unavailable', label: 'Products Unavailable' },
    ]
    : [{ value: 'deactivated', label: 'Deactivated' }, { value: 'expired', label: 'Expired' }]));

const hasFilters = computed(() => filters.scope || filters.type || filters.status);

function switchTab(key) {
    filters.status = '';
    tab.value = key;
}

function clearFilters() {
    filters.scope = '';
    filters.type = '';
    filters.status = '';
}

function scopeText(v) {
    const where = v.scope === 'platform'
        ? 'Platform-wide'
        : `${v.categories.length} categor${v.categories.length === 1 ? 'y' : 'ies'}`;

    return v.type === 'shipping' ? `Shipping fee · ${where}` : where;
}

function audienceText(v) {
    if (v.eligibility === 'lapsed') {
        return `Lapsed ${v.eligibilityMeta?.lapsed_days || 60}+ days`;
    }

    if (v.eligibility === 'region') {
        const regions = v.eligibilityMeta?.regions || [];

        return `${regions.length} region${regions.length === 1 ? '' : 's'}`;
    }

    return ELIGIBILITY[v.eligibility]?.label || 'All users';
}

function usagePercent(v) {
    return v.usageLimit ? Math.min(100, Math.round((v.usedCount / v.usageLimit) * 100)) : 0;
}

function actionsFor(v) {
    const del = {
        key: 'delete', label: 'Delete', danger: true, disabled: !v.canDelete,
        hint: v.canDelete ? '' : 'This voucher has been redeemed and cannot be deleted. You can deactivate it instead.',
    };

    switch (v.status) {
        case 'active':
        case 'scheduled':
        case 'unavailable':
            return [{ key: 'deactivate', label: 'Deactivate' }, del];
        case 'fully_redeemed':
            return [{ key: 'stock', label: 'Edit (add stock)' }, { key: 'deactivate', label: 'Deactivate' }, del];
        case 'deactivated':
            return [{ key: 'reactivate', label: 'Reactivate' }, del];
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

const confirmState = ref(null);
const stockDialog = reactive({ voucher: null, value: '', error: '' });
const stockConfirming = ref(false);

function runAction(v, action) {
    menuOpenId.value = null;

    if (action.disabled) {
        return;
    }

    if (action.key === 'stock') {
        stockDialog.voucher = v;
        stockDialog.value = String((v.usageLimit || 0) + Math.max(10, Math.round((v.usageLimit || 0) * 0.5)));
        stockDialog.error = '';
        stockConfirming.value = false;

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

    await load();
    flash('success', action === 'delete'
        ? `Voucher ${voucher.code} deleted.`
        : `Voucher ${voucher.code} ${action === 'deactivate' ? 'deactivated — moved to Inactive' : 'reactivated — moved to Active'}.`);
}

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
    busyId.value = v.id;
    const { data, error } = await call(`/vouchers/${v.id}/stock`, { method: 'POST', body: { usage_limit: Number(stockDialog.value) } });
    busyId.value = null;
    stockConfirming.value = false;

    if (error) {
        stockDialog.error = error.message;

        return;
    }

    stockDialog.voucher = null;
    await load();
    flash('success', `Voucher ${v.code} is active again with ${data.usageLimit} total uses.`);
}

/* ------------------------------------------------- numeric inputs */

// Digits only — plus one "." and up to `decimals` places for money; no
// leading zeros. Rewritten in place so letters/symbols never stick.
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

/* ---------------------------------------------------------- create */

const view = ref('list');
const step = ref('type');
const isSaving = ref(false);
const formError = ref('');
const overlap = ref(null);
const options = ref({ categories: [], maxCategories: 50, defaultLapsedDays: 60 });
const regions = ref([]);

function todayIso(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function blankForm() {
    return {
        type: '',
        scope: '',
        categories: [],
        eligibility: 'all',
        lapsed_days: String(options.value.defaultLapsedDays || 60),
        regions: [],
        discount_type: 'percentage',
        discount_value: '',
        max_discount: '',
        min_spend: '0',
        starts_on: todayIso(),
        ends_on: todayIso(30),
        usage_limit: '1000',
        per_user_limit: '1',
        budget_cap: '',
        distribution: 'claim',
        stackable: true,
    };
}

const form = reactive(blankForm());

const isShipping = computed(() => form.type === 'shipping');
const isPct = computed(() => !isShipping.value && form.discount_type === 'percentage');

const steps = computed(() => {
    const list = [{ key: 'type', label: 'Type' }, { key: 'scope', label: 'Scope' }];

    if (form.scope === 'category') {
        list.push({ key: 'categories', label: 'Categories' });
    }

    list.push(
        { key: 'eligibility', label: 'Eligibility' },
        { key: 'rules', label: 'Rules' },
        { key: 'distribution', label: 'Distribution' },
        { key: 'stacking', label: 'Stacking' },
        { key: 'confirm', label: 'Confirm' },
    );

    return list;
});

const stepIndex = computed(() => steps.value.findIndex(s => s.key === step.value));

// Budget = every use at its maximum value (max discount / fixed amount /
// costliest delivery fee), until the admin types a lower cap.
const perUseMax = computed(() => (isShipping.value
    ? MAX_SHIPPING_FEE
    : isPct.value ? Number(form.max_discount) : Number(form.discount_value)));

const suggestedBudget = computed(() => {
    const uses = Number(form.usage_limit);

    return perUseMax.value > 0 && uses > 0 ? Math.round(perUseMax.value * uses * 100) / 100 : null;
});

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

async function ensureOptions() {
    if (!options.value.categories.length) {
        const { data } = await call('/vouchers/options');

        if (data) {
            options.value = data;
        }
    }
}

async function ensureRegions() {
    if (regions.value.length) {
        return;
    }

    try {
        const response = await fetch('/api/psgc/regions', { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        regions.value = (payload.data || payload || []).map(r => ({
            code: r.code,
            // Saved addresses use either form; match both server-side.
            names: [r.name, r.regionName].filter(Boolean),
            label: r.regionName && r.regionName !== r.name ? `${r.name} — ${r.regionName}` : r.name,
        }));
    } catch {
        regions.value = [];
    }
}

function openCreate() {
    Object.assign(form, blankForm());
    resetBudget();
    step.value = 'type';
    formError.value = '';
    view.value = 'create';
    ensureOptions();
}

async function startClone(v) {
    busyId.value = v.id;
    const { data, error } = await call(`/vouchers/${v.id}`);
    busyId.value = null;

    if (error) {
        flash('error', error.message);

        return;
    }

    await ensureOptions();
    Object.assign(form, blankForm(), {
        type: data.type,
        scope: data.scope,
        categories: data.categories || [],
        eligibility: data.eligibility,
        lapsed_days: String(data.eligibilityMeta?.lapsed_days || options.value.defaultLapsedDays),
        regions: data.eligibilityMeta?.regions || [],
        discount_type: data.type === 'shipping' ? 'percentage' : data.discountType,
        discount_value: data.type === 'shipping' ? '' : String(data.discountValue),
        max_discount: data.maxDiscount != null ? String(data.maxDiscount) : '',
        min_spend: String(data.minSpend || 0),
        usage_limit: String(data.usageLimit || 1000),
        per_user_limit: String(data.perUserLimit || 1),
        budget_cap: data.budgetCap != null ? String(data.budgetCap) : '',
        distribution: data.distribution,
        stackable: data.stackable,
    });
    budgetEdited.value = form.budget_cap !== '' && Number(form.budget_cap) !== suggestedBudget.value;
    formError.value = '';
    // Dates always need a fresh look on a clone.
    step.value = 'rules';
    view.value = 'create';
}

function chooseType(type) {
    if (form.type !== type) {
        form.scope = '';
        form.categories = [];
    }

    form.type = type;
    next();
}

function chooseScope(scope) {
    form.scope = scope;
    next();
}

function chooseEligibility(value) {
    form.eligibility = value;

    if (value === 'region') {
        ensureRegions();
    }
}

/* Category selection: Select All ↔ Clear, hard cap enforced on selection. */
const allCategoriesSelected = computed(() =>
    options.value.categories.length > 0 && options.value.categories.every(c => form.categories.includes(c))
);

function toggleCategory(category) {
    if (form.categories.includes(category)) {
        form.categories = form.categories.filter(c => c !== category);
    } else if (form.categories.length < options.value.maxCategories) {
        form.categories = [...form.categories, category];
    } else {
        formError.value = `A voucher can cover at most ${options.value.maxCategories} categories.`;
    }
}

function selectAllCategories() {
    form.categories = options.value.categories.slice(0, options.value.maxCategories);
}

function toggleRegion(region) {
    const has = region.names.some(n => form.regions.includes(n));
    form.regions = has
        ? form.regions.filter(n => !region.names.includes(n))
        : [...form.regions, ...region.names];
}

function regionSelected(region) {
    return region.names.some(n => form.regions.includes(n));
}

const selectedRegionCount = computed(() => regions.value.filter(regionSelected).length);

/* Per-step validation; Continue/Create stay disabled until it passes. */
const stepError = computed(() => {
    switch (step.value) {
        case 'scope':
            return form.scope ? '' : 'Choose a scope.';
        case 'categories':
            return form.categories.length ? '' : 'Select at least one category.';
        case 'eligibility':
            if (form.eligibility === 'lapsed' && !(Number(form.lapsed_days) >= 7)) {
                return 'Lapsed means at least 7 days without an order.';
            }

            return form.eligibility === 'region' && !form.regions.length ? 'Pick at least one region.' : '';
        case 'rules':
            return rulesError();
        default:
            return '';
    }
});

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

    if (form.min_spend === '') {
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

    return Number(form.budget_cap) > 0 ? '' : 'Set a budget cap.';
}

function next() {
    formError.value = stepError.value;

    if (formError.value) {
        return;
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
    const startsAt = form.starts_on === todayIso() ? new Date() : new Date(`${form.starts_on}T00:00:00`);

    return {
        type: form.type,
        scope: form.scope,
        categories: form.scope === 'category' ? form.categories : [],
        eligibility: form.eligibility,
        lapsed_days: form.eligibility === 'lapsed' ? Number(form.lapsed_days) : null,
        regions: form.eligibility === 'region' ? form.regions : [],
        discount_type: isShipping.value ? null : form.discount_type,
        discount_value: isShipping.value ? null : Number(form.discount_value),
        max_discount: isPct.value ? Number(form.max_discount) : null,
        min_spend: Number(form.min_spend || 0),
        starts_at: startsAt.toISOString(),
        expires_at: new Date(`${form.ends_on}T23:59:59`).toISOString(),
        usage_limit: Number(form.usage_limit),
        per_user_limit: Number(form.per_user_limit),
        budget_cap: Number(form.budget_cap),
        distribution: form.distribution,
        stackable: form.stackable,
        confirm_overlap: confirmOverlap,
    };
}

async function save(confirmOverlap = false) {
    isSaving.value = true;
    formError.value = '';
    let result;

    try {
        result = await createVoucher(payload(confirmOverlap));
    } catch {
        result = { status: 0, payload: { message: 'Could not reach the server. Please try again.' } };
    }

    isSaving.value = false;

    if (result.status === 409) {
        overlap.value = result.payload.conflicts || [];

        return;
    }

    if (result.status !== 201) {
        formError.value = Object.values(result.payload.errors || {})[0]?.[0] || result.payload.message || 'Could not create the voucher.';

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

    flash('success', `Voucher ${result.payload.data.code} created.`);
}

/* --------------------------------------------------------- summary */

const valueLabel = computed(() => {
    if (isShipping.value) {
        return 'Free Shipping';
    }

    const v = Number(form.discount_value) || 0;

    return isPct.value ? `${v}% OFF` : `${peso(v)} OFF`;
});

const scopeLabel = computed(() => (form.scope === 'platform' ? 'all products on BuyTheWay' : 'the selected categories'));

const audienceLabel = computed(() => {
    switch (form.eligibility) {
        case 'new':
            return 'new users';
        case 'lapsed':
            return `users with no order in ${form.lapsed_days}+ days`;
        case 'region':
            return `buyers in ${selectedRegionCount.value || form.regions.length} region${(selectedRegionCount.value || form.regions.length) === 1 ? '' : 's'}`;
        default:
            return 'all users';
    }
});

const confirmCopy = computed(() =>
    `Are you sure you want to create this platform voucher for ${scopeLabel.value}? `
    + `This will be visible to ${audienceLabel.value} from ${shortDate(`${form.starts_on}T00:00:00`)} to ${shortDate(`${form.ends_on}T00:00:00`)}.`
);

const summaryRows = computed(() => [
    { label: 'Type', value: isShipping.value ? 'Free shipping (full delivery fee)' : `Discount · ${valueLabel.value}${isPct.value ? ` · max ${peso(form.max_discount)}` : ''}` },
    { label: 'Scope', value: form.scope === 'platform' ? 'Platform-wide' : `${form.categories.length} categor${form.categories.length === 1 ? 'y' : 'ies'}` },
    { label: 'Eligibility', value: audienceLabel.value.replace(/^./, c => c.toUpperCase()) },
    { label: 'Min. spend', value: Number(form.min_spend) > 0 ? peso(form.min_spend) : 'None' },
    { label: 'Usage', value: `${form.usage_limit} total · ${form.per_user_limit} per buyer` },
    { label: 'Budget cap', value: peso(form.budget_cap) },
    { label: 'Distribution', value: DISTRIBUTION[form.distribution].label },
    { label: 'Stacking', value: form.stackable ? 'Combines with shop vouchers' : 'Only voucher on the order' },
    { label: 'Funding', value: 'Platform-funded' },
]);

/* ----------------------------------------------------------- shell */

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

function onDocClick(e) {
    if (menuOpenId.value && !e.target.closest?.('.pvm-menu-wrap')) {
        menuOpenId.value = null;
    }
}

onMounted(() => {
    load();
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onDocClick);

    if (props.startCreate) {
        openCreate();
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('click', onDocClick);
});
</script>

<template>
    <div class="pvm-overlay" @click.self="emit('close')">
        <div class="pvm" role="dialog" aria-modal="true" aria-labelledby="pvm-title">
            <!-- ======================= HEADER ======================= -->
            <header class="pvm-head">
                <button v-if="view === 'create'" type="button" class="pvm-icon-btn" aria-label="Back" @click="back">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <span v-else class="pvm-head-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" /></svg>
                </span>
                <div class="pvm-head-text">
                    <h2 id="pvm-title">{{ view === 'create' ? 'Create platform voucher' : 'Platform vouchers' }}</h2>
                    <p v-if="view === 'list'">Platform-funded vouchers for acquisition, retention and campaigns.</p>
                    <p v-else>Step {{ stepIndex + 1 }} of {{ steps.length }} · {{ steps[stepIndex]?.label }}</p>
                </div>
                <button v-if="view === 'list'" type="button" class="pvm-btn primary" @click="openCreate">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" /></svg>
                    Create Voucher
                </button>
                <button type="button" class="pvm-icon-btn" aria-label="Close" @click="emit('close')">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15" /></svg>
                </button>
            </header>

            <div v-if="notice" class="pvm-notice" :class="notice.type" role="status">
                <span>{{ notice.text }}</span>
                <button type="button" aria-label="Dismiss" @click="notice = null">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15" /></svg>
                </button>
            </div>

            <!-- ======================= LIST ======================= -->
            <template v-if="view === 'list'">
                <!-- Platform-funded cost (non-cancelled redemptions); full ledger view in Commission. -->
                <dl class="pvm-spend" aria-label="Platform voucher spend">
                    <div>
                        <dt>Spent this month</dt>
                        <dd>{{ spend ? peso(spend.month) : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Spent all time</dt>
                        <dd>{{ spend ? peso(spend.allTime) : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Vouchered orders</dt>
                        <dd>{{ spend ? spend.orders.toLocaleString('en-PH') : '—' }}</dd>
                    </div>
                </dl>

                <div class="pvm-toolbar">
                    <div class="pvm-tabs" role="tablist">
                        <button
                            v-for="t in [{ key: 'active', label: 'Active' }, { key: 'inactive', label: 'Inactive' }]"
                            :key="t.key"
                            type="button"
                            role="tab"
                            class="pvm-tab"
                            :class="{ active: tab === t.key }"
                            :aria-selected="tab === t.key"
                            @click="switchTab(t.key)"
                        >
                            {{ t.label }} <span class="pvm-tab-count">{{ counts[t.key] }}</span>
                        </button>
                    </div>
                    <div class="pvm-filters">
                        <select v-model="filters.scope" aria-label="Scope">
                            <option value="">All scopes</option>
                            <option value="platform">Platform-wide</option>
                            <option value="category">Category</option>
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
                        <button v-if="hasFilters" type="button" class="pvm-link" @click="clearFilters">Clear</button>
                    </div>
                </div>

                <div class="pvm-body">
                    <div v-if="isLoading && !vouchers.length" class="pvm-list" aria-busy="true">
                        <div v-for="n in 3" :key="n" class="pvm-row pvm-skeleton" />
                    </div>

                    <div v-else-if="loadError" class="pvm-empty">
                        <p>{{ loadError }}</p>
                        <button type="button" class="pvm-btn" @click="load">Try again</button>
                    </div>

                    <div v-else-if="!vouchers.length" class="pvm-empty">
                        <template v-if="hasFilters">
                            <p>No vouchers match these filters.</p>
                            <button type="button" class="pvm-btn" @click="clearFilters">Clear filters</button>
                        </template>
                        <p v-else-if="tab === 'inactive'">No deactivated or expired vouchers.</p>
                        <template v-else>
                            <p>No platform vouchers yet. Create one to drive sign-ups, win back buyers or run a campaign.</p>
                            <button type="button" class="pvm-btn primary" @click="openCreate">Create Voucher</button>
                        </template>
                    </div>

                    <ul v-else class="pvm-list" :class="{ loading: isLoading }">
                        <li v-for="v in vouchers" :key="v.id" class="pvm-row" :class="[`is-${v.status}`, { busy: busyId === v.id }]">
                            <span class="pvm-row-icon" :class="v.type" aria-hidden="true">
                                <svg v-if="v.type === 'shipping'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" /><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14" /><circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" /></svg>
                                <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 3H5a2 2 0 0 0-2 2v6l8.6 8.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8L11 3Z" /><circle cx="7.5" cy="7.5" r="1" /></svg>
                            </span>

                            <div class="pvm-row-main">
                                <div class="pvm-row-top">
                                    <strong class="pvm-row-label">{{ v.label }}</strong>
                                    <span class="pvm-pill" :class="STATUS_META[v.status]?.tone">{{ STATUS_META[v.status]?.label }}</span>
                                    <code class="pvm-code">{{ v.code }}</code>
                                </div>
                                <p class="pvm-row-sub">
                                    {{ scopeText(v) }} · {{ audienceText(v) }} · {{ DISTRIBUTION[v.distribution]?.label }}
                                    <template v-if="v.minSpend > 0"> · Min. {{ peso(v.minSpend) }}</template>
                                    <template v-if="!v.stackable"> · Not stackable</template>
                                </p>
                                <p v-if="v.categories.length" class="pvm-row-faint" :title="v.categories.join(', ')">
                                    {{ v.categories.slice(0, 5).join(', ') }}<template v-if="v.categories.length > 5"> and {{ v.categories.length - 5 }} more</template>
                                </p>
                                <p v-if="v.createdBy" class="pvm-row-faint">
                                    Created by {{ v.createdBy }}<template v-if="v.deactivatedBy"> · Deactivated by {{ v.deactivatedBy }}</template>
                                </p>
                            </div>

                            <div class="pvm-row-stats">
                                <span class="pvm-row-date">
                                    <template v-if="v.status === 'expired'">Expired {{ shortDate(v.expiredOn) }}</template>
                                    <template v-else-if="v.status === 'scheduled'">Starts {{ shortDate(v.startsAt) }}</template>
                                    <template v-else>Until {{ shortDate(v.expiresAt) }}</template>
                                </span>
                                <div class="pvm-meter" :title="`${v.usedCount} of ${v.usageLimit} used`">
                                    <span :style="{ width: usagePercent(v) + '%' }" />
                                </div>
                                <span>{{ v.usedCount }}/{{ v.usageLimit }} used · {{ peso(v.budgetUsed) }} of {{ peso(v.budgetCap) }}</span>
                            </div>

                            <div class="pvm-menu-wrap">
                                <button
                                    type="button"
                                    class="pvm-icon-btn"
                                    :aria-label="`Actions for voucher ${v.code}`"
                                    :aria-expanded="menuOpenId === v.id"
                                    :disabled="busyId === v.id"
                                    @click.stop="menuOpenId = menuOpenId === v.id ? null : v.id"
                                >
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                                </button>
                                <div v-if="menuOpenId === v.id" class="pvm-menu" role="menu">
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

                    <nav v-if="lastPage > 1" class="pvm-pager" aria-label="Voucher pages">
                        <button type="button" class="pvm-btn" :disabled="page <= 1 || isLoading" aria-label="Previous page" @click="page--">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6" /></svg>
                        </button>
                        <span>Page {{ page }} of {{ lastPage }} · {{ counts[tab] }} vouchers</span>
                        <button type="button" class="pvm-btn" :disabled="page >= lastPage || isLoading" aria-label="Next page" @click="page++">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6" /></svg>
                        </button>
                    </nav>
                </div>
            </template>

            <!-- ======================= CREATE ======================= -->
            <template v-else>
                <ol class="pvm-steps" aria-label="Progress">
                    <li v-for="(s, i) in steps" :key="s.key" :class="{ done: i < stepIndex, current: i === stepIndex }">
                        <span>{{ i + 1 }}</span>{{ s.label }}
                    </li>
                </ol>

                <div class="pvm-body">
                    <!-- Type -->
                    <div v-if="step === 'type'" class="pvm-choices">
                        <button type="button" class="pvm-choice" :class="{ active: form.type === 'discount' }" @click="chooseType('discount')">
                            <strong>Discount</strong>
                            <small>Money off the item price, platform-wide or in selected categories.</small>
                        </button>
                        <button type="button" class="pvm-choice" :class="{ active: form.type === 'shipping' }" @click="chooseType('shipping')">
                            <strong>Shipping Fee</strong>
                            <small>Free shipping — the platform covers the delivery fee.</small>
                        </button>
                    </div>

                    <!-- Scope -->
                    <div v-else-if="step === 'scope'" class="pvm-choices">
                        <button type="button" class="pvm-choice" :class="{ active: form.scope === 'platform' }" @click="chooseScope('platform')">
                            <strong>Platform-wide</strong>
                            <small>Any eligible product from any shop, including sale items.</small>
                        </button>
                        <button type="button" class="pvm-choice" :class="{ active: form.scope === 'category' }" @click="chooseScope('category')">
                            <strong>Category</strong>
                            <small>Only products in the categories you pick.</small>
                        </button>
                    </div>

                    <!-- Categories -->
                    <div v-else-if="step === 'categories'">
                        <div class="pvm-picker-bar">
                            <span class="pvm-counter">{{ form.categories.length }} / {{ options.maxCategories }} selected</span>
                            <button v-if="allCategoriesSelected" type="button" class="pvm-btn" @click="form.categories = []">Clear</button>
                            <button v-else type="button" class="pvm-btn" :disabled="!options.categories.length" @click="selectAllCategories">Select All</button>
                        </div>
                        <div v-if="!options.categories.length" class="pvm-row pvm-skeleton" aria-busy="true" />
                        <div v-else class="pvm-chips" role="group" aria-label="Categories">
                            <button
                                v-for="c in options.categories"
                                :key="c"
                                type="button"
                                class="pvm-chip"
                                :class="{ active: form.categories.includes(c) }"
                                :aria-pressed="form.categories.includes(c)"
                                @click="toggleCategory(c)"
                            >
                                <svg v-if="form.categories.includes(c)" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 5 5 9-10" /></svg>
                                {{ c }}
                            </button>
                        </div>
                    </div>

                    <!-- Eligibility -->
                    <div v-else-if="step === 'eligibility'" class="pvm-stack">
                        <div class="pvm-choices">
                            <button
                                v-for="(meta, key) in ELIGIBILITY"
                                :key="key"
                                type="button"
                                class="pvm-choice"
                                :class="{ active: form.eligibility === key }"
                                @click="chooseEligibility(key)"
                            >
                                <strong>{{ meta.label }}</strong>
                                <small>{{ meta.hint }}</small>
                            </button>
                        </div>
                        <label v-if="form.eligibility === 'lapsed'" class="pvm-field narrow">
                            <span>No order in the last (days)</span>
                            <input :value="form.lapsed_days" type="text" inputmode="numeric" maxlength="3" @input="onNumber($event, form, 'lapsed_days')">
                        </label>
                        <div v-if="form.eligibility === 'region'">
                            <p class="pvm-hint">{{ selectedRegionCount }} region{{ selectedRegionCount === 1 ? '' : 's' }} selected — matched against the buyer's delivery address.</p>
                            <div v-if="!regions.length" class="pvm-row pvm-skeleton" aria-busy="true" />
                            <div v-else class="pvm-chips" role="group" aria-label="Regions">
                                <button
                                    v-for="r in regions"
                                    :key="r.code"
                                    type="button"
                                    class="pvm-chip"
                                    :class="{ active: regionSelected(r) }"
                                    :aria-pressed="regionSelected(r)"
                                    @click="toggleRegion(r)"
                                >
                                    {{ r.label }}
                                </button>
                            </div>
                        </div>
                        <p class="pvm-hint">Tier and custom segments are coming later.</p>
                    </div>

                    <!-- Rules -->
                    <div v-else-if="step === 'rules'" class="pvm-form">
                        <p v-if="isShipping" class="pvm-field full pvm-note">Free shipping — buyers pay ₱0 delivery on eligible orders. The platform covers the fee.</p>
                        <fieldset v-if="!isShipping" class="pvm-field full">
                            <legend>Discount</legend>
                            <div class="pvm-seg">
                                <button type="button" :class="{ active: isPct }" @click="form.discount_type = 'percentage'">Percentage (%)</button>
                                <button type="button" :class="{ active: !isPct }" @click="form.discount_type = 'fixed'; form.max_discount = ''">Fixed amount (₱)</button>
                            </div>
                        </fieldset>
                        <label v-if="!isShipping" class="pvm-field">
                            <span>{{ isPct ? 'Discount (%)' : 'Discount (₱)' }}</span>
                            <input :value="form.discount_value" type="text" inputmode="decimal" maxlength="10" :placeholder="isPct ? 'e.g. 10' : 'e.g. 50'" @input="onNumber($event, form, 'discount_value', isPct ? 0 : 2)">
                        </label>
                        <label v-if="isPct" class="pvm-field">
                            <span>Max discount cap (₱)</span>
                            <input :value="form.max_discount" type="text" inputmode="decimal" maxlength="10" placeholder="e.g. 100" @input="onNumber($event, form, 'max_discount', 2)">
                        </label>
                        <label class="pvm-field">
                            <span>Min. spend (₱)</span>
                            <input :value="form.min_spend" type="text" inputmode="decimal" maxlength="10" placeholder="0 for none" @input="onNumber($event, form, 'min_spend', 2)">
                        </label>
                        <label class="pvm-field">
                            <span>Start date</span>
                            <input v-model="form.starts_on" type="date" :min="todayIso()">
                        </label>
                        <label class="pvm-field">
                            <span>End date</span>
                            <input v-model="form.ends_on" type="date" :min="form.starts_on || todayIso()">
                        </label>
                        <label class="pvm-field">
                            <span>Total usage limit</span>
                            <input :value="form.usage_limit" type="text" inputmode="numeric" maxlength="8" @input="onNumber($event, form, 'usage_limit')">
                        </label>
                        <label class="pvm-field">
                            <span>Per-buyer limit</span>
                            <input :value="form.per_user_limit" type="text" inputmode="numeric" maxlength="4" @input="onNumber($event, form, 'per_user_limit')">
                        </label>
                        <label class="pvm-field">
                            <span>Budget cap (₱)</span>
                            <input :value="form.budget_cap" type="text" inputmode="decimal" maxlength="12" @input="onBudgetInput">
                            <small v-if="!budgetEdited && suggestedBudget">Auto: {{ form.usage_limit }} uses × {{ peso(perUseMax) }}{{ isShipping ? ' (costliest delivery)' : '' }}. Lower it to cap total spend.</small>
                            <button v-else-if="suggestedBudget" type="button" class="pvm-link sm" @click="resetBudget">Reset to {{ peso(suggestedBudget) }}</button>
                            <small>Expires once this much has been given away.</small>
                        </label>
                        <p v-if="stepError" class="pvm-field full pvm-inline-error">{{ stepError }}</p>
                    </div>

                    <!-- Distribution -->
                    <div v-else-if="step === 'distribution'" class="pvm-choices">
                        <button
                            v-for="(meta, key) in DISTRIBUTION"
                            :key="key"
                            type="button"
                            class="pvm-choice"
                            :class="{ active: form.distribution === key }"
                            @click="form.distribution = key"
                        >
                            <strong>{{ meta.label }}</strong>
                            <small>{{ meta.hint }}</small>
                        </button>
                    </div>

                    <!-- Stacking -->
                    <div v-else-if="step === 'stacking'" class="pvm-choices">
                        <button type="button" class="pvm-choice" :class="{ active: form.stackable }" @click="form.stackable = true">
                            <strong>Allow stacking</strong>
                            <small>Combines with a shop discount and a shipping voucher on the same order.</small>
                        </button>
                        <button type="button" class="pvm-choice" :class="{ active: !form.stackable }" @click="form.stackable = false">
                            <strong>Don't allow stacking</strong>
                            <small>Must be the only voucher on the order — no shop or shipping vouchers.</small>
                        </button>
                    </div>

                    <!-- Confirm -->
                    <div v-else class="pvm-confirm">
                        <div class="pvm-ticket">
                            <strong>{{ valueLabel }}</strong>
                            <span>Platform-funded</span>
                        </div>
                        <dl class="pvm-summary">
                            <div v-for="row in summaryRows" :key="row.label">
                                <dt>{{ row.label }}</dt>
                                <dd>{{ row.value }}</dd>
                            </div>
                        </dl>
                        <p class="pvm-confirm-copy">{{ confirmCopy }}</p>
                        <ul v-if="form.scope === 'category'" class="pvm-tags">
                            <li v-for="c in form.categories.slice(0, 5)" :key="c">{{ c }}</li>
                            <li v-if="form.categories.length > 5" class="more">and {{ form.categories.length - 5 }} more</li>
                        </ul>
                        <p class="pvm-hint">Vouchers can't be edited after creation (you can add stock once fully redeemed).</p>
                    </div>

                    <p v-if="formError && step !== 'rules'" class="pvm-error" role="alert">{{ formError }}</p>
                    <p v-else-if="formError && step === 'rules' && !stepError" class="pvm-error" role="alert">{{ formError }}</p>
                </div>

                <footer class="pvm-foot">
                    <button type="button" class="pvm-btn" @click="back">{{ stepIndex === 0 ? 'Cancel' : 'Back' }}</button>
                    <button v-if="step === 'confirm'" type="button" class="pvm-btn primary" :disabled="isSaving" @click="save()">
                        {{ isSaving ? 'Creating…' : 'Create Voucher' }}
                    </button>
                    <button v-else-if="step !== 'type' && step !== 'scope'" type="button" class="pvm-btn primary" :disabled="!!stepError" @click="next">Continue</button>
                </footer>
            </template>

            <!-- ======================= DIALOGS ======================= -->
            <div v-if="confirmState" class="pvm-dialog-backdrop" @click.self="confirmState = null">
                <div class="pvm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="pvm-confirm-title">
                    <h3 id="pvm-confirm-title">{{ confirmState.title }}</h3>
                    <p>{{ confirmState.message }}</p>
                    <p class="pvm-dialog-sub">{{ confirmState.voucher.label }} · {{ confirmState.voucher.code }}</p>
                    <div class="pvm-dialog-actions">
                        <button type="button" class="pvm-btn" @click="confirmState = null">Cancel</button>
                        <button type="button" class="pvm-btn" :class="confirmState.danger ? 'danger' : 'primary'" @click="confirmAction">{{ confirmState.confirm }}</button>
                    </div>
                </div>
            </div>

            <div v-if="stockDialog.voucher" class="pvm-dialog-backdrop" @click.self="stockDialog.voucher = null">
                <div class="pvm-dialog" role="dialog" aria-modal="true" aria-labelledby="pvm-stock-title">
                    <template v-if="!stockConfirming">
                        <h3 id="pvm-stock-title">Add stock</h3>
                        <p>{{ stockDialog.voucher.label }} has used all {{ stockDialog.voucher.usageLimit }} redemptions. Only the total usage limit can change.</p>
                        <label class="pvm-field">
                            <span>New total usage limit</span>
                            <input :value="stockDialog.value" type="text" inputmode="numeric" maxlength="8" @input="onNumber($event, stockDialog, 'value')" @keydown.enter="submitStock">
                        </label>
                        <p v-if="stockDialog.error" class="pvm-error">{{ stockDialog.error }}</p>
                        <div class="pvm-dialog-actions">
                            <button type="button" class="pvm-btn" @click="stockDialog.voucher = null">Cancel</button>
                            <button type="button" class="pvm-btn primary" @click="submitStock">Continue</button>
                        </div>
                    </template>
                    <template v-else>
                        <h3 id="pvm-stock-title">Add stock?</h3>
                        <p>Increasing the usage limit will reactivate this voucher. Continue?</p>
                        <p class="pvm-dialog-sub">{{ stockDialog.voucher.usageLimit }} → {{ stockDialog.value }} total uses</p>
                        <div class="pvm-dialog-actions">
                            <button type="button" class="pvm-btn" @click="stockConfirming = false">Back</button>
                            <button type="button" class="pvm-btn primary" :disabled="busyId === stockDialog.voucher.id" @click="confirmStock">Add stock</button>
                        </div>
                    </template>
                </div>
            </div>

            <div v-if="overlap" class="pvm-dialog-backdrop" @click.self="overlap = null">
                <div class="pvm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="pvm-overlap-title">
                    <h3 id="pvm-overlap-title">Overlapping category voucher</h3>
                    <p>These categories already have a platform voucher of this type during these dates. Buyers get only one — the best for them.</p>
                    <ul class="pvm-overlap-list">
                        <li v-for="c in overlap" :key="c.id">
                            <strong>{{ c.label }}</strong> <code class="pvm-code">{{ c.code }}</code>
                            <small>{{ c.categories.join(', ') }}</small>
                        </li>
                    </ul>
                    <div class="pvm-dialog-actions">
                        <button type="button" class="pvm-btn" @click="overlap = null">Go back</button>
                        <button type="button" class="pvm-btn danger" :disabled="isSaving" @click="save(true)">Create anyway</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.pvm-overlay {
    --ink: #0f172a; --ink-2: #334155; --muted: #64748b; --faint: #94a3b8;
    --line: #e2e8f0; --soft: #f8fafc; --brand: #0d9488; --brand-strong: #0f766e; --brand-bg: #f0fdfa;
    --warn: #b45309; --warn-bg: #fffbeb; --info: #0369a1; --info-bg: #f0f9ff; --danger: #dc2626; --danger-bg: #fef2f2;
    position: fixed; inset: 0; z-index: 70; display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(15, 23, 42, .45);
}
.pvm {
    position: relative; display: flex; flex-direction: column; width: min(900px, 100%); max-height: min(90vh, 920px);
    border-radius: 18px; background: #fff; color: var(--ink); box-shadow: 0 24px 60px rgba(15, 23, 42, .25); overflow: hidden;
}

.pvm-head { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--line); }
.pvm-head-icon { display: inline-flex; padding: 9px; border-radius: 11px; background: var(--brand-bg); color: var(--brand-strong); }
.pvm-head-text { flex: 1; min-width: 0; }
.pvm-head-text h2 { margin: 0; font-size: 17px; font-weight: 700; }
.pvm-head-text p { margin: 2px 0 0; font-size: 13px; color: var(--muted); }

.pvm-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 38px; padding: 0 14px;
    border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink); font-size: 13.5px; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.pvm-btn:hover:not(:disabled) { background: var(--soft); }
.pvm-btn:disabled { opacity: .5; cursor: not-allowed; }
.pvm-btn.primary { border-color: transparent; background: var(--brand); color: #fff; }
.pvm-btn.primary:hover:not(:disabled) { background: var(--brand-strong); }
.pvm-btn.danger { border-color: transparent; background: var(--danger); color: #fff; }
.pvm-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border: 0; border-radius: 10px; background: none; color: var(--muted); cursor: pointer; }
.pvm-icon-btn:hover:not(:disabled) { background: var(--soft); color: var(--ink); }
.pvm-btn:focus-visible, .pvm-icon-btn:focus-visible, .pvm-choice:focus-visible, .pvm-chip:focus-visible, .pvm-tab:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
.pvm-link { border: 0; background: none; color: var(--brand-strong); font-size: 13px; font-weight: 600; cursor: pointer; padding: 4px; }
.pvm-link.sm { align-self: flex-start; font-size: 12px; padding: 0; }

.pvm-notice { display: flex; align-items: center; gap: 10px; margin: 12px 20px 0; padding: 10px 14px; border-radius: 10px; font-size: 13.5px; font-weight: 600; }
.pvm-notice span { flex: 1; }
.pvm-notice button { display: inline-flex; border: 0; background: none; color: inherit; cursor: pointer; opacity: .7; }
.pvm-notice.success { background: var(--brand-bg); color: var(--brand-strong); }
.pvm-notice.error { background: var(--danger-bg); color: var(--danger); }

.pvm-spend { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 14px 20px 0; }
.pvm-spend div { padding: 10px 14px; border-radius: 12px; background: var(--soft); border: 1px solid var(--line); }
.pvm-spend dt { font-size: 11.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
.pvm-spend dd { margin: 3px 0 0; font-size: 18px; font-weight: 800; color: var(--ink); font-variant-numeric: tabular-nums; }
.pvm-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 20px 0; }
.pvm-tabs { display: inline-flex; padding: 3px; border-radius: 11px; background: #f1f5f9; }
.pvm-tab { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 0 14px; border: 0; border-radius: 9px; background: none; color: var(--muted); font-size: 13.5px; font-weight: 600; cursor: pointer; }
.pvm-tab.active { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(15, 23, 42, .1); }
.pvm-tab-count { padding: 0 7px; border-radius: 999px; background: var(--line); font-size: 11.5px; }
.pvm-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.pvm-filters select { min-height: 34px; padding: 0 10px; border: 1px solid var(--line); border-radius: 9px; background: #fff; color: var(--ink); font-size: 13px; }

.pvm-body { flex: 1; min-height: 0; overflow-y: auto; padding: 16px 20px 20px; }
.pvm-list { display: flex; flex-direction: column; gap: 10px; margin: 0; padding: 0; list-style: none; transition: opacity .15s ease; }
.pvm-list.loading { opacity: .55; pointer-events: none; }
.pvm-row { display: grid; grid-template-columns: auto 1fr auto auto; align-items: center; gap: 14px; padding: 14px; border: 1px solid var(--line); border-radius: 14px; background: #fff; }
.pvm-row:hover { border-color: #99f6e4; }
.pvm-row.busy { opacity: .6; }
.pvm-row.is-deactivated, .pvm-row.is-expired { background: var(--soft); }
.pvm-row-icon { display: inline-flex; padding: 9px; border-radius: 11px; background: var(--brand-bg); color: var(--brand-strong); }
.pvm-row-icon.shipping { background: var(--info-bg); color: var(--info); }
.pvm-row-main { min-width: 0; }
.pvm-row-top { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.pvm-row-label { font-size: 16px; font-weight: 800; }
.pvm-row-sub { margin: 3px 0 0; font-size: 13px; color: var(--ink-2); }
.pvm-row-faint { margin: 2px 0 0; font-size: 12px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pvm-row-stats { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; min-width: 170px; font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
.pvm-row-date { color: var(--ink-2); font-weight: 600; }
.pvm-meter { width: 100%; height: 6px; border-radius: 999px; background: #f1f5f9; overflow: hidden; }
.pvm-meter span { display: block; height: 100%; border-radius: inherit; background: var(--brand); }
.pvm-row.is-fully_redeemed .pvm-meter span { background: #f59e0b; }
.pvm-pill { padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.pvm-pill.good { background: var(--brand-bg); color: var(--brand-strong); }
.pvm-pill.info { background: var(--info-bg); color: var(--info); }
.pvm-pill.warn { background: var(--warn-bg); color: var(--warn); }
.pvm-pill.muted { background: #f1f5f9; color: var(--muted); }
.pvm-code { padding: 1px 7px; border-radius: 6px; background: #f1f5f9; color: var(--muted); font-size: 11.5px; letter-spacing: .04em; }

.pvm-menu-wrap { position: relative; }
.pvm-menu { position: absolute; right: 0; top: calc(100% + 4px); z-index: 5; display: flex; flex-direction: column; min-width: 220px; padding: 6px; border: 1px solid var(--line); border-radius: 12px; background: #fff; box-shadow: 0 12px 30px rgba(15, 23, 42, .15); }
.pvm-menu button { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 9px 11px; border: 0; border-radius: 8px; background: none; color: var(--ink); font-size: 13.5px; font-weight: 600; text-align: left; cursor: pointer; }
.pvm-menu button:hover:not(:disabled) { background: var(--soft); }
.pvm-menu button.danger { color: var(--danger); }
.pvm-menu button:disabled { color: var(--faint); cursor: not-allowed; }
.pvm-menu small { max-width: 230px; font-size: 11.5px; font-weight: 500; line-height: 1.35; color: var(--muted); }

.pvm-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 40px 16px; text-align: center; color: var(--muted); font-size: 14px; }
.pvm-empty p { margin: 0; max-width: 380px; }
.pvm-skeleton { height: 80px; border: 0; background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: pvm-shimmer 1.2s infinite; }
@keyframes pvm-shimmer { to { background-position: -200% 0; } }
.pvm-pager { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 16px; font-size: 13px; color: var(--muted); }
.pvm-pager .pvm-btn { width: 38px; padding: 0; }

.pvm-steps { display: flex; gap: 4px; margin: 0; padding: 14px 20px 0; list-style: none; overflow-x: auto; }
.pvm-steps li { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 4px; border-radius: 999px; font-size: 12px; font-weight: 600; color: var(--faint); white-space: nowrap; }
.pvm-steps li span { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: #f1f5f9; font-size: 11px; }
.pvm-steps li.current { background: var(--brand-bg); color: var(--brand-strong); }
.pvm-steps li.current span, .pvm-steps li.done span { background: var(--brand); color: #fff; }
.pvm-steps li.done { color: var(--ink-2); }

.pvm-stack { display: flex; flex-direction: column; gap: 16px; }
.pvm-choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.pvm-choice { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 16px; text-align: left; border: 1px solid var(--line); border-radius: 14px; background: #fff; color: var(--ink); cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease; }
.pvm-choice:hover { border-color: #5eead4; }
.pvm-choice.active { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(13, 148, 136, .15); }
.pvm-choice strong { font-size: 15px; }
.pvm-choice small { font-size: 13px; line-height: 1.45; color: var(--muted); }

.pvm-picker-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.pvm-counter { font-size: 13px; font-weight: 700; color: var(--brand-strong); font-variant-numeric: tabular-nums; }
.pvm-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.pvm-chip { display: inline-flex; align-items: center; gap: 5px; min-height: 38px; padding: 0 14px; border: 1px solid var(--line); border-radius: 999px; background: #fff; color: var(--ink-2); font-size: 13.5px; font-weight: 600; cursor: pointer; }
.pvm-chip.active { border-color: var(--brand); background: var(--brand-bg); color: var(--brand-strong); }

.pvm-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; }
.pvm-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; margin: 0; padding: 0; border: 0; }
.pvm-field.full { grid-column: 1 / -1; }
.pvm-field.narrow { max-width: 240px; }
.pvm-field > span, .pvm-field legend { padding: 0; font-size: 12.5px; font-weight: 600; color: var(--ink-2); }
.pvm-field small { font-size: 12px; color: var(--muted); }
.pvm-field input { min-height: 42px; padding: 0 12px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink); font-size: 14px; }
.pvm-field input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(13, 148, 136, .15); }
.pvm-seg { display: inline-flex; align-self: flex-start; padding: 3px; border-radius: 11px; background: #f1f5f9; }
.pvm-seg button { min-height: 34px; padding: 0 14px; border: 0; border-radius: 9px; background: none; color: var(--muted); font-size: 13.5px; font-weight: 600; cursor: pointer; }
.pvm-seg button.active { background: var(--brand); color: #fff; }
.pvm-note { padding: 10px 14px; border-radius: 10px; background: var(--info-bg); color: var(--info); font-size: 13.5px; font-weight: 600; }
.pvm-inline-error { margin: 0; color: var(--danger); font-size: 13px; font-weight: 600; }

.pvm-confirm { display: flex; flex-direction: column; gap: 14px; }
.pvm-ticket { display: flex; align-items: baseline; justify-content: space-between; padding: 14px 16px; border: 1px dashed #5eead4; border-radius: 14px; background: var(--brand-bg); }
.pvm-ticket strong { font-size: 22px; font-weight: 800; color: var(--brand-strong); }
.pvm-ticket span { font-size: 12px; font-weight: 700; color: var(--brand-strong); text-transform: uppercase; letter-spacing: .06em; }
.pvm-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 16px; margin: 0; }
.pvm-summary dt { font-size: 12px; color: var(--muted); }
.pvm-summary dd { margin: 2px 0 0; font-size: 13.5px; font-weight: 600; color: var(--ink-2); }
.pvm-confirm-copy { margin: 0; font-size: 14.5px; font-weight: 600; line-height: 1.5; }
.pvm-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }
.pvm-tags li { padding: 4px 11px; border-radius: 999px; background: #f1f5f9; font-size: 12.5px; }
.pvm-tags li.more { background: var(--brand-bg); color: var(--brand-strong); }

.pvm-hint { margin: 10px 0 0; font-size: 12.5px; color: var(--muted); }
.pvm-error { margin: 14px 0 0; padding: 9px 12px; border-radius: 10px; background: var(--danger-bg); color: var(--danger); font-size: 13px; font-weight: 600; }
.pvm-foot { display: flex; justify-content: space-between; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--line); }

.pvm-dialog-backdrop { position: absolute; inset: 0; z-index: 10; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, .35); }
.pvm-dialog { width: min(440px, 100%); padding: 20px; border-radius: 16px; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, .25); }
.pvm-dialog h3 { margin: 0 0 8px; font-size: 16px; }
.pvm-dialog p { margin: 0 0 10px; font-size: 14px; line-height: 1.5; color: var(--ink-2); }
.pvm-dialog .pvm-dialog-sub { font-size: 13px; color: var(--muted); }
.pvm-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
.pvm-overlap-list { display: flex; flex-direction: column; gap: 8px; max-height: 200px; margin: 0; padding: 0; overflow-y: auto; list-style: none; }
.pvm-overlap-list li { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 9px 11px; border-radius: 10px; background: var(--soft); font-size: 13.5px; }
.pvm-overlap-list small { flex-basis: 100%; color: var(--muted); }

@media (max-width: 680px) {
    .pvm-overlay { padding: 0; align-items: flex-end; }
    .pvm { max-height: 95vh; border-radius: 18px 18px 0 0; }
    .pvm-row { grid-template-columns: auto 1fr auto; }
    .pvm-row-stats { grid-column: 2 / 4; grid-row: 2; align-items: stretch; min-width: 0; }
    .pvm-form, .pvm-summary { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
    .pvm-skeleton { animation: none; }
}
</style>
