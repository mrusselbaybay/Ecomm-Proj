// resources/js/buyer/composables/useCheckoutState.js
//
// The buyer's checkout choices, kept outside the Checkout page so they
// survive going back to the cart to edit items (which closes checkout) and
// coming back: the delivery address, a half-typed new address, each
// store's shipping method and the payment method. Checkout re-validates
// every one of them against a fresh server quote when it opens again.
//
// It also holds the idempotency key for the current order attempt: the
// same key is reused if the buyer retries after a failed or interrupted
// request, so a retry can never create the orders twice; a new key is made
// once orders are created or the items change.
import { reactive } from 'vue';

function uuid() {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

const state = reactive({
    // 'saved' (one of the address book's), 'new' (typed here) or 'edit'
    // (changing a saved one)
    addressMode: 'saved',
    selectedAddressId: '',
    form: { fullName: '', phone: '', line1: '', city: '', province: '', postalCode: '' },
    saveNewAddress: true,
    editingAddressId: '',
    // seller id -> shipping method id
    shippingBySeller: {},
    paymentMethod: 'cod',
    attemptKey: uuid(),
    attemptItems: ''
});

/**
 * The idempotency key for these items: unchanged while the same items are
 * being retried, new as soon as the items differ.
 */
export function attemptKeyFor(itemsSignature) {
    if (state.attemptItems !== itemsSignature) {
        state.attemptItems = itemsSignature;
        state.attemptKey = uuid();
    }

    return state.attemptKey;
}

/** After orders were created: the next checkout is a new attempt. */
export function finishAttempt() {
    state.attemptKey = uuid();
    state.attemptItems = '';
}

export function useCheckoutState() {
    return state;
}
