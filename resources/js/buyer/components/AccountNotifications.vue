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
| Honest about today: BuyTheWay currently emails buyers only about their
| account. Order-update and promotional emails aren't sent yet; the saved
| choices are what they'll follow when they start. There are no push or
| SMS notifications, so no controls for them.
|
*/
import { onMounted, reactive, ref } from 'vue';
import { useAccountSettings } from '../composables/useAccountSettings';

const { preferences, preferencesError, loadPreferences, savePreferences } = useAccountSettings();

const loading = ref(!preferences.value);
const saving = reactive({});
const status = reactive({});

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

onMounted(() => load());

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
            <p class="acc-lede">Choose which optional emails you&rsquo;d like. They go to your sign-in email.</p>
        </header>

        <div class="acc-note">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8v.01" /></svg>
            <p>Right now BuyTheWay only emails you about your account. Order and promotional emails haven&rsquo;t started yet; when they do, they&rsquo;ll follow the choices you save here.</p>
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
