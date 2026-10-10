<script setup>
/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Optional email choices, saved to /api/buyer/account/preferences the
| moment a switch changes (rolled back if the save fails). Essential
| account and security emails are listed for clarity but can't be turned
| off.
|
| Report outcome email updates follow the buyer's preference below.
| Order-update and promotional email preferences are saved for their
| eventual rollout. The buyer app currently has no push or SMS channel.
|
*/
import { onMounted, reactive, ref } from 'vue';
import { useAccountSettings } from '../composables/useAccountSettings';
import { buyerApi } from '../composables/useBuyerApi';

const { preferences, preferencesError, loadPreferences, savePreferences } = useAccountSettings();

const loading = ref(!preferences.value);
const saving = reactive({});
const status = reactive({});
const reportNotifications = ref([]);
const reportNotificationsLoading = ref(true);

async function loadReportNotifications() {
    reportNotificationsLoading.value = true;
    try {
        const response = await buyerApi('/buyer/report-notifications');
        reportNotifications.value = response.data || [];
    } catch {
        reportNotifications.value = [];
    } finally {
        reportNotificationsLoading.value = false;
    }
}

async function load(force = false) {
    loading.value = !preferences.value || force;

    try {
        await loadPreferences({ force });
    } catch {
        // preferencesError carries the message.
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    load();
    loadReportNotifications();
});

const OPTIONAL = [
    {
        key: 'order_updates_email',
        title: 'Order updates',
        body: 'Emails when an order is confirmed, shipped, delivered or cancelled.'
    },
    {
        key: 'promotions_email',
        title: 'Deals and recommendations',
        body: 'Occasional emails about sales and products you might like. Off unless you turn it on.'
    },
    {
        key: 'case_updates_email',
        title: 'Report case updates',
        body: 'An email when the Platform Admin completes a review of a report you submitted.'
    }
];

async function toggle(key) {
    if (!preferences.value || saving[key]) {
        return;
    }

    const next = !preferences.value[key];
    const previous = preferences.value[key];

    preferences.value = { ...preferences.value, [key]: next };
    saving[key] = true;
    status[key] = '';

    try {
        await savePreferences({ [key]: next });
        status[key] = 'Saved';
        setTimeout(() => {
            if (status[key] === 'Saved') {
                status[key] = '';
            }
        }, 2500);
    } catch (err) {
        preferences.value = { ...preferences.value, [key]: previous };
        status[key] = err?.message || 'Couldn’t save. Try again.';
    } finally {
        saving[key] = false;
    }
}
</script>

<template>

    <section
        class="acc-section"
        aria-labelledby="acc-notif-title"
    >
        <header class="acc-head">
            <h1
                id="acc-notif-title"
                class="acc-title"
            >
                Notifications
            </h1>
            <p class="acc-lede">View report outcomes and choose which optional emails you&rsquo;d like.</p>
        </header>

        <div class="acc-note">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8v.01" /></svg>
            <p>Report outcomes can arrive in this inbox and by email when enabled below. Order and promotional emails will follow their saved choices when those channels launch.</p>
        </div>

        <div class="acc-block">
            <h2 class="acc-subtitle">Report updates</h2>
            <p v-if="reportNotificationsLoading" class="acc-hint" aria-busy="true">Loading report updates…</p>
            <p v-else-if="!reportNotifications.length" class="acc-hint">No report outcomes yet.</p>
            <ul v-else class="acc-plain-list">
                <li v-for="notice in reportNotifications" :key="notice.id">
                    <strong>{{ notice.title }}</strong> — {{ notice.message }}
                    <small class="block">{{ new Date(notice.created_at).toLocaleString() }}</small>
                </li>
            </ul>
        </div>

        <div class="acc-block">
            <h2 class="acc-subtitle">Optional emails</h2>

            <div
                v-if="loading"
                class="acc-loading"
                aria-busy="true"
            >
                <span class="skeleton is-line"></span>
                <span class="skeleton is-line"></span>
            </div>

            <p
                v-else-if="!preferences"
                class="acc-error"
                role="alert"
            >
                {{ preferencesError || 'Could not load your preferences.' }}
                <button
                    type="button"
                    class="link-btn"
                    @click="load(true)"
                >
                    Try again
                </button>
            </p>

            <ul
                v-else
                class="acc-switch-list"
            >
                <li
                    v-for="option in OPTIONAL"
                    :key="option.key"
                    class="acc-switch-row"
                >
                    <div class="acc-switch-text">
                        <p
                            :id="`acc-sw-${option.key}`"
                            class="acc-switch-title"
                        >
                            {{ option.title }}
                        </p>
                        <p
                            :id="`acc-sw-${option.key}-desc`"
                            class="acc-hint"
                        >
                            {{ option.body }}
                        </p>
                    </div>
                    <div class="acc-switch-control">
                        <span
                            class="acc-switch-status"
                            :class="{ 'is-error': status[option.key] && status[option.key] !== 'Saved' }"
                            role="status"
                        >{{ saving[option.key] ? 'Saving…' : status[option.key] }}</span>
                        <button
                            type="button"
                            role="switch"
                            class="acc-switch"
                            :aria-checked="preferences[option.key]"
                            :aria-labelledby="`acc-sw-${option.key}`"
                            :aria-describedby="`acc-sw-${option.key}-desc`"
                            :disabled="saving[option.key]"
                            @click="toggle(option.key)"
                        >
                            <span
                                class="acc-switch-thumb"
                                aria-hidden="true"
                            ></span>
                        </button>
                    </div>
                </li>
            </ul>
        </div>

        <div class="acc-block">
            <h2 class="acc-subtitle">Always sent</h2>
            <p class="acc-hint">Needed to run your account, so they can&rsquo;t be switched off.</p>
            <ul class="acc-plain-list">
                <li>Account approval and status changes</li>
                <li>Password reset codes you request</li>
            </ul>
        </div>
    </section>

</template>
