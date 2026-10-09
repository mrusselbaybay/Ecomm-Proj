<script>
/*
| Module state shared by every BaseModal: which dialogs are open (only the
| topmost one answers keys and holds focus) and the page scroll lock, which
| is counted so two dialogs never fight over it.
*/
const openStack = [];
let lockCount = 0;
let lockedScrollY = 0;
let lockedStyles = null;

function lockScroll() {
    lockCount += 1;

    if (lockCount > 1) {
        return;
    }

    const { body, documentElement } = document;
    // With scrollbar-gutter: stable (layout.css) the gutter stays reserved,
    // so there is nothing to compensate for.
    const gutterKept = getComputedStyle(documentElement).scrollbarGutter?.includes('stable');
    const scrollbar = gutterKept ? 0 : window.innerWidth - documentElement.clientWidth;

    lockedScrollY = window.scrollY;
    lockedStyles = {
        position: body.style.position,
        top: body.style.top,
        left: body.style.left,
        right: body.style.right,
        paddingRight: body.style.paddingRight
    };

    // position: fixed is the only lock iOS Safari honours; the offset keeps
    // the page where it was, and any padding stands in for the scrollbar so
    // nothing shifts sideways.
    Object.assign(body.style, {
        position: 'fixed',
        top: `-${lockedScrollY}px`,
        left: '0',
        right: '0',
        paddingRight: scrollbar > 0 ? `${scrollbar}px` : body.style.paddingRight
    });
}

function unlockScroll() {
    lockCount = Math.max(0, lockCount - 1);

    if (lockCount > 0 || !lockedStyles) {
        return;
    }

    Object.assign(document.body.style, lockedStyles);
    lockedStyles = null;
    window.scrollTo({ top: lockedScrollY, behavior: 'instant' });
}
</script>

<script setup>
/*
|--------------------------------------------------------------------------
| BaseModal — the one dialog every buyer modal is built on
|--------------------------------------------------------------------------
|
| Owns everything a dialog must get right so each modal only supplies its
| content: role + aria-modal + an accessible name, the focus trap, initial
| focus, focus returned to whatever opened it, Escape / backdrop dismissal,
| the page scroll lock (scroll position restored), a scrollable body whose
| header and actions stay put, and the open / close motion.
|
|   <BaseModal :open="show" size="md" title="…" @close="show = false">
|       …body…
|       <template #footer="{ requestClose }">…actions…</template>
|   </BaseModal>
|
| Sizes follow the task: sm (420px) confirmations, md (560px) forms,
| lg (720px) wider selection dialogs. At 640px and below every size is a
| bottom sheet that sits above the on-screen keyboard and the home bar.
|
| `busy` blocks every way of closing (a request is in flight); `dirty`
| turns a close into an in-dialog "Discard changes?" step instead of a
| second, stacked dialog.
|
| Motion: CSS transitions driven by data-state, so a close interrupted by a
| reopen reverses from where it is instead of restarting. The dialog stays
| mounted until its exit has played. Styles: "MODAL FOUNDATION", layout.css.
| Teleported to the end of #buyer-app, so it always sits above the page.
|
*/
import { computed, nextTick, onBeforeUnmount, ref, useId, useSlots, watch } from 'vue';

