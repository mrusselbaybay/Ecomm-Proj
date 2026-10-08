<script setup>
/*
|--------------------------------------------------------------------------
| ConfirmDialog — accessible replacement for window.confirm()
|--------------------------------------------------------------------------
|
| Mounted once in Dashboard.vue. Driven entirely by useConfirm.js:
| a caller does `if (await confirm({ ... })) { ... }`.
|
|   - a compact BaseModal with role="alertdialog", named by its title and
|     described by its message
|   - Escape and backdrop click both resolve as "cancel"; focus returns to
|     whatever opened it once the dialog has faded out
|   - tone 'danger' paints the confirm button red and puts first focus on
|     Cancel, so a stray Enter never deletes anything
|
*/
import { computed, ref, watch } from 'vue';
import { useConfirm } from '../composables/useConfirm';
import BaseModal from './BaseModal.vue';

const { confirmState, accept, cancel } = useConfirm();

// The last request stays rendered while the dialog fades out, instead of
// the text blanking mid-exit.
const shown = ref(null);

watch(confirmState, (state) => {
    if (state) {
        shown.value = state;
    }
}, { immediate: true });

const isDanger = computed(() => shown.value?.tone === 'danger');
</script>

<template>
    <BaseModal
        :open="Boolean(confirmState)"
        size="sm"
        role="alertdialog"
        :title="shown?.title || ''"
        :description="shown?.message || ''"
        :show-close="false"
        :initial-focus="isDanger ? '[data-confirm-cancel]' : '[data-confirm-accept]'"
        @close="cancel"
        @after-leave="shown = null"
    >
        <template #footer>
            <button
                type="button"
                class="btn btn-secondary"
                data-confirm-cancel
                @click="cancel"
            >
                {{ shown?.cancelLabel }}
            </button>
            <button
                type="button"
                class="btn"
                :class="isDanger ? 'btn-danger' : 'btn-primary'"
                data-confirm-accept
                @click="accept"
            >
                {{ shown?.confirmLabel }}
            </button>
        </template>
    </BaseModal>
</template>
