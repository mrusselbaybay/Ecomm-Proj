// App-wide buyer navigation requests. Header and the account sidebar call
// navigate(view) from any page; Dashboard.vue watches `navRequest` and opens
// the view — no emit relay through every page component needed.
import { ref } from 'vue';

export const navRequest = ref(null);

/** @param {'profile'|'orders'|'wishlist'|'reviews'|'addresses'|'payments'|'vouchers'} view */
export function navigate(view) {
    navRequest.value = { view, at: Date.now() };
}
