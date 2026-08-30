<template>
    <div class="space-y-6">
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <template v-if="loading && !hasLoadedOnce">
                <article
                    v-for="n in 4"
                    :key="n"
                    class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    aria-hidden="true"
                >
                    <div class="skeleton skeleton-text" style="width: 55%; height: 0.6rem"></div>
                    <div class="skeleton skeleton-text" style="width: 40%; height: 1.9rem; margin-top: 0.6rem"></div>
                    <div class="skeleton skeleton-text" style="width: 70%; height: 0.6rem; margin-top: 0.55rem"></div>
                </article>
            </template>
            <template v-else>
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
            </template>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="font-bold text-slate-900">Recent activity</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Updates and reminders for the admin team.
                </p>
            </div>

            <div v-if="loading && !hasLoadedOnce" class="divide-y divide-slate-100" aria-hidden="true">
                <div
                    v-for="n in 4"
                    :key="n"
                    class="flex items-start justify-between gap-4 px-5 py-4"
                >
                    <div class="flex items-start gap-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-200"></span>
                        <div class="skeleton skeleton-text" style="width: 16rem"></div>
                    </div>
                    <div class="skeleton skeleton-text" style="width: 3rem"></div>
                </div>
            </div>

            <div v-else-if="notifications.length" class="divide-y divide-slate-100">
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
import { onActivated, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { stats, notifications, loadStats, loadNotifications } = useAdmin();

const loading = ref(true);
// Sections stay kept-alive across tab switches, so onActivated below reruns
// on every revisit — not just the first. Without this flag, "loading" would
// flip true again on each revisit and the skeleton would wipe out data that
// was already loaded and on screen a moment earlier; gating the skeleton on
// "loading && !hasLoadedOnce" instead lets a background refresh update the
// numbers in place while the previous values stay visible until it resolves.
const hasLoadedOnce = ref(false);

// This component is kept alive by AdminLayout's <KeepAlive>, so
// onActivated (not onMounted) is what fires both on first visit and every
// time the admin switches back to this tab — refreshing stats/notifications
// each time instead of showing whatever was current when they left.
onActivated(async () => {
    loading.value = true;

    try {
        await Promise.all([loadStats(), loadNotifications()]);
    } finally {
        loading.value = false;
        hasLoadedOnce.value = true;
    }
});
</script>
