<script setup>
/*
|--------------------------------------------------------------------------
| ConfirmDialog — accessible replacement for window.confirm()
|--------------------------------------------------------------------------
|
| Mounted once in Dashboard.vue. Driven entirely by useConfirm.js:
| a caller does `if (await confirm({ ... })) { ... }`.
|
|   - role="alertdialog", labelled + described by its own nodes
|   - focus moves to the primary button on open, returns to the opener on
|     close, and Tab is trapped inside while open
|   - Escape and backdrop click both resolve as "cancel"
|   - the confirm button can be toned 'danger' for destructive actions
|
*/
import { nextTick, ref, watch } from 'vue';
import { useConfirm } from '../composables/useConfirm';

const { confirmState, accept, cancel } = useConfirm();

const dialogRef = ref(null);
const confirmBtnRef = ref(null);
let lastFocused = null;

watch(confirmState, async (state) => {
    if (state) {
        lastFocused = document.activeElement;
        await nextTick();
        confirmBtnRef.value?.focus();
    } else if (lastFocused && typeof lastFocused.focus === 'function') {
        lastFocused.focus();
        lastFocused = null;
    }
});

function onKeydown(event) {
    if (!confirmState.value) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        cancel();

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const focusable = dialogRef.value?.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
    );

    if (!focusable || focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}
</script>

<template>
    <transition name="confirm-fade">
        <div
            v-if="confirmState"
            class="confirm-overlay"
            @click.self="cancel"
            @keydown="onKeydown"
        >
            <div
                ref="dialogRef"
                class="confirm-dialog"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="confirm-title"
                aria-describedby="confirm-message"
            >
                <h2
                    id="confirm-title"
                    class="confirm-title"
                >
                    {{ confirmState.title }}
                </h2>

                <p
                    id="confirm-message"
                    class="confirm-message"
                >
                    {{ confirmState.message }}
                </p>

                <div class="confirm-actions">
                    <button
                        type="button"
                        class="confirm-cancel"
                        @click="cancel"
                    >
                        {{ confirmState.cancelLabel }}
                    </button>

                    <button
                        ref="confirmBtnRef"
                        type="button"
                        class="confirm-accept"
                        :class="{ 'confirm-accept--danger': confirmState.tone === 'danger' }"
                        @click="accept"
                    >
                        {{ confirmState.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.confirm-overlay {
    position: fixed;
    inset: 0;
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(15, 23, 42, 0.55);
}

.confirm-dialog {
    width: min(400px, 100%);
    background: #ffffff;
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 24px 60px -12px rgba(15, 23, 42, 0.4);
}

.confirm-title {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
}

.confirm-message {
    margin: 0 0 20px;
    font-size: 14px;
    line-height: 1.55;
    color: #475569;
}

.confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.confirm-cancel,
.confirm-accept {
    min-height: 44px;
    padding: 0 18px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.confirm-cancel {
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #475569;
}

.confirm-cancel:hover {
    background: #f8fafc;
}

.confirm-accept {
    border: none;
    background: #0d9488;
    color: #ffffff;
}

.confirm-accept:hover {
    background: #0f766e;
}

.confirm-accept--danger {
    background: #dc2626;
}

.confirm-accept--danger:hover {
    background: #b91c1c;
}

.confirm-cancel:focus-visible,
.confirm-accept:focus-visible {
    outline: 2px solid #0d9488;
    outline-offset: 2px;
}

.confirm-fade-enter-active,
.confirm-fade-leave-active {
    transition: opacity 0.18s ease;
}

.confirm-fade-enter-from,
.confirm-fade-leave-to {
    opacity: 0;
}
</style>
