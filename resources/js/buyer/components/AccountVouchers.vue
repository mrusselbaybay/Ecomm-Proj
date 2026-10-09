<script setup>
import { computed, nextTick, onActivated, ref } from 'vue';
import { requestBuyerView } from '../composables/useBuyerNav';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { useBuyerVouchers, voucherDateLabel } from '../composables/useBuyerVouchers';
import { useToasts } from '../composables/useToasts';

const { wallet, isLoadingWallet, walletError, loadWallet } = useBuyerVouchers();
const { getProductById } = useBuyerProducts();
const { error: showError } = useToasts();
const tabs = [
    { id: 'all', label: 'All' },
    { id: 'available', label: 'Available' },
    { id: 'used', label: 'Used' },
    { id: 'expired', label: 'Expired' },
];
const selectedTab = ref('all');
const tabButtons = ref([]);
const usingId = ref(null);
const counts = computed(() => {
    const result = { all: wallet.value.length, available: 0, used: 0, expired: 0 };

    for (const entry of wallet.value) {
        if (entry.status in result) {
            result[entry.status] += 1;
        }
    }

    return result;
});
const visibleVouchers = computed(() => selectedTab.value === 'all'
    ? wallet.value
    : wallet.value.filter(entry => entry.status === selectedTab.value));
const emptyMessages = {
    all: { title: 'No vouchers yet', detail: 'Vouchers you claim from shops will appear here.' },
    available: { title: 'No available vouchers', detail: 'Vouchers you claim from shops will appear here until they are used or expire.' },
    used: { title: 'No used vouchers', detail: 'Vouchers you use at checkout will appear here.' },
    expired: { title: 'No expired vouchers', detail: 'Vouchers that have passed their valid date will appear here.' },
};

function coverage(entry) {
    if (entry.type === 'shipping') {
        return 'Shipping';
    }

    if (entry.scope !== 'product') {
        return 'Whole shop';
    }

    const more = entry.productsCount > 1 ? ` +${entry.productsCount - 1} more` : '';

    return `${entry.productName || 'Selected products'}${more}`;
}

function validity(entry) {
    if (entry.status === 'used') {
        return 'Used';
    }

    return `${entry.status === 'expired' ? 'Expired' : 'Valid until'} ${voucherDateLabel(entry.expiresAt)}`;
}

async function useVoucher(entry) {
    if (usingId.value || entry.status !== 'available') {
        return;
    }

    if (entry.scope !== 'product') {
        if (entry.sellerId) {
            requestBuyerView('store', { id: entry.sellerId, name: entry.shopName });
        } else {
            requestBuyerView('stores');
        }

        return;
    }

    if (!entry.productId) {
        showError('This product is no longer available.');

        return;
    }

    usingId.value = entry.id;

    try {
        const product = await getProductById(entry.productId);

        if (product) {
            requestBuyerView('product', product);
        } else {
            showError('This product is no longer available.');
        }
    } finally {
        usingId.value = null;
    }
}

function handleTabKeydown(event, index) {
    const next = {
        ArrowRight: (index + 1) % tabs.length,
        ArrowLeft: (index + tabs.length - 1) % tabs.length,
        Home: 0,
        End: tabs.length - 1,
    }[event.key];

    if (next === undefined) {
        return;
    }

    event.preventDefault();
    selectedTab.value = tabs[next].id;
    nextTick(() => tabButtons.value[next]?.focus());
}

onActivated(() => loadWallet({ force: true }));
</script>

