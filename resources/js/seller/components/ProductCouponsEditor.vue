<script setup>
/*
| Coupons section of the product edit page (Seller\ProductCouponController).
| Seller-funded, product-level: existing coupons as ticket cards (edit /
| delete in place) and one-or-many new coupon drafts saved in one request.
| Themed with the inventory page's dark tokens (--inv-*) + teal accents.
*/
import { computed, reactive, ref, watch } from 'vue';
import { apiRequest } from '../../shared/accountApi';
import { createClient } from '../../shared/backendClient';

const props = defineProps({
    productId: { type: String, required: true },
    price: { type: Number, default: 0 },
});

const EXPIRY_PRESETS = [7, 14, 30, 60, 90];
// No 0/O/1/I so codes read cleanly when buyers type or say them.
const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

const coupons = ref([]);
const isLoading = ref(false);
const loadError = ref('');
const drafts = ref([]);
const draftErrors = ref({});
const isSaving = ref(false);
const notice = ref(null);
const editingId = ref(null);
const editForm = reactive({});
const busyId = ref(null);
const confirmDeleteId = ref(null);

function call(path, options) {
    return apiRequest(createClient(), `/api/seller${path}`, options);
}

function generateCode() {
    const bytes = crypto.getRandomValues(new Uint8Array(8));

    return Array.from(bytes, b => CODE_ALPHABET[b % CODE_ALPHABET.length]).join('');
}

