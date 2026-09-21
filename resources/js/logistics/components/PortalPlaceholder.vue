<!-- resources/js/logistics/components/PortalPlaceholder.vue
     Fallback for any nav tab key that isn't wired to a real page in
     LogisticsLayout's TAB_COMPONENTS map — every current tab (including
     Messages and Reports, both formerly placeholders here) now has a
     real implementation, so this only renders for an unrecognised or
     future tab key, e.g. a stale bookmark. -->
<template>
    <div class="logistics-page">
        <header class="page-header">
            <div>
                <h2 class="page-title">{{ content.title }}</h2>
                <p class="page-subtitle">{{ content.description }}</p>
            </div>
        </header>

        <div class="card p-6">
            <div class="empty-state">
                <NavIcon :name="content.icon" :size="32" />
                <strong>{{ content.heading }}</strong>
                <p>{{ content.note }}</p>
                <button
                    v-if="content.action"
                    type="button"
                    class="btn-outline"
                    @click="emit('open-section', content.action.tab)"
                >
                    {{ content.action.label }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import NavIcon from './NavIcon.vue';

defineProps({ section: { type: String, required: true } });
const emit = defineEmits(['open-section']);

const content = {
    title: 'Not available',
    description: '',
    icon: 'alert',
    heading: 'This section isn’t available',
    note: 'It may have moved. Try the Dashboard, or use the sidebar to find what you’re looking for.',
    action: { tab: 'dashboard', label: 'Go to Dashboard' },
};
</script>
