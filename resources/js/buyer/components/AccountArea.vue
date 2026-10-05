<script setup>
/*
|--------------------------------------------------------------------------
| AccountArea — the account settings sections
|--------------------------------------------------------------------------
|
| The current settings section, rendered inside AccountLayout (which owns
| Header, AccountNav and the page frame for every account page). Each
| section is kept alive, so forms keep their input and nothing refetches
| on the way back.
|
| While any section has unsaved edits, closing or reloading the tab asks
| first; in-app navigation is guarded by Dashboard (useAccountNav).
|
*/
import { computed, onMounted, onUnmounted } from 'vue';
import AccountProfile from './AccountProfile.vue';
import AccountAddresses from './AccountAddresses.vue';
import AccountPayments from './AccountPayments.vue';
import AccountSecurity from './AccountSecurity.vue';
import AccountNotifications from './AccountNotifications.vue';
import AccountPrivacy from './AccountPrivacy.vue';
import AccountFollowing from './AccountFollowing.vue';
import AccountHelp from './AccountHelp.vue';
import { hasUnsavedChanges } from '../composables/useAccountNav';
import { useBuyerSession } from '../composables/useBuyerSession';

const props = defineProps({
    section: {
        type: String,
        default: 'profile'
    }
});

const emit = defineEmits(['select-product', 'open-store']);

const { buyerProfile, isLoadingSession } = useBuyerSession();

const SECTIONS = {
    profile: AccountProfile,
    addresses: AccountAddresses,
    payments: AccountPayments,
    security: AccountSecurity,
    notifications: AccountNotifications,
    privacy: AccountPrivacy,
    following: AccountFollowing,
    help: AccountHelp
};

const current = computed(() => SECTIONS[props.section] || AccountProfile);

// Help & Support is useful before signing in; everything else is personal.
const needsSignIn = computed(() => props.section !== 'help' && !buyerProfile.value);

function handleBeforeUnload(event) {
    if (hasUnsavedChanges()) {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(() => window.addEventListener('beforeunload', handleBeforeUnload));
onUnmounted(() => window.removeEventListener('beforeunload', handleBeforeUnload));
</script>

<template>

    <div class="acc-area">
        <div
            v-if="isLoadingSession && !buyerProfile && section !== 'help'"
            class="acc-loading"
            aria-busy="true"
        >
            <span class="skeleton is-line acc-sk-title"></span>
            <span class="skeleton is-line"></span>
            <span class="skeleton is-line"></span>
        </div>

        <section
            v-else-if="needsSignIn"
            class="acc-signin"
            aria-labelledby="acc-signin-title"
        >
            <h1
                id="acc-signin-title"
                class="acc-title"
            >
                Sign in to manage your account
            </h1>
            <p class="acc-lede">Your profile, addresses, orders and settings are here once you&rsquo;re signed in.</p>
            <a
                href="/login"
                class="btn btn-primary"
            >
                Sign in
            </a>
        </section>

        <Transition
            v-else
            name="acc-swap"
            mode="out-in"
        >
            <KeepAlive>
                <component
                    :is="current"
                    :key="section"
                    @select-product="emit('select-product', $event)"
                    @open-store="emit('open-store', $event)"
                />
            </KeepAlive>
        </Transition>
    </div>

</template>
