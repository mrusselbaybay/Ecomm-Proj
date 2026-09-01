// resources/js/buyer/composables/useToasts.js
//
// The single buyer-wide notification store. There was no toast/snackbar
// system before this — feedback was window.alert()/confirm(). Every buyer
// page renders inside Dashboard.vue (createApp(Dashboard)), so <ToastHost>
// mounted once there covers Cart, Product Details, Checkout, Orders,
// Reviews, Account, etc. Reusable on any of them via useToasts().
//
// Design notes:
//   - module-scoped refs => one shared queue across every component.
//   - identical (type + message) toasts are de-duplicated: the existing
//     one's timer is just refreshed, so rapid repeat actions ("Only 3
//     available") don't stack.
//   - timers live in a Map keyed by id and are always cleared on dismiss
//     / clearToasts, so nothing leaks when a view unmounts.
//   - success is never shown pre-emptively; callers await the server first.
import { ref } from 'vue';

const DEFAULT_TIMEOUT = 4500;
const ERROR_TIMEOUT = 7000;
const RESUME_TIMEOUT = 3000;

const toasts = ref([]);
const timers = new Map();

let seq = 0;

function clearTimer(id) {
    const handle = timers.get(id);

    if (handle) {
        clearTimeout(handle);
        timers.delete(id);
    }
}

function startTimer(id, timeout) {
    clearTimer(id);

    if (timeout > 0) {
        timers.set(id, setTimeout(() => dismiss(id), timeout));
    }
}

export function dismiss(id) {
    clearTimer(id);
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

export function clearToasts() {
    timers.forEach((handle) => clearTimeout(handle));
    timers.clear();
    toasts.value = [];
}

/**
 * @param {'success'|'error'|'warning'|'info'} type
 * @param {string} message
 * @param {{ timeout?: number, action?: { label: string, handler: Function } }} [options]
 * @returns {number|null} the toast id (or null when there was nothing to show)
 */
function notify(type, message, options = {}) {
    const text = typeof message === 'string' ? message.trim() : '';

    if (!text) {
        return null;
    }

    const timeout = options.timeout ?? (type === 'error' ? ERROR_TIMEOUT : DEFAULT_TIMEOUT);
    const existing = toasts.value.find((toast) => toast.type === type && toast.message === text);

    if (existing) {
        // Same message already on screen — refresh its life instead of
        // pushing a duplicate.
        startTimer(existing.id, timeout);

        return existing.id;
    }

    const id = ++seq;

    toasts.value = [
        ...toasts.value,
        {
            id,
            type,
            message: text,
            action: options.action || null,
            timeout,
        },
    ];

    startTimer(id, timeout);

    return id;
}

export function useToasts() {
    return {
        toasts,

        notify,
        success: (message, options) => notify('success', message, options),
        error: (message, options) => notify('error', message, options),
        warning: (message, options) => notify('warning', message, options),
        info: (message, options) => notify('info', message, options),

        dismiss,
        clearToasts,

        // Used by ToastHost to pause on hover/focus and resume on leave.
        pauseTimer: (id) => clearTimer(id),
        resumeTimer: (id) => startTimer(id, RESUME_TIMEOUT),
    };
}
