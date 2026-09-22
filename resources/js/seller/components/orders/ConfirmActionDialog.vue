<!-- resources/js/seller/components/orders/ConfirmActionDialog.vue -->
<!--
  Confirmation dialog for order actions. Used for the destructive ones
  (Cancel / Reject) which require a typed reason, and can also gate a
  plain confirm. Accessible: labelled, Esc + backdrop close, focus moves
  in on open and is restored on close, confirm is blocked while busy or
  while a required reason is too short.
-->
<template>
    <Teleport to="body">
        <Transition name="modal-fade">
        <div
            v-if="open"
            class="modal-overlay order-confirm-overlay"
            @click.self="close"
        >
            <div
                ref="panel"
                class="modal-panel order-confirm-panel"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="messageId"
                @keydown.esc.stop.prevent="close"
            >
                <div class="modal-header">
                    <h3 :id="titleId">{{ title }}</h3>
                    <button
                        type="button"
                        class="modal-close"
                        aria-label="Close dialog"
                        @click="close"
                    >
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path
                                d="M5 5l10 10M15 5L5 15"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>
                    </button>
                </div>

                <p :id="messageId" class="modal-desc">{{ message }}</p>

                <div v-if="requiresReason" class="order-confirm-field">
                    <label :for="reasonId" class="field-label">
                        {{ reasonLabel }}
                    </label>
                    <textarea
                        :id="reasonId"
                        ref="reasonInput"
                        v-model="reason"
                        class="field-input order-confirm-reason"
                        rows="3"
                        :placeholder="reasonPlaceholder"
                        :disabled="busy"
                    ></textarea>
                    <p
                        v-if="reasonTouched && !reasonValid"
                        class="order-confirm-hint"
                    >
                        Please give a reason of at least 3 characters — the buyer sees this.
                    </p>
                </div>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="btn-outline"
                        :disabled="busy"
                        @click="close"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        ref="confirmBtn"
                        type="button"
                        :class="tone === 'danger' ? 'btn-danger-soft' : 'btn-primary'"
                        :disabled="busy || (requiresReason && !reasonValid)"
                        @click="confirm"
                    >
                        {{ busy ? 'Working…' : confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirm' },
    cancelLabel: { type: String, default: 'Keep order' },
    tone: { type: String, default: 'primary' }, // 'primary' | 'danger'
    requiresReason: { type: Boolean, default: false },
    reasonLabel: { type: String, default: 'Reason' },
    reasonPlaceholder: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);

const uid = Math.random().toString(36).slice(2, 8);
const titleId = `oc-title-${uid}`;
const messageId = `oc-msg-${uid}`;
const reasonId = `oc-reason-${uid}`;

const reason = ref('');
const reasonTouched = ref(false);
const reasonInput = ref(null);
const confirmBtn = ref(null);
const panel = ref(null);

const reasonValid = computed(() => reason.value.trim().length >= 3);

let lastFocused = null;

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            lastFocused = document.activeElement;
            reason.value = '';
            reasonTouched.value = false;
            document.addEventListener('keydown', onTabTrap, true);
            await nextTick();
            (props.requiresReason ? reasonInput.value : confirmBtn.value)?.focus();
        } else {
            document.removeEventListener('keydown', onTabTrap, true);
            lastFocused?.focus?.();
        }
    },
);

// Minimal focus trap — keep Tab inside the panel while it is open.
function onTabTrap(e) {
    if (e.key !== 'Tab' || !panel.value) {
        return;
    }

    const focusable = panel.value.querySelectorAll(
        'button, textarea, [href], input, select, [tabindex]:not([tabindex="-1"])',
    );

    if (!focusable.length) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

function close() {
    if (props.busy) {
        return;
    }

    emit('cancel');
}

function confirm() {
    if (props.busy) {
        return;
    }

    if (props.requiresReason && !reasonValid.value) {
        reasonTouched.value = true;

        return;
    }

    emit('confirm', reason.value.trim());
}

onBeforeUnmount(() => document.removeEventListener('keydown', onTabTrap, true));
</script>
