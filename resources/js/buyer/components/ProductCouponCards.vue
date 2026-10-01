<script setup>
import { computed, ref, watch } from 'vue';
import { couponExpiryLabel, useBuyerCoupons } from '../composables/useBuyerCoupons';
import { formatPrice } from '../composables/useCategoryMeta';
import { useToasts } from '../composables/useToasts';

const props = defineProps({
    productId: { type: String, required: true },
    // Current unit price (variant-aware) so "₱500 → ₱450" tracks the selection.
    price: { type: Number, required: true },
});

const { claimedCouponIds, loadWallet, claim, fetchProductCoupons } = useBuyerCoupons();
const { success, error: toastError } = useToasts();

const coupons = ref([]);
const isLoading = ref(false);
const claimingId = ref(null);

// Display only — mirrors ProductCoupon::discountFor(); checkout re-prices server-side.
function discountFor(c, unit) {
    let d = c.discountType === 'percentage' ? Math.round(unit * c.discountValue) / 100 : c.discountValue;
    if (c.discountType === 'percentage' && c.maxDiscount != null) {
        d = Math.min(d, c.maxDiscount);
    }

    return Math.max(0, Math.min(d, unit));
}

const cards = computed(() =>
    coupons.value.map((c) => {
        const discount = discountFor(c, props.price);

        return { ...c, after: props.price - discount, claimed: claimedCouponIds.value.has(c.id) };
    })
);

async function load(id) {
    isLoading.value = true;
    try {
        const [list] = await Promise.all([fetchProductCoupons(id), loadWallet()]);
        coupons.value = list;
    } catch {
        coupons.value = [];
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
        toastError(err?.status === 401 ? 'Sign in to claim coupons.' : err?.message || 'Could not claim this coupon.');
        if (err?.status === 422) {
            coupons.value = await fetchProductCoupons(props.productId, { force: true }).catch(() => coupons.value);
        }
    } finally {
        claimingId.value = null;
    }
}
</script>

<template>
    <section v-if="isLoading || cards.length" class="pcc" aria-label="Claimable coupons">
        <h2 class="pcc-title">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                <path d="M13 5v2M13 17v2M13 11v2" />
            </svg>
            Claimable coupons
        </h2>

        <div v-if="isLoading && !cards.length" class="pcc-list" aria-busy="true">
            <div v-for="n in 2" :key="n" class="pcc-card pcc-skeleton" />
        </div>

        <div v-else class="pcc-list">
            <article v-for="card in cards" :key="card.id" class="pcc-card" :class="{ claimed: card.claimed }">
                <div class="pcc-body">
                    <strong class="pcc-label">{{ card.label }}</strong>
                    <span class="pcc-price">
                        <s>{{ formatPrice(price) }}</s>
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                        <b>{{ formatPrice(card.after) }}</b>
                    </span>
                    <span class="pcc-meta">
                        Expires {{ couponExpiryLabel(card.expiresAt) }}
                        <template v-if="card.maxDiscount"> · max {{ formatPrice(card.maxDiscount) }}</template>
                    </span>
                    <span v-if="card.runningLow" class="pcc-low">Only {{ card.remaining }} left</span>
                </div>
                <button
                    type="button"
                    class="pcc-claim"
                    :disabled="card.claimed || claimingId === card.id"
                    @click="handleClaim(card)"
                >
                    <template v-if="card.claimed">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                        Claimed
                    </template>
                    <template v-else>{{ claimingId === card.id ? 'Claiming…' : 'Claim' }}</template>
                </button>
            </article>
        </div>
    </section>
</template>

<style scoped>
.pcc { margin-top: 14px; }
.pcc-title { display: flex; align-items: center; gap: 6px; margin: 0 0 10px; font-size: 12px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #dc2626; }
.pcc-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; }
.pcc-card {
    position: relative; display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 8px 10px 8px 14px; border-radius: 10px; gap: 8px; color: #fff;
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    box-shadow: 0 3px 10px rgba(220, 38, 38, .2);
}
/* Ticket notches */
.pcc-card::before, .pcc-card::after { content: ''; position: absolute; left: -5px; width: 10px; height: 10px; border-radius: 50%; background: var(--pcc-notch, #fff); top: calc(50% - 5px); }
.pcc-card::after { left: auto; right: -5px; }
.pcc-card.claimed { background: linear-gradient(135deg, #f87171, #ef4444); box-shadow: none; }
.pcc-body { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.pcc-label { font-size: 15px; font-weight: 800; line-height: 1.1; }
.pcc-price { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; }
.pcc-price s { opacity: .75; }
.pcc-price b { font-size: 13px; }
.pcc-meta { font-size: 11px; opacity: .9; }
.pcc-low { align-self: flex-start; margin-top: 2px; padding: 1px 6px; border-radius: 999px; background: #fef08a; color: #7f1d1d; font-size: 11px; font-weight: 800; }
.pcc-claim {
    flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; min-height: 32px; padding: 0 12px;
    border: 0; border-radius: 999px; background: #fff; color: #b91c1c; font-weight: 800; font-size: 12px; cursor: pointer;
    transition: transform .15s ease;
}
.pcc-claim:hover:not(:disabled) { transform: scale(1.04); }
.pcc-claim:disabled { cursor: default; opacity: .9; }
.pcc-claim:focus-visible { outline: 3px solid #fde68a; outline-offset: 2px; }
.pcc-skeleton { height: 60px; background: linear-gradient(90deg, #fee2e2 25%, #fecaca 50%, #fee2e2 75%); background-size: 200% 100%; animation: pcc-shimmer 1.2s infinite; box-shadow: none; }
@keyframes pcc-shimmer { to { background-position: -200% 0; } }
</style>
