<!-- resources/js/logistics/components/Dashboard.vue -->
<template>
    <div class="logistics-page">
        <div class="page-header">
            <div>
                <h2 class="page-title">Dashboard</h2>
                <p class="page-subtitle">
                    Overview of {{ companyName }}'s courier network.
                </p>
            </div>
        </div>

        <div v-if="loadError" class="dashboard-alert" role="alert">
            <strong>We couldn't load the latest logistics data.</strong>
            <span>{{ loadError }}</span>
            <button type="button" @click="loadDashboard">Try again</button>
        </div>

        <div class="mb-6 grid grid-cols-4 gap-4">
            <div class="stat-card accent-total">
                <div class="stat-card-top">
                    <p class="field-label">Total Couriers</p>
                    <span class="stat-icon" aria-hidden="true">
                        <svg class="icon-sm" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 17V8a1 1 0 0 1 1-1h9v10M4 17h10M4 17a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0M14 10h4l3 3v4h-2"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </span>
                </div>
                <p class="stat-total text-2xl font-bold">
                    {{ couriers.length }}
                </p>
            </div>
            <div class="stat-card accent-pending">
                <div class="stat-card-top">
                    <p class="field-label">Pending Applications</p>
                    <span class="stat-icon" aria-hidden="true">
                        <svg class="icon-sm" viewBox="0 0 24 24" fill="none">
                            <circle
                                cx="12"
                                cy="12"
                                r="8.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M12 7.5V12l3 2"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>
                    </span>
                </div>
                <p class="stat-pending text-2xl font-bold">
                    {{ pendingCount }}
                </p>
            </div>
            <div class="stat-card accent-active">
                <div class="stat-card-top">
                    <p class="field-label">Accepted</p>
                    <span class="stat-icon" aria-hidden="true">
                        <svg class="icon-sm" viewBox="0 0 24 24" fill="none">
                            <path
                                d="m5 13 4 4L19 7"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </span>
                </div>
                <p class="stat-active text-2xl font-bold">
                    {{ acceptedCount }}
                </p>
            </div>
            <div class="stat-card accent-deactivated">
                <div class="stat-card-top">
                    <p class="field-label">Rejected</p>
                    <span class="stat-icon" aria-hidden="true">
                        <svg class="icon-sm" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M6 6l12 12M18 6 6 18"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </span>
                </div>
                <p class="stat-deactivated text-2xl font-bold">
                    {{ rejectedCount }}
                </p>
            </div>
        </div>

        <div class="card p-6">
            <h3 class="section-label mb-3">Recent Applications</h3>
            <div v-if="recentApplications.length === 0" class="empty-state">
                <svg
                    class="icon-lg empty-state-icon"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-hidden="true"
                >
                    <path
                        d="M9 12h6M9 16h6M9 8h1M6 4h8l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linejoin="round"
                    />
                </svg>
                <p>No applications yet.</p>
            </div>
            <div v-else class="doc-list">
                <div
                    v-for="app in recentApplications"
                    :key="app.id"
                    class="doc-row"
                >
                    <div class="doc-info">
                        <div class="avatar" aria-hidden="true">
                            {{ initials(app) }}
                        </div>
                        <div>
                            <p class="doc-type">
                                {{ app.courier?.first_name }}
                                {{ app.courier?.last_name }}
                            </p>
                            <p class="doc-date">
                                Applied {{ formatDate(app.applied_at) }}
                            </p>
                        </div>
                    </div>
                    <span class="badge" :class="badgeClass(app.status)">{{
                        app.status
                    }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useLogistics } from '../composables/useLogistics';

const {
    companyName,
    applications,
    couriers,
    pendingCount,
    loadApplications,
    loadCouriers,
} = useLogistics();

const acceptedCount = computed(
    () => applications.value.filter((a) => a.status === 'accepted').length,
);
const rejectedCount = computed(
    () => applications.value.filter((a) => a.status === 'rejected').length,
);
const recentApplications = computed(() => applications.value.slice(0, 5));
const loadError = ref('');

function initials(app) {
    const n =
        `${app.courier?.first_name || ''} ${app.courier?.last_name || ''}`.trim();
    return (
        n
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0])
            .join('')
            .toUpperCase() || '?'
    );
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function badgeClass(status) {
    return {
        'badge-teal': status === 'accepted',
        'badge-amber': status === 'pending',
        'badge-red': status === 'rejected' || status === 'withdrawn',
    };
}

async function loadDashboard() {
    loadError.value = '';

    try {
        await Promise.all([loadApplications(), loadCouriers()]);
    } catch (error) {
        console.error('Failed to load logistics dashboard:', error);
        loadError.value =
            error.message || 'Please refresh the page and try again.';
    }
}

onMounted(loadDashboard);
</script>
