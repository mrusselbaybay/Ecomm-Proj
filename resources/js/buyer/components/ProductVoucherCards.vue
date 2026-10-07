<script setup>
/*
| Claimable seller vouchers on the product page. Light ticket cards: the
| value on a tinted stub, plain-language conditions, one clear action.
| Two best in a row; "View all" opens a side panel with Product Discounts /
| Free Shipping tabs.
*/
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { voucherDateLabel, useBuyerVouchers } from '../composables/useBuyerVouchers';
import { formatPrice } from '../composables/useCategoryMeta';
import { useToasts } from '../composables/useToasts';
import VoucherTicket from './VoucherTicket.vue';

const props = defineProps({
    productId: { type: String, required: true },
    // Current unit price (variant-aware) so "₱500 → ₱450" tracks the selection.
    price: { type: Number, required: true },
});

const PREVIEW_COUNT = 2;

const { claimedVoucherIds, loadWallet, claim, fetchProductVouchers } = useBuyerVouchers();
const { success, error: toastError } = useToasts();

const vouchers = ref([]);
const isLoading = ref(false);
const claimingId = ref(null);

// Display only — mirrors Voucher::discountOn() for one unit; checkout re-prices server-side.
function discountFor(c, unit) {
    let d = c.discountType === 'percentage' ? Math.round(unit * c.discountValue) / 100 : c.discountValue;

    if (c.discountType === 'percentage' && c.maxDiscount != null) {
        d = Math.min(d, c.maxDiscount);
    }

    return Math.max(0, Math.min(d, unit));
}

// "Ends today" / "Ends in 3 days" when close, otherwise the date.
function expiryText(iso) {
    const days = Math.ceil((new Date(iso) - Date.now()) / 86400000);

    if (days <= 1) {
        return 'Ends today';
    }

    return days <= 7 ? `Ends in ${days} days` : `Valid till ${voucherDateLabel(iso)}`;
}

const cards = computed(() =>
    vouchers.value.map((c) => {
        const isShipping = c.type === 'shipping';
        const conditions = [];

        if (c.minSpend > 0) {
            conditions.push(`Min. spend ${formatPrice(c.minSpend)}`);
        }

        if (c.discountType === 'percentage' && c.maxDiscount && !isShipping) {
            conditions.push(`Up to ${formatPrice(c.maxDiscount)} off`);
        }

        if (!c.stackable) {
            conditions.push("Can't combine");
        }

        // Price preview only when this one unit already qualifies.
        const preview = !isShipping && c.minSpend <= props.price;

        return {
            ...c,
            isShipping,
            headline: isShipping ? 'Free' : c.discountType === 'percentage' ? `${c.discountValue}%` : formatPrice(c.discountValue),
            subhead: isShipping ? 'Shipping' : 'OFF',
            scopeLabel: c.scope === 'product' ? 'This item' : 'Whole shop',
            conditions: conditions.join(' · ') || 'No minimum spend',
            after: preview ? props.price - discountFor(c, props.price) : null,
            expiry: expiryText(c.expiresAt),
            endingSoon: (new Date(c.expiresAt) - Date.now()) < 3 * 86400000,
            claimed: claimedVoucherIds.value.has(c.id),
        };
    })
);

// Claimed vouchers leave the product page (they're in the wallet); the
// "View all" panel still lists them, marked Claimed.
const previewCards = computed(() => cards.value.filter(c => !c.claimed).slice(0, PREVIEW_COUNT));

/* "View all" side panel: Product Discounts / Free Shipping tabs. */
const panelOpen = ref(false);
const panelTab = ref('discount');
const panelEl = ref(null);
let returnFocusEl = null;

const tabs = computed(() => [
    { key: 'discount', label: 'Product Discounts', items: cards.value.filter(c => !c.isShipping) },
    { key: 'shipping', label: 'Free Shipping', items: cards.value.filter(c => c.isShipping) },
]);

const panelItems = computed(() => tabs.value.find(t => t.key === panelTab.value)?.items || []);

function openPanel(event) {
    returnFocusEl = event?.currentTarget || null;
    // Start on a tab that has something in it.
    panelTab.value = tabs.value[0].items.length ? 'discount' : 'shipping';
    panelOpen.value = true;
    nextTick(() => panelEl.value?.focus());
}

