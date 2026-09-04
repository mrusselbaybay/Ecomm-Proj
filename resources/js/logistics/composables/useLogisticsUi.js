// resources/js/logistics/composables/useLogisticsUi.js
//
// One toast stack, one confirm dialog, and one set of formatters for the
// whole logistics portal. Replaces the three separate copies of the toast
// logic (Applications / Couriers / ParcelOperations) and the four copies
// of formatDate / initials / badgeClass that had drifted apart.
//
// Toasts and the confirm dialog are module-level singletons so any
// component can raise one and the layout renders them once, at the top of
// the shell — no per-page toast stack fighting for the same corner.
import { readonly, ref } from 'vue';

// ---------------------------------------------------------------- toasts

const toasts = ref([]);
const TOAST_TTL_MS = 4000;
let toastSeq = 0;

function dismissToast(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

/**
 * @param {string} message
 * @param {'success'|'error'|'info'} type
 */
function notify(message, type = 'success') {
    if (!message) {
        return null;
    }

    const id = ++toastSeq;
    toasts.value = [...toasts.value, { id, message, type }];

    // Errors stay twice as long — they usually need reading, not glancing.
    setTimeout(
        dismissToast,
        type === 'error' ? TOAST_TTL_MS * 2 : TOAST_TTL_MS,
        id,
    );

    return id;
}

const notifyError = (error, fallback = 'Something went wrong.') =>
    notify(
        typeof error === 'string' ? error : error?.message || fallback,
        'error',
    );

// --------------------------------------------------------------- confirm

const confirmState = ref(null);

/**
 * Promise-based confirm dialog. Resolves true when the user confirms.
 *
 * @param {{title: string, message: string, confirmLabel?: string,
 *          cancelLabel?: string, tone?: 'primary'|'danger'}} options
 * @returns {Promise<boolean>}
 */
function askConfirm(options) {
    return new Promise((resolve) => {
        confirmState.value = {
            title: options.title,
            message: options.message,
            confirmLabel: options.confirmLabel || 'Confirm',
            cancelLabel: options.cancelLabel || 'Cancel',
            tone: options.tone || 'primary',
            resolve,
        };
    });
}

function resolveConfirm(result) {
    const pending = confirmState.value;
    confirmState.value = null;
    pending?.resolve(result);
}

// ------------------------------------------------------------ formatters

const DATE_OPTS = { month: 'short', day: 'numeric', year: 'numeric' };
const TIME_OPTS = { hour: 'numeric', minute: '2-digit' };

function toDate(value) {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

const formatDate = (value, fallback = '—') =>
    toDate(value)?.toLocaleDateString('en-US', DATE_OPTS) ?? fallback;

const formatDateTime = (value, fallback = '—') =>
    toDate(value)?.toLocaleString('en-US', { ...DATE_OPTS, ...TIME_OPTS }) ??
    fallback;

/**
 * "3 min ago" / "2 h ago" / "Sep 4" — for timestamps where recency is the
 * point (queue arrival, last sync) rather than the exact date.
 */
function formatRelative(value, fallback = '—') {
    const date = toDate(value);

    if (!date) {
        return fallback;
    }

    const seconds = Math.round((Date.now() - date.getTime()) / 1000);

    if (seconds < 60) {
        return 'Just now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min ago`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)} h ago`;
    }

    if (seconds < 604800) {
        return `${Math.floor(seconds / 86400)} d ago`;
    }

    return formatDate(value, fallback);
}

/**
 * Wall-clock digits exactly as they were picked, with no timezone shift —
 * interview_scheduled_at is a "floating" local time, not a real instant.
 */
function formatFloatingDateTime(value, fallback = '') {
    if (!value) {
        return fallback;
    }

    const naive = String(value).replace(/(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/, '');
    const date = new Date(naive);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('en-US', {
        weekday: 'short',
        ...DATE_OPTS,
        ...TIME_OPTS,
    });
}

/** "Juan Dela Cruz" from any {first_name, last_name} shaped record. */
function personName(person, fallback = '') {
    if (!person) {
        return fallback;
    }

    return (
        `${person.first_name || ''} ${person.last_name || ''}`.trim() ||
        fallback
    );
}

/** Up to two uppercase initials, for avatar chips. */
function initials(person, fallback = '?') {
    const parts = personName(person)
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]);

    return parts.join('').toUpperCase() || fallback;
}

/** Maps any status string onto the portal's four badge colours. */
function badgeClass(status) {
    const value = String(status || '').toLowerCase();

    if (['accepted', 'approved', 'active', 'delivered'].includes(value)) {
        return 'badge-teal';
    }

    if (['pending', 'received', 'sorted', 'assigned'].includes(value)) {
        return 'badge-amber';
    }

    if (['rejected', 'withdrawn', 'deactivated', 'suspended'].includes(value)) {
        return 'badge-red';
    }

    return 'badge-slate';
}

function formatFileSize(bytes) {
    if (!bytes) {
        return '';
    }

    const kb = bytes / 1024;

    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

/** snake_case_value -> "Snake Case Value" */
function titleCase(value) {
    if (!value) {
        return '';
    }

    return String(value)
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export function useLogisticsUi() {
    return {
        toasts: readonly(toasts),
        notify,
        notifyError,
        dismissToast,
        confirmState: readonly(confirmState),
        askConfirm,
        resolveConfirm,
        formatDate,
        formatDateTime,
        formatRelative,
        formatFloatingDateTime,
        personName,
        initials,
        badgeClass,
        formatFileSize,
        titleCase,
    };
}
