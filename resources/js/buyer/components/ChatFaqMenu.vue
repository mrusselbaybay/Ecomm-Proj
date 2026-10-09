<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    questions: { type: Array, default: () => [] },
});

const emit = defineEmits(['select']);
const openSection = ref('general');
const sections = computed(() => [
    {
        key: 'general',
        title: 'General Questions',
        questions: props.questions.filter(question => question.contextType === 'general'),
    },
    {
        key: 'orders',
        title: 'Order Questions',
        questions: props.questions.filter(question => question.contextType === 'order'),
    },
]);

function toggleSection(key) {
    openSection.value = openSection.value === key ? null : key;
}
</script>

<template>
    <div class="overflow-hidden border-t border-slate-100 bg-white">
        <section v-for="section in sections" :key="section.key" class="border-b border-slate-100 last:border-b-0">
            <button
                type="button"
                class="flex min-h-12 w-full items-center justify-between gap-3 px-4 text-left transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[#0d9488]"
                :aria-expanded="openSection === section.key"
                @click="toggleSection(section.key)"
            >
                <span class="text-[13px] font-semibold text-slate-800">{{ section.title }}</span>
                <svg
                    viewBox="0 0 20 20"
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="shrink-0 text-slate-400 transition-transform duration-200"
                    :class="openSection === section.key ? 'rotate-180' : ''"
                    aria-hidden="true"
                ><path d="m5 7.5 5 5 5-5" /></svg>
            </button>

            <div v-if="openSection === section.key" class="px-3 pb-2">
                <template v-if="section.questions.length">
                    <button
                        v-for="question in section.questions"
                        :key="question.key"
                        type="button"
                        class="group flex min-h-11 w-full items-center justify-between gap-3 border-t border-slate-100 px-2 py-2 text-left text-[12px] leading-5 text-slate-600 transition-colors hover:text-[#0d766f] focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-[#0d9488]"
                        @click="emit('select', question)"
                    >
                        <span>{{ question.question }}</span>
                        <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-[#0d9488]" aria-hidden="true"><path d="m6 3 5 5-5 5" /></svg>
                    </button>
                </template>
                <p v-else class="px-2 py-3 text-[12px] text-slate-400">Loading questions…</p>
            </div>
        </section>
    </div>
</template>
