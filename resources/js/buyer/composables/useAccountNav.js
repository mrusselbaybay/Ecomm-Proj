// resources/js/buyer/composables/useAccountNav.js
//
// The buyer account area: which destinations exist, how they map to URLs
// (?account=<id>, so a refreshed or shared link opens the same page), and
// the unsaved-changes guard sections register so leaving a half-edited
// form asks first.
//
// Settings sections render inside AccountArea.vue (one mounted shell,
// sections kept alive). Orders, Wishlist and My Reviews are their own
// existing pages that share the same AccountNav.
import { requestBuyerView } from './useBuyerNav';

export const SETTINGS_SECTIONS = ['profile', 'addresses', 'payments', 'security', 'notifications', 'privacy', 'following', 'help'];

export const SHOPPING_PAGES = ['orders', 'wishlist', 'reviews'];

/**
 * Grouped navigation. Payment Methods shows what a payment provider has
 * saved for the buyer (nothing can be typed in there) and how checkout
 * can be paid — see AccountPayments.vue.
 */
export const ACCOUNT_NAV_GROUPS = [
    {
        id: 'settings',
        label: 'Account settings',
        items: [
            { id: 'profile', label: 'My Profile', icon: 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z' },
            { id: 'addresses', label: 'Addresses', icon: 'M20 10c0 5-8 12-8 12s-8-7-8-12a8 8 0 0 1 16 0ZM12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z' },
            { id: 'payments', label: 'Payment Methods', icon: 'M3 6h18v12H3zM3 10h18M7 15h3' },
            { id: 'security', label: 'Login & Security', icon: 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4' },
            { id: 'notifications', label: 'Notifications', icon: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0' },
            { id: 'privacy', label: 'Privacy & Account', icon: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z' }
        ]
    },
    {
        id: 'shopping',
        label: 'Shopping & support',
        items: [
            { id: 'orders', label: 'My Orders', icon: 'M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8' },
            { id: 'wishlist', label: 'Wishlist', icon: 'M12 20.5s-7.5-4.6-9.2-9.4C1.7 7.9 3.9 4.5 7.4 4.5c2 0 3.5 1.1 4.6 2.7 1.1-1.6 2.6-2.7 4.6-2.7 3.5 0 5.7 3.4 4.6 6.6-1.7 4.8-9.2 9.4-9.2 9.4Z' },
            { id: 'reviews', label: 'My Reviews', icon: 'M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z' },
            { id: 'following', label: 'Followed Stores', icon: 'M3 9.5 4.6 4h14.8L21 9.5M4 9.5V20h16V9.5M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M10 20v-5h4v5' },
            { id: 'help', label: 'Help & Support', icon: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20ZM9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01' }
        ]
    }
];

const ALL_IDS = [...SETTINGS_SECTIONS, ...SHOPPING_PAGES];

/** The account destination named in a URL's query string, or null. */
export function accountFromQuery(search) {
    const id = new URLSearchParams(search).get('account');

    return ALL_IDS.includes(id) ? id : null;
}

export function accountUrl(id) {
    return `${window.location.pathname}?account=${encodeURIComponent(id)}`;
}

/** Navigate to an account destination (Dashboard runs the guard). */
export function goToAccount(id) {
    if (SETTINGS_SECTIONS.includes(id)) {
        requestBuyerView('account', { section: id });
    } else if (SHOPPING_PAGES.includes(id)) {
        requestBuyerView(id);
    }
}

/*
|--------------------------------------------------------------------------
| Unsaved changes
|--------------------------------------------------------------------------
|
| A section registers { label, isDirty(), discard() }. Before any account
| navigation Dashboard asks hasUnsavedChanges(); when the buyer chooses to
| leave anyway, discardUnsavedChanges() resets those forms so a kept-alive
| section doesn't keep nagging.
|
*/

const guards = new Set();

export function registerUnsavedGuard(guard) {
    guards.add(guard);

    return () => guards.delete(guard);
}

export function dirtyLabels() {
    return [...guards].filter(guard => guard.isDirty()).map(guard => guard.label);
}

export function hasUnsavedChanges() {
    return dirtyLabels().length > 0;
}

export function discardUnsavedChanges() {
    [...guards].filter(guard => guard.isDirty()).forEach(guard => guard.discard());
}
