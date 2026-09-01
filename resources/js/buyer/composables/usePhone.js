// resources/js/buyer/composables/usePhone.js
//
// Shared rules for the buyer-facing contact-number fields (checkout
// delivery contact, account profile, saved addresses). All of them take a
// local 11-digit mobile number in 09XXXXXXXXX form.
//
// toLocalMobile() is what every one of those inputs runs on `@input`, so
// the field can never hold more than 11 digits no matter how the value
// got there (typing, autofill, or pasting a formatted / +63 number).

// Exactly 11 digits, leading zero — 09171234567.
export const LOCAL_MOBILE_RE = /^0\d{10}$/;

/**
 * Normalise any user input to at most 11 local mobile digits:
 *   - strips every non-digit (spaces, dashes, parens, a leading +)
 *   - folds a +63 / 63 country-code prefix back to the 0-prefixed form
 *   - hard-caps the result at 11 characters
 *
 * @param {string} raw
 * @returns {string}
 */
export function toLocalMobile(raw) {
    let digits = String(raw ?? '').replace(/\D/g, '');

    if (digits.startsWith('63') && digits.length >= 12) {
        digits = '0' + digits.slice(2);
    }

    return digits.slice(0, 11);
}

export function isValidLocalMobile(value) {
    return LOCAL_MOBILE_RE.test(String(value ?? ''));
}

export function usePhone() {
    return { toLocalMobile, isValidLocalMobile, LOCAL_MOBILE_RE };
}