function dateIn(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

function blankDraft() {
    return {
        key: crypto.randomUUID(),
        code: generateCode(),
        discount_type: 'percentage',
        discount_value: '',
        max_discount: '',
        usage_limit: '',
        expiry: '30',
        expires_on: dateIn(30),
    };
}

// Expiry is a preset (days from today) or a picked date; either way end of that day.
function expiresAt(row) {
    const day = row.expiry === 'date' ? row.expires_on : dateIn(Number(row.expiry));

    return new Date(`${day}T23:59:59`).toISOString();
}

function payload(row) {
    const isPct = row.discount_type === 'percentage';

    return {
        code: row.code,
        discount_type: row.discount_type,
        discount_value: Number(row.discount_value),
        max_discount: isPct && row.max_discount !== '' && row.max_discount != null ? Number(row.max_discount) : null,
        usage_limit: row.usage_limit !== '' && row.usage_limit != null ? Number(row.usage_limit) : null,
        expires_at: expiresAt(row),
    };
}

// Display only — mirrors ProductCoupon::discountFor().
function discountOn(row) {
    const value = Number(row.discount_value);
    if (!value || !props.price) {
        return 0;
    }
    let d = row.discount_type === 'percentage' ? Math.round(props.price * value) / 100 : value;
    if (row.discount_type === 'percentage' && Number(row.max_discount) > 0) {
        d = Math.min(d, Number(row.max_discount));
    }

    return Math.min(d, props.price);
}

function peso(n) {
    return `₱${Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function longDate(iso) {
    return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function usagePercent(c) {
    return c.usageLimit ? Math.min(100, Math.round((c.usedCount / c.usageLimit) * 100)) : 0;
}

function draftValid(row) {
    const v = Number(row.discount_value);

    return v > 0 && (row.discount_type !== 'percentage' || v <= 100) && (row.expiry !== 'date' || row.expires_on);
}

function errorsByDraft(fieldErrors) {
    const out = {};
    for (const [field, messages] of Object.entries(fieldErrors || {})) {
        const m = field.match(/^coupons\.(\d+)\./);
        (out[m ? Number(m[1]) : 'general'] ||= []).push(...[].concat(messages));
    }

    return out;
}

async function load() {
    isLoading.value = true;
    loadError.value = '';
    const { data, error } = await call(`/products/${props.productId}/coupons`);
    isLoading.value = false;

    if (error) {
        loadError.value = error.message;

        return;
    }
    coupons.value = data || [];
}

watch(() => props.productId, () => {
    drafts.value = [];
    editingId.value = null;
    load();
}, { immediate: true });

const activeCount = computed(() => coupons.value.filter(c => c.status === 'active').length);
const canSave = computed(() => drafts.value.length > 0 && drafts.value.every(draftValid) && !isSaving.value);

function addDraft() {
    drafts.value.push(blankDraft());
}

function removeDraft(key) {
    drafts.value = drafts.value.filter(r => r.key !== key);
}

async function saveDrafts() {
    isSaving.value = true;
    draftErrors.value = {};
    notice.value = null;

    const { data, error } = await call(`/products/${props.productId}/coupons`, {
        method: 'POST',
        body: { coupons: drafts.value.map(payload) },
    });
    isSaving.value = false;

    if (error) {
        draftErrors.value = errorsByDraft(error.payload?.errors);
        notice.value = { tone: 'error', text: error.message || 'Could not save coupons.' };

        return;
    }

    coupons.value = [...data, ...coupons.value];
    notice.value = { tone: 'success', text: `${data.length} coupon${data.length > 1 ? 's' : ''} created and live on the product page.` };
    drafts.value = [];
}

function startEdit(c) {
    confirmDeleteId.value = null;
    editingId.value = c.id;
    Object.assign(editForm, {
        code: c.code,
        discount_type: c.discountType,
        discount_value: c.discountValue,
        max_discount: c.maxDiscount ?? '',
        usage_limit: c.usageLimit ?? '',
        expiry: 'date',
        expires_on: c.expiresAt.slice(0, 10),
        error: '',
    });
}

async function saveEdit(c) {
    busyId.value = c.id;
    const { data, error } = await call(`/coupons/${c.id}`, { method: 'PUT', body: payload(editForm) });
    busyId.value = null;

    if (error) {
        editForm.error = Object.values(error.payload?.errors || {}).flat()[0] || error.message;

        return;
    }

    coupons.value = coupons.value.map(x => (x.id === c.id ? data : x));
    editingId.value = null;
    notice.value = { tone: 'success', text: `${data.code} updated. Buyers who claimed it get the new terms.` };
}

async function remove(c) {
    busyId.value = c.id;
    const { error } = await call(`/coupons/${c.id}`, { method: 'DELETE' });
    busyId.value = null;
    confirmDeleteId.value = null;

    if (error) {
        notice.value = { tone: 'error', text: error.message };

        return;
    }

    coupons.value = coupons.value.filter(x => x.id !== c.id);
    notice.value = { tone: 'success', text: `${c.code} deleted. Past orders keep their discount.` };
}
</script>

<template>
    <section class="ps-section pce">
        <header class="pce-head">
            <div class="ps-icon-badge pce-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                    <path d="M13 5v2M13 17v2M13 11v2" />
                </svg>
            </div>
            <div class="pce-head-text">
                <h3>Coupons</h3>
                <p>Buyers claim these on the product page. The discount comes out of your payout.</p>
            </div>
            <span v-if="coupons.length" class="pce-count">{{ activeCount }} active</span>
        </header>

        <div class="pce-body">
            <Transition name="pce-fade">
                <div v-if="notice" class="pce-notice" :class="notice.tone" role="status">
                    <svg v-if="notice.tone === 'success'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                    <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" /></svg>
                    <span>{{ notice.text }}</span>
                    <button type="button" class="pce-notice-close" aria-label="Dismiss" @click="notice = null">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>
            </Transition>

            <!-- ===================== Existing coupons ===================== -->
            <div v-if="isLoading" class="pce-grid" aria-busy="true">
                <div v-for="n in 2" :key="n" class="pce-skeleton" />
            </div>

            <div v-else-if="loadError" class="pce-notice error">
                <span>{{ loadError }}</span>
                <button type="button" class="pce-btn-text" @click="load">Retry</button>
            </div>

            <div v-else-if="coupons.length" class="pce-grid">
                <article
                    v-for="c in coupons"
                    :key="c.id"
                    class="pce-ticket"
                    :class="{ 'is-expired': c.status !== 'active', 'is-editing': editingId === c.id }"
                >
                    <!-- View -->
                    <template v-if="editingId !== c.id">
                        <div class="pce-ticket-top">
                            <div>
                                <p class="pce-ticket-value">{{ c.label }}</p>
                                <p v-if="c.maxDiscount" class="pce-ticket-sub">Up to {{ peso(c.maxDiscount) }}</p>
                            </div>
                            <span class="pce-pill" :class="c.status">{{ c.status === 'active' ? 'Active' : 'Expired' }}</span>
                        </div>

                        <code class="pce-code">{{ c.code }}</code>

                        <dl class="pce-facts">
                            <div>
                                <dt>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
                                    {{ c.status === 'active' ? 'Expires' : 'Expired' }}
                                </dt>
                                <dd>{{ longDate(c.expiresAt) }}</dd>
                            </div>
                            <div>
                                <dt>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
                                    Redeemed
                                </dt>
                                <dd>{{ c.usedCount }}<span v-if="c.usageLimit"> / {{ c.usageLimit }}</span><span v-else class="pce-muted"> · no limit</span></dd>
                            </div>
                        </dl>

                        <div v-if="c.usageLimit" class="pce-meter" role="progressbar" :aria-valuenow="usagePercent(c)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${c.usedCount} of ${c.usageLimit} redeemed`">
                            <span :style="{ width: `${usagePercent(c)}%` }" :class="{ low: c.runningLow }" />
                        </div>

                        <div class="pce-ticket-actions">
                            <template v-if="confirmDeleteId === c.id">
                                <span class="pce-confirm-text">Delete {{ c.code }}?</span>
                                <button type="button" class="pce-btn-ghost" @click="confirmDeleteId = null">Keep</button>
                                <button type="button" class="pce-btn-danger" :disabled="busyId === c.id" @click="remove(c)">
                                    {{ busyId === c.id ? 'Deleting…' : 'Delete' }}
                                </button>
                            </template>
                            <template v-else>
                                <button type="button" class="pce-btn-ghost" @click="startEdit(c)">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
                                    Edit
                                </button>
                                <button type="button" class="pce-btn-ghost danger" @click="confirmDeleteId = c.id">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
                                    Delete
                                </button>
                            </template>
                        </div>
                    </template>

                    <!-- Edit -->
                    <form v-else class="pce-form" @submit.prevent="saveEdit(c)">
                        <div class="pce-form-head">
                            <strong>Edit coupon</strong>
                            <code class="pce-code sm">{{ editForm.code }}</code>
                        </div>

                        <div class="pce-field">
                            <span class="pce-label">Discount type</span>
                            <div class="pce-seg" role="radiogroup" aria-label="Discount type">
                                <button type="button" role="radio" :aria-checked="editForm.discount_type === 'percentage'" :class="{ on: editForm.discount_type === 'percentage' }" @click="editForm.discount_type = 'percentage'">Percentage</button>
                                <button type="button" role="radio" :aria-checked="editForm.discount_type === 'fixed'" :class="{ on: editForm.discount_type === 'fixed' }" @click="editForm.discount_type = 'fixed'">Fixed amount</button>
                            </div>
                        </div>

                        <div class="pce-row2">
                            <label class="pce-field">
                                <span class="pce-label">Discount value</span>
                                <span class="pce-affix">
                                    <span v-if="editForm.discount_type === 'fixed'" class="pce-affix-pre">₱</span>
                                    <input v-model="editForm.discount_value" type="number" min="0" step="0.01" class="pce-input" required>
                                    <span v-if="editForm.discount_type === 'percentage'" class="pce-affix-post">%</span>
                                </span>
                            </label>
                            <label v-if="editForm.discount_type === 'percentage'" class="pce-field">
                                <span class="pce-label">Maximum discount <em>optional</em></span>
                                <span class="pce-affix">
                                    <span class="pce-affix-pre">₱</span>
                                    <input v-model="editForm.max_discount" type="number" min="0" step="0.01" class="pce-input" placeholder="No maximum">
                                </span>
                            </label>
                        </div>

                        <div class="pce-row2">
                            <label class="pce-field">
                                <span class="pce-label">Expires on</span>
                                <input v-model="editForm.expires_on" type="date" :min="dateIn(0)" class="pce-input" required>
                            </label>
                            <label class="pce-field">
                                <span class="pce-label">Usage limit <em>optional</em></span>
                                <input v-model="editForm.usage_limit" type="number" min="1" step="1" class="pce-input" placeholder="Unlimited">
                            </label>
                        </div>

                        <p v-if="editForm.error" class="pce-error" role="alert">{{ editForm.error }}</p>

                        <div class="pce-ticket-actions">
                            <button type="button" class="pce-btn-ghost" @click="editingId = null">Cancel</button>
                            <button type="submit" class="btn-primary" :disabled="busyId === c.id">{{ busyId === c.id ? 'Saving…' : 'Save changes' }}</button>
                        </div>
                    </form>
                </article>
            </div>

            <div v-else-if="!drafts.length" class="pce-empty">
                <div class="pce-empty-icon" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" /></svg>
                </div>
                <p class="pce-empty-title">No coupons for this product yet</p>
                <p class="pce-empty-text">A coupon shows as a red card on your product page and can nudge undecided buyers to check out.</p>
                <button type="button" class="btn-primary" @click="addDraft">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    Create a coupon
                </button>
            </div>

            <!-- ===================== New coupon drafts ===================== -->
            <div v-if="drafts.length" class="pce-drafts">
                <p class="pce-section-label">New coupons</p>

                <article v-for="(row, i) in drafts" :key="row.key" class="pce-draft">
                    <div class="pce-draft-head">
                        <span class="pce-draft-num">{{ i + 1 }}</span>
                        <code class="pce-code sm" :title="'Auto-generated code'">{{ row.code }}</code>
                        <button type="button" class="pce-icon-btn" aria-label="Generate a different code" title="Generate a different code" @click="row.code = generateCode()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8" /><path d="M21 3v5h-5" /></svg>
                        </button>
                        <span v-if="discountOn(row)" class="pce-preview">
                            {{ peso(price) }}
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                            <strong>{{ peso(price - discountOn(row)) }}</strong>
                        </span>
                        <button type="button" class="pce-icon-btn pce-draft-remove" :aria-label="`Remove new coupon ${i + 1}`" @click="removeDraft(row.key)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div class="pce-draft-grid">
                        <fieldset class="pce-group">
                            <legend>Discount</legend>
                            <div class="pce-seg" role="radiogroup" aria-label="Discount type">
                                <button type="button" role="radio" :aria-checked="row.discount_type === 'percentage'" :class="{ on: row.discount_type === 'percentage' }" @click="row.discount_type = 'percentage'">Percentage</button>
                                <button type="button" role="radio" :aria-checked="row.discount_type === 'fixed'" :class="{ on: row.discount_type === 'fixed' }" @click="row.discount_type = 'fixed'">Fixed amount</button>
                            </div>
                            <div class="pce-row2">
                                <label class="pce-field">
                                    <span class="pce-label">Value <span class="pce-req" aria-hidden="true">*</span></span>
                                    <span class="pce-affix">
                                        <span v-if="row.discount_type === 'fixed'" class="pce-affix-pre">₱</span>
                                        <input v-model="row.discount_value" type="number" min="0" :max="row.discount_type === 'percentage' ? 100 : undefined" step="0.01" class="pce-input" :placeholder="row.discount_type === 'percentage' ? '10' : '50'" required>
                                        <span v-if="row.discount_type === 'percentage'" class="pce-affix-post">%</span>
                                    </span>
                                </label>
                                <label v-if="row.discount_type === 'percentage'" class="pce-field">
                                    <span class="pce-label">Maximum discount <em>optional</em></span>
                                    <span class="pce-affix">
                                        <span class="pce-affix-pre">₱</span>
                                        <input v-model="row.max_discount" type="number" min="0" step="0.01" class="pce-input" placeholder="No maximum">
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <fieldset class="pce-group">
                            <legend>Validity</legend>
                            <div class="pce-chips" role="radiogroup" aria-label="Expires in">
                                <button
                                    v-for="d in EXPIRY_PRESETS"
                                    :key="d"
                                    type="button"
                                    role="radio"
                                    :aria-checked="row.expiry === String(d)"
                                    :class="{ on: row.expiry === String(d) }"
                                    @click="row.expiry = String(d)"
                                >{{ d }} days</button>
                                <button
                                    type="button"
                                    role="radio"
                                    class="pce-chip-plus"
                                    aria-label="Pick a custom expiry date"
                                    title="Pick a custom expiry date"
                                    :aria-checked="row.expiry === 'date'"
                                    :class="{ on: row.expiry === 'date' }"
                                    @click="row.expiry = 'date'"
                                >
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                                </button>
                            </div>
                            <div class="pce-row2">
                                <label v-if="row.expiry === 'date'" class="pce-field">
                                    <span class="pce-label">Expiry date</span>
                                    <input v-model="row.expires_on" type="date" :min="dateIn(1)" class="pce-input">
                                </label>
                                <p v-else class="pce-hint">Ends {{ longDate(expiresAt(row)) }}</p>
                                <label class="pce-field">
                                    <span class="pce-label">Usage limit <em>optional</em></span>
                                    <input v-model="row.usage_limit" type="number" min="1" step="1" class="pce-input" placeholder="Unlimited">
                                </label>
                            </div>
                        </fieldset>
                    </div>

                    <p v-for="msg in draftErrors[i] || []" :key="msg" class="pce-error" role="alert">{{ msg }}</p>
                </article>
            </div>

            <footer v-if="drafts.length || coupons.length" class="pce-footer">
                <button type="button" class="pce-btn-add" @click="addDraft">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    {{ drafts.length ? 'Add another coupon' : 'Add coupon' }}
                </button>
                <button v-if="drafts.length" type="button" class="btn-primary" :disabled="!canSave" @click="saveDrafts">
                    {{ isSaving ? 'Saving…' : `Save ${drafts.length} coupon${drafts.length > 1 ? 's' : ''}` }}
                </button>
            </footer>
        </div>
    </section>
</template>

<style scoped>
/* Inherits the inventory page's dark tokens; fallbacks match them. */
.pce {
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
    --c-danger: #f87171;
    --c-danger-bg: rgba(248, 113, 113, 0.12);
    color: var(--c-ink);
}

/* ---------- Header ---------- */
.pce-head { display: flex; align-items: center; gap: 0.85rem; margin-bottom: 1rem; }
.pce-icon { background: var(--c-teal-bg); color: var(--c-teal); }
.pce-head-text { flex: 1; min-width: 0; }
.pce-head-text h3 { margin: 0; font-size: 1rem; font-weight: 700; color: var(--c-ink); }
.pce-head-text p { margin: 0.2rem 0 0; font-size: 0.85rem; line-height: 1.5; color: var(--c-muted); }
.pce-count { padding: 0.3rem 0.75rem; border-radius: 999px; background: var(--c-teal-bg); color: var(--c-teal); font-size: 0.8rem; font-weight: 700; white-space: nowrap; }

.pce-body {
    display: flex; flex-direction: column; gap: 1.25rem;
    padding: 1.5rem; border-radius: 1.25rem;
    background: var(--c-surface); border: 1px solid var(--c-border);
}

/* ---------- Notice ---------- */
.pce-notice { display: flex; align-items: center; gap: 0.6rem; padding: 0.75rem 1rem; border-radius: 0.85rem; font-size: 0.875rem; font-weight: 600; }
.pce-notice span { flex: 1; }
.pce-notice.success { background: var(--c-teal-bg); color: var(--c-teal); border: 1px solid var(--c-teal-border); }
.pce-notice.error { background: var(--c-danger-bg); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.35); }
.pce-notice-close { display: inline-flex; padding: 0.35rem; border: 0; border-radius: 0.4rem; background: none; color: inherit; opacity: 0.7; cursor: pointer; }
.pce-notice-close:hover { opacity: 1; }
.pce-fade-enter-active, .pce-fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.pce-fade-enter-from, .pce-fade-leave-to { opacity: 0; transform: translateY(-4px); }

/* ---------- Existing coupons: ticket grid ---------- */
.pce-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.pce-ticket {
    position: relative; display: flex; flex-direction: column; gap: 0.9rem;
    padding: 1.25rem; border-radius: 1rem;
    background: var(--c-surface-2); border: 1px solid var(--c-border);
    border-left: 4px solid var(--c-teal-strong);
    transition: border-color 0.15s ease;
}
.pce-ticket:hover { border-color: var(--c-teal-border); border-left-color: var(--c-teal-strong); }
.pce-ticket.is-expired { border-left-color: var(--c-faint); }
.pce-ticket.is-expired .pce-ticket-value { color: var(--c-muted); }
.pce-ticket.is-editing { grid-column: 1 / -1; border-left-color: var(--c-teal); }
.pce-ticket-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
.pce-ticket-value { margin: 0; font-size: 1.5rem; font-weight: 800; line-height: 1.1; color: var(--c-teal); letter-spacing: -0.01em; }
.pce-ticket-sub { margin: 0.3rem 0 0; font-size: 0.8rem; color: var(--c-muted); }

.pce-pill { padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; }
.pce-pill.active { background: var(--c-teal-bg); color: var(--c-teal); }
.pce-pill.expired { background: rgba(255, 255, 255, 0.06); color: var(--c-muted); }

.pce-code {
    align-self: flex-start; padding: 0.4rem 0.75rem; border-radius: 0.5rem;
    background: rgba(255, 255, 255, 0.05); border: 1px dashed var(--c-border);
    color: var(--c-ink); font: 700 0.9rem/1 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: 0.08em;
}
.pce-code.sm { font-size: 0.8rem; padding: 0.35rem 0.6rem; }

.pce-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin: 0; }
.pce-facts dt { display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; color: var(--c-muted); }
.pce-facts dd { margin: 0.2rem 0 0; font-size: 0.9rem; font-weight: 600; color: var(--c-ink-2); font-variant-numeric: tabular-nums; }
.pce-muted { color: var(--c-faint); font-weight: 500; }

