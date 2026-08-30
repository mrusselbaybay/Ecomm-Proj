<template>
    <div class="logistics-page">
        <div class="page-header">
            <div>
                <h2 class="page-title">{{ content.title }}</h2>
                <p class="page-subtitle">{{ content.description }}</p>
            </div>
        </div>
        <div class="card placeholder-card">
            <span class="placeholder-icon">{{ content.icon }}</span>
            <h3>{{ content.heading }}</h3>
            <p>{{ content.note }}</p>
        </div>
    </div>
</template>
<script setup>
import { computed } from 'vue';
const props = defineProps({ section: { type: String, required: true } });
const sections = {
    messages: {
        title: 'Messages',
        description: 'Coordinate with riders and marketplace staff.',
        icon: '◇',
        heading: 'Messaging workspace',
        note: 'Conversations and delivery escalation threads will appear here.',
    },
    reports: {
        title: 'Reports',
        description:
            'Review parcel transactions, rider activity, and commissions.',
        icon: '▥',
        heading: 'Operations reports',
        note: 'Date filters, transaction summaries, commission totals, and exports will appear here.',
    },
    account: {
        title: 'Account Settings',
        description: 'Manage your logistics company and account.',
        icon: '⚙',
        heading: 'Company profile',
        note: 'Company details, service areas, security, and account controls will appear here.',
    },
};
const content = computed(() => sections[props.section] || sections.account);
</script>
