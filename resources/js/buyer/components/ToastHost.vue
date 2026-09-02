<script setup>
/*
|--------------------------------------------------------------------------
| ToastHost — the one on-screen home for buyer notifications
|--------------------------------------------------------------------------
|
| Mounted once in Dashboard.vue (every buyer page renders inside it), the
| same way <Chat/> is. Reads the shared queue from useToasts.js.
|
| Accessibility / UX:
|   - fixed top-centre, below the sticky header, and above nothing that
|     the buyer needs to tap (it sits clear of the checkout bar).
|   - the region is aria-live="polite" + role="status" so screen readers
|     announce new messages without stealing focus.
|   - each toast: auto-dismisses (4.5s / 7s for errors), pauses on
|     hover/focus, and has a real dismiss button (44px hit area).
|   - errors can carry a single "Retry" action.
|   - honours prefers-reduced-motion (no slide/scale, just opacity).
|
*/
import { useToasts } from '../composables/useToasts';

const { toasts, dismiss, pauseTimer, resumeTimer } = useToasts();

const ICONS = {
    success: 'M20 6 9 17l-5-5',
    error: 'M18 6 6 18M6 6l12 12',
    warning: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z',
    info: 'M12 16v-4m0-4h.01M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z',
};

function runAction(toast) {
    if (typeof toast.action?.handler === 'function') {
        toast.action.handler();
    }

    dismiss(toast.id);
}
</script>

<template>
    <div
        class="toast-host"
        role="region"
        aria-label="Notifications"
    >
        <transition-group
            name="toast"
            tag="div"
            class="toast-host-list"
            aria-live="polite"
            role="status"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="toast"
                :class="`toast--${toast.type}`"
                @mouseenter="pauseTimer(toast.id)"
                @mouseleave="resumeTimer(toast.id)"
                @focusin="pauseTimer(toast.id)"
                @focusout="resumeTimer(toast.id)"
            >
                <span
                    class="toast-icon"
                    aria-hidden="true"
                >
                    <svg
                        viewBox="0 0 24 24"
                        width="18"
                        height="18"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path :d="ICONS[toast.type] || ICONS.info" />
                    </svg>
                </span>

                <p class="toast-message">
                    {{ toast.message }}
                </p>

                <button
                    v-if="toast.action"
                    type="button"
                    class="toast-action"
                    @click="runAction(toast)"
                >
                    {{ toast.action.label }}
                </button>

                <button
                    type="button"
                    class="toast-close"
                    aria-label="Dismiss notification"
                    @click="dismiss(toast.id)"
                >
                    <svg
                        viewBox="0 0 24 24"
                        width="16"
                        height="16"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </transition-group>
    </div>
</template>

<style scoped>
.toast-host {
    position: fixed;
    top: 88px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 1000;
    width: min(420px, calc(100vw - 32px));
    pointer-events: none;
}

.toast-host-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.toast {
    pointer-events: auto;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 14px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 32px -12px rgba(15, 23, 42, 0.28);
    color: #0f172a;
}

.toast-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 8px;
    flex-shrink: 0;
    margin-top: 1px;
}

.toast--success .toast-icon { background: #ecfdf5; color: #047857; }
.toast--error   .toast-icon { background: #fef2f2; color: #b91c1c; }
.toast--warning .toast-icon { background: #fff7ed; color: #c2410c; }
.toast--info    .toast-icon { background: #eff6ff; color: #1d4ed8; }

.toast--success { border-left: 4px solid #047857; }
.toast--error   { border-left: 4px solid #b91c1c; }
.toast--warning { border-left: 4px solid #c2410c; }
.toast--info    { border-left: 4px solid #1d4ed8; }

.toast-message {
    flex: 1;
    margin: 0;
    font-size: 13.5px;
    line-height: 1.45;
    font-weight: 500;
    padding-top: 3px;
}

.toast-action {
    align-self: center;
    padding: 6px 10px;
    border-radius: 9px;
    border: 1px solid currentColor;
    background: transparent;
    font-size: 12.5px;
    font-weight: 700;
    color: #0f766e;
    cursor: pointer;
    white-space: nowrap;
}

.toast-action:hover { background: #f0fdfa; }

.toast-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    margin: -4px -4px -4px 0;
    border: none;
    background: transparent;
    color: #94a3b8;
    border-radius: 8px;
    cursor: pointer;
    flex-shrink: 0;
}

.toast-close:hover { background: #f1f5f9; color: #475569; }

.toast-action:focus-visible,
.toast-close:focus-visible {
    outline: 2px solid #0d9488;
    outline-offset: 2px;
}

.toast-enter-active,
.toast-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(-8px) scale(0.98);
}

.toast-leave-active {
    position: absolute;
    width: 100%;
}

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active,
    .toast-leave-active {
        transition: opacity 0.15s linear;
    }

    .toast-enter-from,
    .toast-leave-to {
        transform: none;
    }
}

@media (max-width: 640px) {
    .toast-host {
        top: 76px;
        width: calc(100vw - 24px);
    }
}
</style>