.pce-meter { height: 6px; border-radius: 999px; background: rgba(255, 255, 255, 0.06); overflow: hidden; }
.pce-meter span { display: block; height: 100%; border-radius: inherit; background: var(--c-teal-strong); transition: width 0.3s ease; }
.pce-meter span.low { background: #f59e0b; }

.pce-ticket-actions { display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem; margin-top: auto; padding-top: 0.25rem; border-top: 1px solid var(--c-border-soft); padding-top: 0.85rem; }
.pce-confirm-text { margin-right: auto; font-size: 0.85rem; font-weight: 600; color: #fca5a5; }

/* ---------- Buttons ---------- */
.pce-btn-ghost, .pce-btn-danger, .pce-btn-add, .pce-btn-text {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
    min-height: 40px; padding: 0 1rem; border-radius: 0.65rem;
    font-size: 0.85rem; font-weight: 700; cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.pce-btn-ghost { border: 1px solid var(--c-border); background: transparent; color: var(--c-ink-2); }
.pce-btn-ghost:hover { background: rgba(255, 255, 255, 0.05); color: var(--c-ink); }
.pce-btn-ghost.danger:hover { background: var(--c-danger-bg); border-color: rgba(248, 113, 113, 0.35); color: var(--c-danger); }
.pce-btn-danger { border: 0; background: #dc2626; color: #fff; }
.pce-btn-danger:hover:not(:disabled) { background: #b91c1c; }
.pce-btn-add { border: 1px dashed var(--c-teal-border); background: transparent; color: var(--c-teal); }
.pce-btn-add:hover { background: var(--c-teal-bg); }
.pce-btn-text { min-height: 32px; padding: 0 0.5rem; border: 0; background: none; color: inherit; text-decoration: underline; }
.pce-icon-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: 0; border-radius: 0.6rem;
    background: transparent; color: var(--c-muted); cursor: pointer; transition: background 0.15s ease, color 0.15s ease;
}
.pce-icon-btn:hover { background: rgba(255, 255, 255, 0.06); color: var(--c-ink); }
button:disabled { opacity: 0.5; cursor: not-allowed; }
.pce button:focus-visible, .pce input:focus-visible { outline: 2px solid var(--c-teal); outline-offset: 2px; }

/* ---------- Forms ---------- */
.pce-form { display: flex; flex-direction: column; gap: 1.1rem; }
.pce-form-head { display: flex; align-items: center; gap: 0.75rem; }
.pce-form-head strong { font-size: 0.95rem; }
.pce-field { display: flex; flex-direction: column; gap: 0.4rem; min-width: 0; }
.pce-label { font-size: 0.8rem; font-weight: 600; color: var(--c-ink-2); }
.pce-label em { margin-left: 0.25rem; font-style: normal; font-weight: 500; color: var(--c-faint); }
.pce-req { color: var(--c-danger); }
.pce-row2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: end; }
.pce-input {
    width: 100%; box-sizing: border-box; min-height: 44px; padding: 0 0.85rem;
    border-radius: 0.65rem; border: 1px solid var(--c-border);
    background: var(--c-surface); color: var(--c-ink); font-size: 0.95rem;
    color-scheme: dark; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pce-input::placeholder { color: var(--c-faint); }
.pce-input:focus { outline: none; border-color: var(--c-teal-strong); box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.2); }
.pce-affix { position: relative; display: flex; align-items: center; }
.pce-affix .pce-input { flex: 1; }
.pce-affix-pre, .pce-affix-post { position: absolute; color: var(--c-muted); font-weight: 700; font-size: 0.9rem; pointer-events: none; }
.pce-affix-pre { left: 0.85rem; }
.pce-affix-pre + .pce-input { padding-left: 1.85rem; }
.pce-affix-post { right: 0.85rem; }
.pce-affix .pce-input:has(+ .pce-affix-post) { padding-right: 2rem; }

.pce-seg { display: inline-flex; padding: 4px; border-radius: 0.75rem; background: var(--c-surface); border: 1px solid var(--c-border); align-self: flex-start; }
.pce-seg button { min-height: 36px; padding: 0 1rem; border: 0; border-radius: 0.55rem; background: transparent; color: var(--c-muted); font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: background 0.15s ease, color 0.15s ease; }
.pce-seg button.on { background: var(--c-teal-bg); color: var(--c-teal); box-shadow: inset 0 0 0 1px var(--c-teal-border); }

.pce-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.pce-chips button { min-height: 36px; padding: 0 0.9rem; border-radius: 999px; border: 1px solid var(--c-border); background: transparent; color: var(--c-ink-2); font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.15s ease; }
.pce-chips button:hover { border-color: var(--c-teal-border); }
.pce-chips .pce-chip-plus { width: 36px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
.pce-chips button.on { background: var(--c-teal-bg); border-color: var(--c-teal-border); color: var(--c-teal); }

.pce-hint { margin: 0; align-self: center; font-size: 0.85rem; color: var(--c-muted); }
.pce-error { margin: 0; font-size: 0.85rem; color: #fca5a5; }

/* ---------- Drafts ---------- */
.pce-drafts { display: flex; flex-direction: column; gap: 1rem; }
.pce-section-label { margin: 0; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--c-muted); }
.pce-draft {
    display: flex; flex-direction: column; gap: 1.25rem;
    padding: 1.25rem; border-radius: 1rem;
    background: var(--c-surface-2); border: 1px dashed var(--c-teal-border);
}
.pce-draft-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; }
.pce-draft-num { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: var(--c-teal-bg); color: var(--c-teal); font-size: 0.8rem; font-weight: 800; }
.pce-preview { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.75rem; border-radius: 999px; background: rgba(255, 255, 255, 0.05); color: var(--c-muted); font-size: 0.85rem; font-variant-numeric: tabular-nums; }
.pce-preview strong { color: var(--c-teal); }
.pce-draft-remove { margin-left: auto; }
.pce-draft-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.25rem; }
.pce-group { display: flex; flex-direction: column; gap: 0.9rem; margin: 0; padding: 1rem; border: 1px solid var(--c-border-soft); border-radius: 0.85rem; min-width: 0; }
.pce-group legend { padding: 0 0.4rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--c-muted); }

/* ---------- Footer / empty / loading ---------- */
.pce-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding-top: 1.25rem; border-top: 1px solid var(--c-border-soft); }
.pce-empty { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.5rem; padding: 2rem 1rem; }
.pce-empty-icon { display: flex; align-items: center; justify-content: center; width: 56px; height: 56px; margin-bottom: 0.25rem; border-radius: 1rem; background: var(--c-teal-bg); color: var(--c-teal); }
.pce-empty-title { margin: 0; font-size: 1rem; font-weight: 700; color: var(--c-ink); }
.pce-empty-text { margin: 0 0 0.75rem; max-width: 42ch; font-size: 0.875rem; line-height: 1.55; color: var(--c-muted); }
.pce-skeleton { height: 190px; border-radius: 1rem; background: linear-gradient(90deg, var(--c-surface-2) 25%, rgba(255, 255, 255, 0.06) 50%, var(--c-surface-2) 75%); background-size: 200% 100%; animation: pce-shimmer 1.2s infinite; }
@keyframes pce-shimmer { to { background-position: -200% 0; } }

@media (max-width: 640px) {
    .pce-body { padding: 1rem; }
    .pce-draft-grid { grid-template-columns: 1fr; }
    .pce-footer > * { flex: 1; }
}
@media (prefers-reduced-motion: reduce) {
    .pce *, .pce-fade-enter-active, .pce-fade-leave-active { transition: none !important; animation: none !important; }
}
</style>