function closePanel() {
    panelOpen.value = false;
    nextTick(() => returnFocusEl?.focus());
}

function onKeydown(e) {
    if (panelOpen.value && e.key === 'Escape') {
        closePanel();
    }
}

// Arrow keys move between tabs (WAI-ARIA tabs pattern).
function onTabKey(e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') {
        return;
    }

    panelTab.value = panelTab.value === 'discount' ? 'shipping' : 'discount';
    nextTick(() => document.getElementById(`pvc-tab-${panelTab.value}`)?.focus());
}

// Lock page scroll behind the panel.
watch(panelOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

async function load(id) {
    isLoading.value = true;
    panelOpen.value = false;

    try {
        const [list] = await Promise.all([fetchProductVouchers(id), loadWallet()]);
        vouchers.value = list;
    } catch {
        vouchers.value = [];
    } finally {
        isLoading.value = false;
    }
}

watch(() => props.productId, load, { immediate: true });

async function handleClaim(card) {
    claimingId.value = card.id;

    try {
        await claim(card.id);
        success(`${card.label} claimed — it'll apply automatically at checkout.`);
    } catch (err) {
        toastError(err?.status === 401 ? 'Sign in to claim vouchers.' : err?.message || 'Could not claim this voucher.');

        if (err?.status === 422) {
            vouchers.value = await fetchProductVouchers(props.productId, { force: true }).catch(() => vouchers.value);
        }
    } finally {
        claimingId.value = null;
    }
}
</script>

<template>
    <section v-if="isLoading || cards.length" class="pvc" aria-labelledby="pvc-title">
        <header class="pvc-head">
            <h2 id="pvc-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                    <path d="M13 5v2M13 17v2M13 11v2" />
                </svg>
                Shop vouchers
            </h2>
            <button
                v-if="cards.length > previewCards.length"
                type="button"
                class="pvc-view-all"
                aria-haspopup="dialog"
                @click="openPanel"
            >
                View all ({{ cards.length }})
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
            </button>
        </header>

        <div v-if="isLoading && !cards.length" class="pvc-row" aria-busy="true">
            <div v-for="n in 2" :key="n" class="pvc-skeleton" />
        </div>

        <p v-else-if="!previewCards.length" class="pvc-all-claimed">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
            You've claimed every voucher here — they'll apply at checkout.
        </p>

        <ul v-else class="pvc-row">
            <li v-for="card in previewCards" :key="card.id">
                <VoucherTicket :card="card" :price="price" :claiming="claimingId === card.id" @claim="handleClaim" />
            </li>
        </ul>

        <!-- View all: side panel -->
        <Teleport to="body">
            <Transition name="pvc-panel">
                <div v-if="panelOpen" class="pvc-backdrop" @click.self="closePanel">
                    <aside
                        ref="panelEl"
                        class="pvc-panel"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="pvc-panel-title"
                        tabindex="-1"
                    >
                        <header class="pvc-panel-head">
                            <h2 id="pvc-panel-title">Shop vouchers</h2>
                            <button type="button" class="pvc-close" aria-label="Close" @click="closePanel">
                                <svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5 5 15" /></svg>
                            </button>
                        </header>

                        <div class="pvc-tabs" role="tablist" aria-label="Voucher type" @keydown="onTabKey">
                            <button
                                v-for="t in tabs"
                                :id="`pvc-tab-${t.key}`"
                                :key="t.key"
                                type="button"
                                role="tab"
                                class="pvc-tab"
                                :class="{ active: panelTab === t.key }"
                                :aria-selected="panelTab === t.key"
                                :aria-controls="`pvc-tabpanel-${t.key}`"
                                :tabindex="panelTab === t.key ? 0 : -1"
                                @click="panelTab = t.key"
                            >
                                {{ t.label }}
                                <span class="pvc-tab-count">{{ t.items.length }}</span>
                            </button>
                        </div>

                        <div
                            :id="`pvc-tabpanel-${panelTab}`"
                            class="pvc-panel-body"
                            role="tabpanel"
                            :aria-labelledby="`pvc-tab-${panelTab}`"
                        >
                            <ul v-if="panelItems.length" class="pvc-panel-list">
                                <li v-for="card in panelItems" :key="card.id">
                                    <VoucherTicket :card="card" :price="price" :claiming="claimingId === card.id" @claim="handleClaim" />
                                </li>
                            </ul>
                            <p v-else class="pvc-empty">
                                No {{ panelTab === 'shipping' ? 'free shipping' : 'discount' }} vouchers for this product right now.
                            </p>
                        </div>

                        <p class="pvc-panel-foot">Claimed vouchers apply automatically at checkout — the best combination for you.</p>
                    </aside>
                </div>
            </Transition>
        </Teleport>
    </section>
</template>

<style scoped>
.pvc { margin-top: 16px; }
.pvc-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.pvc-head h2 { display: flex; align-items: center; gap: 6px; margin: 0; font-size: 15px; font-weight: 700; color: #0f172a; }
.pvc-head h2 svg { color: #dc2626; }
.pvc-view-all {
    display: inline-flex; align-items: center; gap: 2px; min-height: 36px; padding: 0 6px;
    border: 0; background: none; color: #0f766e; font-size: 13px; font-weight: 700; cursor: pointer;
}
.pvc-view-all:hover { text-decoration: underline; }

/* Two tickets side by side; stacks only on very narrow screens. */
.pvc-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0; padding: 0; list-style: none; }
.pvc-row > li { min-width: 0; }
.pvc-all-claimed { display: flex; align-items: center; gap: 6px; margin: 0; padding: 10px 12px; border-radius: 10px; background: #f0fdf4; color: #15803d; font-size: 13px; font-weight: 600; }
.pvc-skeleton { height: 128px; border-radius: 12px; background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: pvc-shimmer 1.2s infinite; }
@keyframes pvc-shimmer { to { background-position: -200% 0; } }

/* ---------- Side panel ---------- */
.pvc-backdrop { position: fixed; inset: 0; z-index: 80; display: flex; justify-content: flex-end; background: rgba(15, 23, 42, .45); }
.pvc-panel {
    display: flex; flex-direction: column; width: min(420px, 100%); height: 100%;
    background: #fff; box-shadow: -12px 0 40px rgba(15, 23, 42, .18); outline: none;
}
.pvc-panel-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 8px; }
.pvc-panel-head h2 { margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; }
.pvc-close { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border: 0; border-radius: 10px; background: none; color: #475569; cursor: pointer; }
.pvc-close:hover { background: #f1f5f9; color: #0f172a; }

.pvc-tabs { display: flex; gap: 4px; margin: 0 18px; border-bottom: 1px solid #e2e8f0; }
.pvc-tab {
    display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 10px; margin-bottom: -1px;
    border: 0; border-bottom: 2px solid transparent; background: none; color: #64748b; font-size: 14px; font-weight: 600; cursor: pointer;
}
.pvc-tab.active { color: #0f172a; border-bottom-color: #dc2626; }
.pvc-tab-count { min-width: 20px; padding: 0 6px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 12px; font-weight: 700; text-align: center; }
.pvc-tab.active .pvc-tab-count { background: #fee2e2; color: #b91c1c; }

.pvc-panel-body { flex: 1; overflow-y: auto; padding: 14px 18px; }
.pvc-panel-list { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
.pvc-empty { margin: 40px 0; text-align: center; font-size: 14px; color: #64748b; }
.pvc-panel-foot { margin: 0; padding: 12px 18px 16px; border-top: 1px solid #f1f5f9; font-size: 12.5px; color: #475569; }

.pvc-view-all:focus-visible, .pvc-close:focus-visible, .pvc-tab:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }

.pvc-panel-enter-active, .pvc-panel-leave-active { transition: opacity .2s ease; }
.pvc-panel-enter-active .pvc-panel, .pvc-panel-leave-active .pvc-panel { transition: transform .25s ease; }
.pvc-panel-enter-from, .pvc-panel-leave-to { opacity: 0; }
.pvc-panel-enter-from .pvc-panel, .pvc-panel-leave-to .pvc-panel { transform: translateX(100%); }

@media (max-width: 360px) {
    .pvc-row { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
    .pvc-skeleton { animation: none; }
    .pvc-panel-enter-active .pvc-panel, .pvc-panel-leave-active .pvc-panel { transition: none; }
}
</style>
