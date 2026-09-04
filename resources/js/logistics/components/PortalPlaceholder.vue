<!-- resources/js/logistics/components/PortalPlaceholder.vue
     Honest empty state for the two portal sections that have no backend
     behind them yet. It says plainly that the section isn't available and
     points at the tab that does the job today, rather than implying a
     feature exists and is merely empty. -->
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
import { computed } from 'vue';
import NavIcon from './NavIcon.vue';

const props = defineProps({ section: { type: String, required: true } });
const emit = defineEmits(['open-section']);

const SECTIONS = {
    messages: {
        title: 'Messages',
        description: 'Coordinate with riders and marketplace staff.',
        icon: 'messages',
        heading: 'Messaging isn’t available yet',
        note: 'Rider coordination and delivery escalation threads will live here. For now, rider contact numbers are on each rider’s record under Riders & Areas.',
        action: { tab: 'couriers', label: 'Go to Riders & Areas' },
    },
    reports: {
        title: 'Reports',
        description:
            'Parcel transactions, rider activity, and commission totals.',
        icon: 'reports',
        heading: 'Reporting isn’t available yet',
        note: 'Date-filtered transaction summaries, commission totals, and exports will live here. Live parcel counts are on the Dashboard in the meantime.',
        action: { tab: 'dashboard', label: 'Go to Dashboard' },
    },
};

const content = computed(() => SECTIONS[props.section] || SECTIONS.reports);
</script>
