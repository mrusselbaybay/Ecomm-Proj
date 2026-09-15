<!-- resources/js/logistics/components/SearchableSelect.vue
     A typeable dropdown for long option lists (province/municipality/
     barangay pickers can run into the hundreds of entries) — behaves like
     a <select> but lets the user filter by typing instead of scrolling.
     Only a value from `options` can ever be committed; typed text that
     doesn't match anything is discarded on blur rather than saved as
     free text. -->
<template>
    <div
        ref="rootEl"
        class="searchable-select"
        :class="{ 'is-open': open && !disabled, 'is-disabled': disabled }"
    >
        <input
            ref="inputEl"
            type="text"
            class="field-input searchable-select-input"
            :value="displayText"
            :placeholder="loading ? loadingText : placeholder"
            :disabled="disabled"
            autocomplete="off"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open"
            @focus="onFocus"
            @input="onInput"
            @keydown="onKeydown"
            @blur="onBlur"
        />
        <ul v-if="open && !disabled" class="searchable-select-list" role="listbox">
            <li v-if="loading" class="searchable-select-hint">
                {{ loadingText }}
            </li>
            <template v-else>
                <li
                    v-for="(opt, idx) in filteredOptions"
                    :key="opt.value"
                    class="searchable-select-option"
                    :class="{ 'is-active': idx === activeIndex }"
                    role="option"
                    @mousedown.prevent="selectOption(opt)"
                >
                    {{ opt.label }}
                </li>
                <li
                    v-if="filteredOptions.length === 0"
                    class="searchable-select-hint"
                >
                    {{ query.trim() ? 'No matches' : emptyText }}
                </li>
            </template>
        </ul>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    // [{ value, label }]
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Select…' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    loadingText: { type: String, default: 'Loading…' },
    emptyText: { type: String, default: 'No options' },
});
const emit = defineEmits(['update:modelValue', 'select']);

const open = ref(false);
const typing = ref(false);
const query = ref('');
const activeIndex = ref(-1);
const inputEl = ref(null);

const selectedOption = computed(
    () => props.options.find((opt) => opt.value === props.modelValue) || null,
);

const displayText = computed(() =>
    typing.value ? query.value : selectedOption.value?.label || '',
);

const filteredOptions = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (!typing.value || !needle) {
        return props.options;
    }

    return props.options.filter((opt) =>
        opt.label.toLowerCase().includes(needle),
    );
});

function onFocus() {
    typing.value = true;
    query.value = '';
    open.value = true;
    activeIndex.value = -1;
}

function onInput(event) {
    typing.value = true;
    query.value = event.target.value;
    open.value = true;
    activeIndex.value = -1;
}

function selectOption(opt) {
    emit('update:modelValue', opt.value);
    emit('select', opt);
    typing.value = false;
    query.value = '';
    open.value = false;
    activeIndex.value = -1;
}

function onBlur() {
    // A click on an option fires @mousedown.prevent (which keeps focus),
    // but give it a tick either way before discarding unmatched typed text.
    setTimeout(() => {
        typing.value = false;
        query.value = '';
        open.value = false;
        activeIndex.value = -1;
    }, 150);
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        typing.value = false;
        query.value = '';
        open.value = false;
        inputEl.value?.blur();

        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        activeIndex.value = Math.min(
            activeIndex.value + 1,
            filteredOptions.value.length - 1,
        );

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        const opt =
            filteredOptions.value[activeIndex.value] ??
            filteredOptions.value[0];

        if (opt) {
            selectOption(opt);
        }
    }
}
</script>
