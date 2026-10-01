<script setup>
/*
| MyCoupons.vue — the buyer's claimed product coupons, grouped by status.
| Same account-area shell as PaymentMethods.vue (shared Header/Footer).
*/
import { computed, onMounted } from 'vue';
import { couponExpiryLabel, useBuyerCoupons } from '../composables/useBuyerCoupons';
import Footer from './Footer.vue';
import Header from './Header.vue';
import AccountSidebar from './AccountSidebar.vue';

const emit = defineEmits([
    'back',
    'go-home',
    'search',
    'select-category',
    'open-cart',
    'view-profile',
    'view-payments',
    'open-product',
]);

const { wallet, isLoadingWallet, walletError, loadWallet } = useBuyerCoupons();

// Always refresh on open: expiry/usage can change while the page was away.
onMounted(() => loadWallet({ force: true }));

const groups = computed(() => {
    const by = { available: [], used: [], expired: [] };
    for (const entry of wallet.value) {
        (by[entry.status] || by.expired).push(entry);
    }
    by.available.sort((a, b) => a.expiresAt.localeCompare(b.expiresAt));

    return [
        { key: 'available', title: 'Available', items: by.available },
        { key: 'used', title: 'Used', items: by.used },
        { key: 'expired', title: 'Expired', items: by.expired },
    ];
});

function subline(entry) {
    if (entry.status === 'used') {
        return entry.usedAt ? `Used ${couponExpiryLabel(entry.usedAt)}` : 'Used';
    }

    return `${entry.status === 'expired' ? 'Expired' : 'Expires'} ${couponExpiryLabel(entry.expiresAt)}`;
}
</script>

<template>
    <div class="buyer-page">
        <Header
            active-category=""
            @select-category="(c) => emit('select-category', c)"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('go-home')"
            @search="(q) => emit('search', q)"
        />

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-10">
            <div class="flex flex-col lg:flex-row lg:items-start gap-8">
                <AccountSidebar active="coupons" />

                <div class="flex-1 min-w-0 space-y-8">
            <div class="flex flex-col gap-2">
                <nav class="flex items-center text-sm font-medium text-slate-400" aria-label="Breadcrumb">
                    <button type="button" class="hover:text-slate-600 transition-colors" @click="emit('view-profile')">Account</button>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" class="mx-1.5"><path d="m9 18 6-6-6-6" /></svg>
                    <span class="text-slate-900">My Coupons</span>
                </nav>
                <h1 class="text-3xl font-bold text-slate-900 tracking-tight">My Coupons</h1>
                <p class="text-slate-500">Coupons you've claimed. The best one applies automatically at checkout.</p>
            </div>

            <div v-if="isLoadingWallet && !wallet.length" class="space-y-3" aria-busy="true">
                <div v-for="n in 3" :key="n" class="mc-skeleton" />
            </div>

            <div v-else-if="walletError && !wallet.length" class="flex items-center justify-between gap-4 p-4 rounded-2xl bg-red-50 text-red-700 text-sm" role="alert">
                <span>{{ walletError }}</span>
                <button type="button" class="font-bold underline" @click="loadWallet({ force: true })">Retry</button>
            </div>

            <div v-else-if="!wallet.length" class="text-center py-16 px-6 bg-white rounded-3xl border border-slate-100">
                <div class="mx-auto mb-4 w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" /></svg>
                </div>
                <h2 class="font-bold text-slate-900">No coupons yet</h2>
                <p class="text-sm text-slate-500 mt-1">Look for red coupon cards on product pages and tap Claim.</p>
                <button type="button" class="mt-5 px-5 py-2.5 rounded-xl bg-[#0d9488] text-white text-sm font-bold" @click="emit('go-home')">Browse products</button>
            </div>

            <template v-else>
                <section v-for="group in groups" v-show="group.items.length" :key="group.key" class="space-y-3">
                    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                        {{ group.title }} <span class="text-slate-400">({{ group.items.length }})</span>
                    </h2>

                    <button
                        v-for="entry in group.items"
                        :key="entry.id"
                        type="button"
                        class="mc-row"
                        :class="`is-${entry.status}`"
                        @click="emit('open-product', entry.productId)"
                    >
                        <span class="mc-badge">{{ entry.label }}</span>
                        <span class="mc-info">
                            <strong>{{ entry.productName || 'Product unavailable' }}</strong>
                            <small>
                                {{ subline(entry) }}
                                <span v-if="entry.status === 'available' && entry.runningLow" class="mc-low">Only {{ entry.remaining }} left</span>
                            </small>
                        </span>
                        <span v-if="entry.status === 'available'" class="mc-use">Use</span>
                        <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><path d="m9 18 6-6-6-6" /></svg>
                    </button>
                </section>
            </template>
                </div>
            </div>
        </main>

        <Footer
            @browse-all="emit('go-home')"
            @browse-categories="emit('go-home')"
            @cart-click="emit('open-cart')"
        />
    </div>
</template>

<style scoped>
.mc-row {
    display: flex; align-items: center; gap: 14px; width: 100%; padding: 14px 16px; text-align: left;
    background: #fff; border: 1px solid #f1f5f9; border-radius: 16px; cursor: pointer;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.mc-row:hover { border-color: #fecaca; box-shadow: 0 4px 14px rgba(220, 38, 38, .08); }
.mc-row:focus-visible { outline: 3px solid #fca5a5; outline-offset: 2px; }
.mc-badge {
    flex-shrink: 0; min-width: 88px; padding: 10px 12px; border-radius: 12px; text-align: center;
    font-weight: 800; font-size: 14px; color: #fff; background: linear-gradient(135deg, #dc2626, #b91c1c);
}
.mc-row.is-used .mc-badge, .mc-row.is-expired .mc-badge { background: #e2e8f0; color: #64748b; }
.mc-row.is-used, .mc-row.is-expired { opacity: .8; }
.mc-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.mc-info strong { color: #0f172a; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-info small { color: #64748b; font-size: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.mc-low { padding: 1px 8px; border-radius: 999px; background: #fee2e2; color: #b91c1c; font-weight: 800; }
.mc-use { flex-shrink: 0; padding: 8px 16px; border-radius: 999px; background: #fef2f2; color: #dc2626; font-weight: 800; font-size: 13px; }
.mc-skeleton { height: 72px; border-radius: 16px; background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: mc-shimmer 1.2s infinite; }
@keyframes mc-shimmer { to { background-position: -200% 0; } }
</style>