const props = defineProps({
    open: {
        type: Boolean,
        default: false
    },
    size: {
        type: String,
        default: 'md',
        validator: value => ['sm', 'md', 'lg', 'xl'].includes(value)
    },
    panelClass: {
        type: String,
        default: ''
    },
    role: {
        type: String,
        default: 'dialog',
        validator: value => ['dialog', 'alertdialog'].includes(value)
    },
    title: {
        type: String,
        default: ''
    },
    description: {
        type: String,
        default: ''
    },
    eyebrow: {
        type: String,
        default: ''
    },
    closeLabel: {
        type: String,
        default: 'Close'
    },
    showClose: {
        type: Boolean,
        default: true
    },
    // Backdrop click dismisses; Escape always does (unless busy).
    dismissible: {
        type: Boolean,
        default: true
    },
    busy: {
        type: Boolean,
        default: false
    },
    dirty: {
        type: Boolean,
        default: false
    },
    discardLabel: {
        type: String,
        default: 'Discard changes'
    },
    // CSS selector, resolved inside the dialog, for the first focus.
    initialFocus: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['close', 'after-leave']);
const slots = useSlots();

const titleId = `nx-modal-title-${useId()}`;
const descriptionId = `nx-modal-desc-${useId()}`;

const SHEET_QUERY = '(max-width: 640px)';
const REDUCED_QUERY = '(prefers-reduced-motion: reduce)';
// Exit durations, matching layout.css (exit is quicker than the entrance).
const LEAVE_MS = { dialog: 160, sheet: 240, reduced: 120 };

const mounted = ref(false);
const state = ref('closed');
const confirmingDiscard = ref(false);
const scrolledTop = ref(false);
const scrolledBottom = ref(false);

const root = ref(null);
const panel = ref(null);
const body = ref(null);
const keepEditingButton = ref(null);

let opener = null;
let unmountTimer = null;
let backdropPressed = false;
let resizeObserver = null;
const self = {};

const hasHeader = computed(() => Boolean(props.title || props.eyebrow || slots.header));

function leaveDuration() {
    if (window.matchMedia(REDUCED_QUERY).matches) {
        return LEAVE_MS.reduced;
    }

    return window.matchMedia(SHEET_QUERY).matches ? LEAVE_MS.sheet : LEAVE_MS.dialog;
}

/*
| Open / close
*/

watch(() => props.open, open => (open ? show() : hide()), { immediate: true });

async function show() {
    clearTimeout(unmountTimer);
    confirmingDiscard.value = false;

    if (mounted.value) {
        // Reopened mid-exit: reverse from the current frame.
        state.value = 'open';
        addToStack();

        if (!panel.value?.contains(document.activeElement)) {
            focusInitial();
        }

        return;
    }

    const active = document.activeElement;

    // Remember the trigger, unless focus is already inside another overlay
    // (that overlay restores its own).
    opener = active && active !== document.body ? active : null;

    mounted.value = true;
    lockScroll();
    addToStack();
    trackViewport(true);

    await nextTick();

    if (!props.open || !root.value) {
        return;
    }

    // Commit the closed frame first so the change to "open" transitions.
    root.value.getBoundingClientRect();
    state.value = 'open';
    focusInitial();
    observeBody();
}

// A request starting disables the form; focus inside it would drop to the
// page, so it moves to the busy action first (flush: 'pre' runs before the
// disabled fields are rendered).
watch(() => props.busy, (busy) => {
    if (busy && body.value?.contains(document.activeElement)) {
        (panel.value?.querySelector('.nx-modal-foot [aria-busy="true"], .nx-modal-foot .btn-primary') || panel.value)?.focus();
    }
}, { flush: 'pre' });

function hide() {
    if (!mounted.value) {
        return;
    }

    state.value = 'closed';
    confirmingDiscard.value = false;
    removeFromStack();
    clearTimeout(unmountTimer);
    unmountTimer = setTimeout(finishLeave, leaveDuration());
}

function finishLeave() {
    const active = document.activeElement;
    // Focus still inside the dialog is about to be removed with it.
    const focusLost = !active || active === document.body || !active.isConnected || Boolean(root.value?.contains(active));

    mounted.value = false;
    resizeObserver?.disconnect();
    trackViewport(false);
    unlockScroll();

    // Back to the trigger, unless the page already moved focus elsewhere.
    if (focusLost && opener?.isConnected) {
        opener.focus({ preventScroll: true });
    }

    opener = null;
    emit('after-leave');
}

onBeforeUnmount(() => {
    clearTimeout(unmountTimer);

    if (mounted.value) {
        removeFromStack();
        resizeObserver?.disconnect();
        trackViewport(false);
        unlockScroll();
    }
});

/*
| Closing requests: Escape, backdrop, the close button and Cancel buttons
| (via the footer slot's requestClose) all come through here.
*/

function requestClose() {
    if (props.busy || state.value !== 'open') {
        return;
    }

    if (props.dirty && !confirmingDiscard.value) {
        confirmingDiscard.value = true;
        nextTick(() => keepEditingButton.value?.focus());

        return;
    }

    emit('close');
}

function keepEditing() {
    confirmingDiscard.value = false;
    nextTick(focusInitial);
}

function discard() {
    confirmingDiscard.value = false;
    emit('close');
}

function onBackdropPointerdown(event) {
    backdropPressed = event.target === event.currentTarget;
}

function onBackdropClick(event) {
    // Only a press that started and ended on the backdrop: a text
    // selection dragged out of the dialog doesn't close it.
    if (backdropPressed && event.target === event.currentTarget && props.dismissible) {
        requestClose();
    }

    backdropPressed = false;
}

/*
| Keyboard: Escape and the focus trap, for the topmost dialog only.
*/

function isTop() {
    return openStack[openStack.length - 1] === self;
}

function addToStack() {
    if (!openStack.includes(self)) {
        openStack.push(self);
    }

    document.addEventListener('keydown', onKeydown);
    document.addEventListener('focusin', onFocusin);
}

function removeFromStack() {
    const index = openStack.indexOf(self);

    if (index !== -1) {
        openStack.splice(index, 1);
    }

    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('focusin', onFocusin);
}

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
].join(',');

function focusables() {
    return [...(panel.value?.querySelectorAll(FOCUSABLE) || [])]
        .filter(el => el.tabIndex >= 0 && el.getClientRects().length > 0);
}

function focusInitial() {
    if (!panel.value) {
        return;
    }

    const target = (props.initialFocus && panel.value.querySelector(props.initialFocus))
        || body.value?.querySelector('[autofocus]')
        || panel.value;

    target.focus({ preventScroll: target === panel.value });
}

