<script setup>
// Shared account-area sidebar (was copy-pasted into every account page).
import { navigate } from '../composables/useBuyerNav';

defineProps({
    active: { type: String, default: '' },
    // OrderDetails shows an extra "Order Tracking" entry under My Orders.
    showTracking: { type: Boolean, default: false },
});

const emit = defineEmits(['track-order']);

const items = [
    { view: 'profile', label: 'My Profile', icon: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />' },
    { view: 'orders', label: 'My Orders', icon: '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" /><path d="M12 22V12" /><polyline points="3.29 7 12 12 20.71 7" /><path d="m7.5 4.27 9 5.15" />' },
    { view: 'wishlist', label: 'Wishlist', icon: '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" />' },
    { view: 'reviews', label: 'My Reviews', icon: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />' },
    { view: 'coupons', label: 'My Coupons', icon: '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" />' },
    { view: 'addresses', label: 'Saved Addresses', icon: '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />' },
    { view: 'payments', label: 'Payment Methods', icon: '<rect width="20" height="14" x="2" y="5" rx="2" /><line x1="2" x2="22" y1="10" y2="10" />' },
];
const TRACK_ICON = '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" /><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62L18.3 8.38A1 1 0 0 0 17.52 8H14" /><circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" />';
</script>

<template>
    <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-36">
        <nav
            class="bg-white rounded-3xl p-4 border border-slate-100 space-y-1"
            style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
            aria-label="Account"
        >
            <template v-for="item in items" :key="item.view">
                <button
                    type="button"
                    class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl transition-colors"
                    :class="active === item.view ? 'bg-slate-100 text-[#0d9488] font-semibold' : 'text-slate-500 hover:bg-slate-50'"
                    :aria-current="active === item.view ? 'page' : undefined"
                    @click="navigate(item.view)"
                >
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="item.icon" />
                    {{ item.label }}
                </button>
                <button
                    v-if="item.view === 'orders' && showTracking"
                    type="button"
                    class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                    @click="emit('track-order')"
                >
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="TRACK_ICON" />
                    Order Tracking
                </button>
            </template>
        </nav>
    </aside>
</template>