<template>
    <section class="acc-view vch" aria-labelledby="vouchers-title">
        <header class="acc-head">
            <h1 id="vouchers-title" class="acc-title">My Vouchers</h1>
            <p class="acc-lede">Your claimed vouchers. Eligible savings apply automatically at checkout.</p>
        </header>

        <div class="ord-tabs" role="tablist" aria-label="Vouchers by status">
            <button
                v-for="(tab, index) in tabs"
                :id="`vouchers-tab-${tab.id}`"
                :key="tab.id"
                :ref="el => (tabButtons[index] = el)"
                type="button"
                role="tab"
                class="ord-tab"
                :class="{ 'is-active': selectedTab === tab.id }"
                :aria-selected="selectedTab === tab.id"
                aria-controls="vouchers-panel"
                :tabindex="selectedTab === tab.id ? 0 : -1"
                @click="selectedTab = tab.id"
                @keydown="handleTabKeydown($event, index)"
            >
                {{ tab.label }}
                <span v-if="counts[tab.id]" class="ord-tab-count">{{ counts[tab.id] }}</span>
            </button>
        </div>

        <div id="vouchers-panel" role="tabpanel" :aria-labelledby="`vouchers-tab-${selectedTab}`" :aria-busy="isLoadingWallet">
            <div v-if="isLoadingWallet && !wallet.length">
                <div class="ord-list" aria-hidden="true">
                    <div v-for="n in 2" :key="n" class="ord-card vch-skeleton">
                        <span class="skeleton is-line"></span>
                        <span class="skeleton is-line"></span>
                    </div>
                </div>
                <p class="sr-only" role="status">Loading vouchers…</p>
            </div>
            <div v-else-if="walletError && !wallet.length" class="ord-state" role="alert">
                <h2>We couldn’t load your vouchers</h2>
                <p>{{ walletError }}</p>
                <button type="button" class="btn btn-primary" @click="loadWallet({ force: true })">Try again</button>
            </div>
            <div v-else-if="!visibleVouchers.length" class="ord-state">
                <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 7h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4V7Zm9 0v12" />
                </svg>
                <h2>{{ emptyMessages[selectedTab].title }}</h2>
                <p>{{ emptyMessages[selectedTab].detail }}</p>
            </div>
            <ul v-else class="vch-grid">
                <li v-for="entry in visibleVouchers" :key="entry.id">
                    <article class="vch-card" :class="[{ 'is-inactive': entry.status !== 'available' }, entry.type === 'shipping' ? 'is-shipping' : 'is-discount']">
                        <div class="vch-ticket">
                            <svg v-if="entry.type === 'shipping'" viewBox="0 0 48 48" width="42" height="42" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 13h25v22H5zM30 20h7l6 8v7H30z" />
                                <circle cx="13" cy="36" r="3" fill="currentColor" stroke="none" />
                                <circle cx="36" cy="36" r="3" fill="currentColor" stroke="none" />
                                <path d="M11 20h13M11 26h10" />
                            </svg>
                            <svg v-else viewBox="0 0 48 48" width="42" height="42" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 12h36v8a4 4 0 0 0 0 8v8H6v-8a4 4 0 0 0 0-8v-8Z" />
                                <circle cx="19" cy="20" r="2" />
                                <circle cx="29" cy="28" r="2" />
                                <path d="m29 19-10 10" />
                            </svg>
                            <span>{{ entry.shopName || 'BuyTheWay' }}</span>
                        </div>
                        <div class="vch-card-body">
                            <div class="vch-copy">
                                <h2>
                                    {{ entry.label }}
                                    <small v-if="entry.maxDiscount && entry.discountType === 'percentage'">Up to ₱{{ Number(entry.maxDiscount).toLocaleString('en-PH') }} off</small>
                                </h2>
                                <p class="vch-spend">{{ entry.minSpend ? `Min. spend ₱${Number(entry.minSpend).toLocaleString('en-PH')}` : 'No minimum spend' }}</p>
                                <p class="vch-coverage">{{ coverage(entry) }}</p>
                                <p class="vch-validity">{{ validity(entry) }}<span v-if="entry.status === 'available' && entry.runningLow" class="vch-low"> · Only {{ entry.remaining }} left</span></p>
                            </div>
                            <button
                                v-if="entry.status === 'available'"
                                type="button"
                                class="vch-use"
                                :disabled="Boolean(usingId)"
                                @click="useVoucher(entry)"
                            >{{ usingId === entry.id ? 'Opening…' : 'Use' }}</button>
                            <span v-else class="vch-state">{{ entry.status }}</span>
                        </div>
                    </article>
                </li>
            </ul>
        </div>
    </section>
</template>

<style scoped>
.vch .acc-head { margin-bottom: 18px; }
.vch-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 0; padding: 0; list-style: none; }
.vch-card { display: flex; min-width: 0; min-height: 154px; overflow: hidden; border: 1px solid var(--nx-line); border-radius: var(--nx-radius); background: var(--nx-surface); }
.vch-ticket { position: relative; display: flex; flex: 0 0 132px; flex-direction: column; align-items: center; justify-content: center; gap: 11px; padding: 16px 12px; background: var(--nx-accent); color: #fff; text-align: center; }
.vch-ticket::before { position: absolute; z-index: 1; top: 0; bottom: 0; left: -6px; width: 12px; background: radial-gradient(circle at center, var(--nx-bg) 0 5px, transparent 5.5px) left top / 12px 14px repeat-y; content: ''; pointer-events: none; }
.vch-ticket span { font-size: 12px; font-weight: 600; line-height: 1.25; overflow-wrap: anywhere; }
.vch-card.is-shipping .vch-ticket { background: #237d77; }
.vch-card-body { display: flex; flex: 1; align-items: center; gap: 12px; min-width: 0; padding: 19px 16px; }
.vch-copy { flex: 1; min-width: 0; }
.vch-copy h2 { margin: 0 0 3px; color: var(--nx-ink); font-size: 17px; font-weight: 650; line-height: 1.25; overflow-wrap: anywhere; }
.vch-copy h2 small { display: block; margin-top: 2px; color: var(--nx-text-2); font-size: 12px; font-weight: 500; }
.vch-copy p { margin: 2px 0 0; font-size: 12px; line-height: 1.4; }
.vch-copy .vch-spend { color: var(--nx-text); font-size: 14px; }
.vch-coverage { color: var(--nx-text-2); overflow-wrap: anywhere; }
.vch-validity { color: var(--nx-muted); }
.vch-low { color: var(--nx-accent-dark); font-weight: 600; }
.vch-use { flex: none; min-width: 54px; min-height: 38px; padding: 5px 10px; border: 1px solid var(--nx-accent); border-radius: var(--nx-radius-sm); background: #fff; color: var(--nx-accent-dark); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; transition: background var(--nx-dur-fast) var(--nx-ease), color var(--nx-dur-fast) var(--nx-ease); }
.vch-use:hover { background: var(--nx-accent); color: #fff; }
.vch-use:focus-visible { outline: 2px solid var(--nx-accent); outline-offset: 2px; }
.vch-use:disabled { opacity: .6; cursor: wait; }
.vch-state { flex: none; color: var(--nx-muted); font-size: 12px; text-transform: capitalize; }
.vch-card.is-inactive .vch-ticket { background: var(--nx-muted); }
.vch-skeleton { display: flex; flex-direction: column; gap: 14px; padding: 26px 20px; }
.vch-skeleton .is-line { width: 58%; height: 14px; }
.vch-skeleton .is-line:last-child { width: 35%; }
@media (max-width: 1000px) {
    .vch-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 640px) {
    .vch-ticket { flex-basis: 100px; padding-inline: 8px; }
    .vch-ticket svg { width: 34px; height: 34px; }
    .vch-card-body { flex-wrap: wrap; gap: 12px; padding: 14px; }
    .vch-copy { flex-basis: 100%; }
    .vch-copy h2 { font-size: 15px; }
    .vch-use { min-height: 36px; }
}
</style>
