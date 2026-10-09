<script setup>
import { computed, ref, watch } from 'vue';
import { voucherDateLabel, useBuyerVouchers } from '../composables/useBuyerVouchers';
import { formatPrice } from '../composables/useCategoryMeta';
import { fetchJson } from '../composables/useStores';
import { useToasts } from '../composables/useToasts';

const props = defineProps({
    storeId: { type: String, required: true },
    signedIn: { type: Boolean, default: false },
});

const { claimedVoucherIds, loadWallet, claim } = useBuyerVouchers();
const toasts = useToasts();
const vouchers = ref([]);
const loading = ref(true);
const error = ref('');
const page = ref(0);
const claimingId = ref(null);
const pageCount = computed(() => Math.ceil(vouchers.value.length / 4));
const visibleVouchers = computed(() => vouchers.value.slice(page.value * 4, page.value * 4 + 4));

async function load() {
    const id = props.storeId;
    loading.value = true;
    error.value = '';
    page.value = 0;

    try {
        const body = await fetchJson(`/api/stores/${encodeURIComponent(id)}/vouchers`);
        if (id !== props.storeId) return;
        vouchers.value = body.data || [];
        if (props.signedIn) loadWallet();
    } catch (err) {
        if (id !== props.storeId) return;
        vouchers.value = [];
        error.value = err?.message || 'Could not load shop vouchers.';
    } finally {
        if (id === props.storeId) loading.value = false;
    }
}

async function handleClaim(voucher) {
    if (claimingId.value || claimedVoucherIds.value.has(voucher.id)) return;
    if (!props.signedIn) {
        toasts.error('Sign in to claim shop vouchers.');
        return;
    }

    claimingId.value = voucher.id;
    try {
        await claim(voucher.id);
        toasts.success(`${voucher.label} claimed. It will apply when eligible at checkout.`);
    } catch (err) {
        toasts.error(err?.message || 'Could not claim this voucher.');
        if (err?.status === 422) load();
    } finally {
        claimingId.value = null;
    }
}

watch(() => props.storeId, load, { immediate: true });
watch(() => props.signedIn, signedIn => { if (signedIn) loadWallet(); });
</script>

<template>
    <section v-if="loading || error || vouchers.length" class="shop-vouchers" aria-label="Shop vouchers">
        <div v-if="loading" class="shop-voucher-grid" aria-busy="true">
            <div v-for="n in 4" :key="n" class="shop-voucher-skeleton" />
        </div>
        <div v-else-if="error" class="shop-voucher-error" role="alert">
            <span>{{ error }}</span>
            <button type="button" @click="load">Retry</button>
        </div>
        <div v-else class="shop-voucher-carousel">
            <button v-if="page > 0" type="button" class="shop-voucher-arrow is-prev" aria-label="Previous shop vouchers" @click="page--">‹</button>
            <div class="shop-voucher-grid">
                <article v-for="voucher in visibleVouchers" :key="voucher.id" class="shop-voucher" :class="{ 'is-shipping': voucher.type === 'shipping' }">
                    <div class="shop-voucher-copy">
                        <strong>{{ voucher.label }}</strong>
                        <span>{{ voucher.minSpend > 0 ? `Min. spend ${formatPrice(voucher.minSpend)}` : 'No minimum spend' }}</span>
                        <small v-if="voucher.discountType === 'percentage' && voucher.maxDiscount">Up to {{ formatPrice(voucher.maxDiscount) }} off</small>
                        <small v-if="voucher.remaining !== null && voucher.runningLow">Only {{ voucher.remaining }} left</small>
                        <small>Valid until {{ voucherDateLabel(voucher.expiresAt) }}</small>
                    </div>
                    <button type="button" class="shop-voucher-claim" :disabled="claimedVoucherIds.has(voucher.id) || claimingId === voucher.id" :aria-label="claimedVoucherIds.has(voucher.id) ? `${voucher.label} claimed` : `Claim ${voucher.label}`" @click="handleClaim(voucher)">
                        {{ claimedVoucherIds.has(voucher.id) ? 'Claimed' : claimingId === voucher.id ? 'Claiming…' : 'Claim' }}
                    </button>
                </article>
            </div>
            <button v-if="page < pageCount - 1" type="button" class="shop-voucher-arrow is-next" aria-label="Next shop vouchers" @click="page++">›</button>
        </div>
    </section>
</template>

<style scoped>
.shop-vouchers { margin: 18px 0 28px; padding: 18px; background: var(--nx-sunken, #f7f7f7); }
.shop-voucher-carousel { position: relative; }
.shop-voucher-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.shop-voucher { position: relative; display: flex; align-items: center; gap: 8px; min-width: 0; min-height: 104px; padding: 12px 10px 12px 15px; border: 1px solid #f5d7d9; border-radius: 3px; background: #fff5f5; box-shadow: 0 2px 5px rgb(127 29 29 / 5%); }
.shop-voucher::before { position: absolute; top: 0; bottom: 0; left: -1px; width: 7px; background: radial-gradient(circle at left center, var(--nx-sunken, #f7f7f7) 3px, transparent 3.5px) left top / 7px 10px repeat-y; content: ''; pointer-events: none; }
.shop-voucher.is-shipping { border-color: #c9e9e6; background: #f0faf9; }
.shop-voucher-copy { display: flex; flex: 1; flex-direction: column; gap: 2px; min-width: 0; }
.shop-voucher-copy strong { color: #be123c; font-size: 14px; line-height: 1.2; }
.shop-voucher.is-shipping .shop-voucher-copy strong { color: #0f766e; }
.shop-voucher-copy span, .shop-voucher-copy small { overflow: hidden; color: #6b4b4d; font-size: 11px; line-height: 1.2; text-overflow: ellipsis; white-space: nowrap; }
.shop-voucher-copy small:last-child { color: #8b7475; }
.shop-voucher-claim { flex: none; min-height: 36px; padding: 0 9px; border: 0; border-radius: 3px; background: #c91c36; color: white; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
.shop-voucher.is-shipping .shop-voucher-claim { background: #0f766e; }
.shop-voucher-claim:disabled { background: #e9d6d8; color: #795f61; cursor: default; }
.shop-voucher-arrow { position: absolute; z-index: 2; top: 50%; display: grid; place-items: center; width: 38px; height: 38px; padding: 0; border: 1px solid #eee; border-radius: 50%; background: white; box-shadow: 0 2px 10px rgb(0 0 0 / 14%); color: #333; font-size: 28px; line-height: 1; cursor: pointer; transform: translateY(-50%); }
.shop-voucher-arrow.is-prev { left: -18px; }
.shop-voucher-arrow.is-next { right: -18px; }
.shop-voucher-claim:focus-visible, .shop-voucher-arrow:focus-visible, .shop-voucher-error button:focus-visible { outline: 2px solid #be123c; outline-offset: 3px; }
.shop-voucher-error { display: flex; justify-content: space-between; gap: 12px; color: #9f1239; font-size: 13px; }
.shop-voucher-error button { border: 0; background: none; color: inherit; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }
.shop-voucher-skeleton { height: 104px; border-radius: 3px; background: #eee5e6; }
@media (max-width: 1100px) { .shop-voucher-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 580px) { .shop-vouchers { padding: 12px; } .shop-voucher-grid { grid-template-columns: minmax(0, 1fr); } .shop-voucher { min-height: 82px; } }
</style>