function onKeydown(event) {
    if (!isTop() || state.value !== 'open') {
        return;
    }

    if (event.key === 'Escape' && !event.isComposing) {
        event.preventDefault();

        if (confirmingDiscard.value) {
            keepEditing();
        } else {
            requestClose();
        }

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const items = focusables();

    if (items.length === 0) {
        event.preventDefault();
        panel.value?.focus();

        return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || active === panel.value)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

function onFocusin(event) {
    // Focus that escapes (a screen reader jump, a stray script) comes back.
    if (isTop() && state.value === 'open' && panel.value && !panel.value.contains(event.target)) {
        focusInitial();
    }
}

/*
| Layout: header / footer dividers appear only while the body scrolls under
| them, and the sheet follows the visual viewport so the on-screen keyboard
| never covers the focused field or the actions.
*/

function updateScrollEdges() {
    const el = body.value;

    if (!el) {
        return;
    }

    scrolledTop.value = el.scrollTop > 2;
    scrolledBottom.value = el.scrollTop + el.clientHeight < el.scrollHeight - 2;
}

function observeBody() {
    updateScrollEdges();

    if (typeof ResizeObserver !== 'undefined' && body.value) {
        resizeObserver = new ResizeObserver(updateScrollEdges);
        resizeObserver.observe(body.value);
        [...body.value.children].forEach(child => resizeObserver.observe(child));
    }
}

function syncViewport() {
    const viewport = window.visualViewport;

    if (!viewport || !root.value) {
        return;
    }

    root.value.style.setProperty('--nx-vv-h', `${viewport.height}px`);
    root.value.style.setProperty('--nx-vv-top', `${viewport.offsetTop}px`);
}

function trackViewport(on) {
    const viewport = window.visualViewport;

    if (!viewport) {
        return;
    }

    const method = on ? 'addEventListener' : 'removeEventListener';

    viewport[method]('resize', syncViewport);
    viewport[method]('scroll', syncViewport);

    if (on) {
        nextTick(syncViewport);
    }
}

defineExpose({ requestClose });
</script>

<template>
    <!-- Moved to the end of #buyer-app: out of any stacking context on the
         page (the sticky header stays under the backdrop) while keeping the
         app's #buyer-app styles. -->
    <Teleport to="#buyer-app">
        <div
            v-if="mounted"
            ref="root"
            class="nx-modal-root"
            :data-state="state"
        >
            <div
                class="nx-modal-backdrop"
                aria-hidden="true"
            ></div>

            <div
                class="nx-modal-frame"
                @pointerdown="onBackdropPointerdown"
                @click="onBackdropClick"
            >
                <section
                    ref="panel"
                    class="nx-modal"
                    :class="[`is-${size}`, panelClass, { 'is-busy': busy }]"
                    :role="role"
                    aria-modal="true"
                    :aria-labelledby="hasHeader ? titleId : undefined"
                    :aria-describedby="description || slots.description ? descriptionId : undefined"
                    :aria-busy="busy ? 'true' : undefined"
                    tabindex="-1"
                >
                    <header
                        v-if="hasHeader"
                        class="nx-modal-head"
                        :class="{ 'is-divided': scrolledTop }"
                    >
                        <div class="nx-modal-heading">
                            <p
                                v-if="eyebrow"
                                class="nx-modal-eyebrow"
                            >{{ eyebrow }}</p>
                            <h2
                                :id="titleId"
                                class="nx-modal-title"
                            >
                                <slot
                                    name="header"
                                    :title-id="titleId"
                                >{{ title }}</slot>
                            </h2>
                            <p
                                v-if="description || slots.description"
                                :id="descriptionId"
                                class="nx-modal-description"
                            >
                                <slot name="description">{{ description }}</slot>
                            </p>
                        </div>
                        <button
                            v-if="showClose"
                            type="button"
                            class="icon-btn nx-modal-close"
                            :aria-label="closeLabel"
                            :disabled="busy"
                            @click="requestClose"
                        >
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </header>

                    <div
                        v-if="slots.default"
                        ref="body"
                        class="nx-modal-body"
                        @scroll.passive="updateScrollEdges"
                    >
                        <slot :request-close="requestClose"></slot>
                    </div>

                    <footer
                        v-if="confirmingDiscard"
                        class="nx-modal-foot nx-modal-discard is-divided"
                        role="group"
                        aria-labelledby="nx-modal-discard-text"
                    >
                        <p
                            id="nx-modal-discard-text"
                            class="nx-modal-discard-text"
                            role="alert"
                        >
                            <strong>Discard your changes?</strong>
                            <span>What you entered here won’t be saved.</span>
                        </p>
                        <div class="nx-modal-actions">
                            <button
                                ref="keepEditingButton"
                                type="button"
                                class="btn btn-secondary"
                                @click="keepEditing"
                            >
                                Keep editing
                            </button>
                            <button
                                type="button"
                                class="btn btn-danger"
                                @click="discard"
                            >
                                {{ discardLabel }}
                            </button>
                        </div>
                    </footer>

                    <footer
                        v-else-if="slots.footer"
                        class="nx-modal-foot"
                        :class="{ 'is-divided': scrolledBottom }"
                    >
                        <div class="nx-modal-actions">
                            <slot
                                name="footer"
                                :request-close="requestClose"
                            ></slot>
                        </div>
                    </footer>
                </section>
            </div>
        </div>
    </Teleport>
</template>
