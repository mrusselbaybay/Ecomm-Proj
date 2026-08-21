<template>
    <div class="space-y-6">
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="stat in stats"
                :key="stat.label"
                class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <p
                    class="text-xs font-semibold tracking-wide text-slate-500 uppercase"
                >
                    {{ stat.label }}
                </p>
                <p class="mt-2 text-3xl font-bold text-slate-900">
                    {{ stat.value }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ stat.delta }}
                </p>
            </article>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="font-bold text-slate-900">Recent activity</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Updates and reminders for the admin team.
                </p>
            </div>

            <div v-if="notifications.length" class="divide-y divide-slate-100">
                <div
                    v-for="(notification, index) in notifications"
                    :key="`${notification.text}-${index}`"
                    class="flex items-start justify-between gap-4 px-5 py-4"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1.5 h-2 w-2 rounded-full bg-teal-500"
                        ></span>
                        <p class="text-sm text-slate-700">
                            {{ notification.text }}
                        </p>
                    </div>
                    <time class="shrink-0 text-xs text-slate-400">
                        {{ notification.time }}
                    </time>
                </div>
            </div>

            <p v-else class="px-5 py-8 text-center text-sm text-slate-500">
                No recent activity.
            </p>
        </section>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { stats, notifications, loadStats, loadNotifications } = useAdmin();

onMounted(() => {
    loadStats();
    loadNotifications();
});
</script>
