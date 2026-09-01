// resources/js/buyer/composables/useConfirm.js
//
// Promise-based confirmation dialog, so destructive buyer actions can
// `await confirm({...})` instead of calling window.confirm() (which isn't
// stylable, isn't screen-reader friendly beyond the browser default, and
// blocks the event loop). <ConfirmDialog> is mounted once in Dashboard.vue
// and reads `confirmState`; resolving/cancelling clears it.
import { ref } from 'vue';

const confirmState = ref(null);

/**
 * @param {{
 *   title?: string,
 *   message?: string,
 *   confirmLabel?: string,
 *   cancelLabel?: string,
 *   tone?: 'default' | 'danger'
 * }} [options]
 * @returns {Promise<boolean>} true if confirmed, false if cancelled/dismissed
 */
function confirm(options = {}) {
    // Only one confirmation at a time — resolve any pending one as
    // cancelled before opening the next.
    if (confirmState.value) {
        confirmState.value.resolve(false);
    }

    return new Promise((resolve) => {
        confirmState.value = {
            title: options.title || 'Are you sure?',
            message: options.message || '',
            confirmLabel: options.confirmLabel || 'Confirm',
            cancelLabel: options.cancelLabel || 'Cancel',
            tone: options.tone === 'danger' ? 'danger' : 'default',
            resolve,
        };
    });
}

function settle(result) {
    if (confirmState.value) {
        confirmState.value.resolve(result);
        confirmState.value = null;
    }
}

export function useConfirm() {
    return {
        confirmState,
        confirm,
        accept: () => settle(true),
        cancel: () => settle(false),
    };
}
