<script setup>
/*
|--------------------------------------------------------------------------
| Login & Security
|--------------------------------------------------------------------------
|
| Everything here goes to Supabase Auth, the provider that owns sign-in:
| - verification status from the live auth user (email_confirmed_at);
| - password change: the current password is re-checked first
|   (signInWithPassword), then updateUser({ password });
| - "sign out other devices": signOut({ scope: 'others' }).
|
| Not offered, because the project doesn't support them: two-step
| verification, a per-device session list, phone verification, and
| changing the sign-in email (profiles.email isn't kept in step with the
| auth email yet).
|
*/
import { computed, onActivated, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { getSupabase, useBuyerSession } from '../composables/useBuyerSession';
import { registerUnsavedGuard } from '../composables/useAccountNav';
import { useConfirm } from '../composables/useConfirm';
import { useToasts } from '../composables/useToasts';

const { buyerProfile } = useBuyerSession();
const { confirm } = useConfirm();
const toasts = useToasts();

/*
|--------------------------------------------------------------------------
| Account status
|--------------------------------------------------------------------------
*/

const authUser = ref(null);
const authError = ref('');

async function loadAuthUser() {
    authError.value = '';

    try {
        const { data, error } = await getSupabase().auth.getUser();

        if (error) {
            throw error;
        }

        authUser.value = data.user;
    } catch (err) {
        authError.value = err?.message || 'Could not check your sign-in details.';
    }
}

onMounted(loadAuthUser);
onActivated(loadAuthUser);

const signInEmail = computed(() => authUser.value?.email || buyerProfile.value?.email || '');
const emailVerified = computed(() => Boolean(authUser.value?.email_confirmed_at));

function formatDateTime(iso) {
    const date = iso ? new Date(iso) : null;

    return date && !Number.isNaN(date.getTime())
        ? date.toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
        : '';
}

/*
|--------------------------------------------------------------------------
| Password
|--------------------------------------------------------------------------
*/

const pw = reactive({ current: '', next: '', confirm: '' });
const show = reactive({ current: false, next: false, confirm: false });
const pwErrors = ref({});
const pwFormError = ref('');
const pwSaving = ref(false);
const pwOpen = ref(false);

const rules = computed(() => [
    { id: 'length', label: 'At least 8 characters', met: pw.next.length >= 8 },
    { id: 'letter', label: 'A letter', met: /[A-Za-z]/.test(pw.next) },
    { id: 'number', label: 'A number', met: /\d/.test(pw.next) }
]);

const pwDirty = computed(() => pwOpen.value && Boolean(pw.current || pw.next || pw.confirm));

function resetPassword() {
    pw.current = '';
    pw.next = '';
    pw.confirm = '';
    show.current = show.next = show.confirm = false;
    pwErrors.value = {};
    pwFormError.value = '';
    pwOpen.value = false;
}

const unregister = registerUnsavedGuard({ label: 'Login & Security', isDirty: () => pwDirty.value, discard: resetPassword });

onBeforeUnmount(unregister);

function validatePassword() {
    const next = {};

    if (!pw.current) {
        next.current = 'Enter your current password.';
    }

    if (!rules.value.every(rule => rule.met)) {
        next.next = 'Use at least 8 characters, with a letter and a number.';
    } else if (pw.next === pw.current) {
        next.next = 'Choose a password different from your current one.';
    }

    if (pw.confirm !== pw.next) {
        next.confirm = 'The passwords don’t match.';
    }

    pwErrors.value = next;

    return Object.keys(next).length === 0;
}

async function changePassword() {
    pwFormError.value = '';

    if (!validatePassword() || pwSaving.value) {
        return;
    }

    pwSaving.value = true;

    try {
        const supabase = getSupabase();
        const email = signInEmail.value;

        // Re-authenticate: proves it's really the account holder.
        const { error: reauthError } = await supabase.auth.signInWithPassword({ email, password: pw.current });

        if (reauthError) {
            pwErrors.value = { current: 'That isn’t your current password.' };

            return;
        }

        const { error } = await supabase.auth.updateUser({ password: pw.next });

        if (error) {
            throw error;
        }

        resetPassword();
        toasts.success('Password changed.');
    } catch (err) {
        pwFormError.value = err?.message || 'Could not change your password. Please try again.';
    } finally {
        pwSaving.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Other sessions
|--------------------------------------------------------------------------
*/

const signingOutOthers = ref(false);

async function signOutOthers() {
    const ok = await confirm({
        title: 'Sign out of other devices?',
        message: 'Any other browser or phone signed in to this account will need to sign in again. You stay signed in here.',
        confirmLabel: 'Sign out others'
    });

    if (!ok) {
        return;
    }

    signingOutOthers.value = true;

    try {
        const { error } = await getSupabase().auth.signOut({ scope: 'others' });

        if (error) {
            throw error;
        }

        toasts.success('Signed out of your other devices.');
    } catch (err) {
        toasts.error(err?.message || 'Could not sign out your other devices.');
    } finally {
        signingOutOthers.value = false;
    }
}
</script>

<template>

    <section
        class="acc-section"
        aria-labelledby="acc-security-title"
    >
        <header class="acc-head">
            <h1
                id="acc-security-title"
                class="acc-title"
            >
                Login &amp; Security
            </h1>
            <p class="acc-lede">How you sign in, and keeping your account yours.</p>
        </header>

        <dl class="acc-rows">
            <div class="acc-row">
                <dt>Sign-in email</dt>
                <dd>
                    <span class="acc-value">{{ signInEmail || '—' }}</span>
                    <span
                        v-if="authUser"
                        class="acc-status"
                        :class="emailVerified ? 'is-ok' : 'is-warn'"
                    >
                        <svg v-if="emailVerified" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                        <svg v-else viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16.5v.01" /></svg>
                        {{ emailVerified ? 'Verified' : 'Not verified' }}
                    </span>
                    <span class="acc-hint">Changing your sign-in email isn&rsquo;t available yet.</span>
                </dd>
            </div>
            <div class="acc-row">
                <dt>Mobile number</dt>
                <dd>
                    <span class="acc-value">{{ buyerProfile?.contact_no || 'Not added' }}</span>
                    <span class="acc-status is-muted">Not verified</span>
                    <span class="acc-hint">Edit it in My Profile. Phone verification isn&rsquo;t available yet.</span>
                </dd>
            </div>
            <div
                v-if="authUser?.last_sign_in_at"
                class="acc-row"
            >
                <dt>Last sign-in</dt>
                <dd><span class="acc-value">{{ formatDateTime(authUser.last_sign_in_at) }}</span></dd>
            </div>
        </dl>
        <p
            v-if="authError"
            class="acc-error"
            role="alert"
        >
            {{ authError }}
            <button
                type="button"
                class="link-btn"
                @click="loadAuthUser"
            >
                Try again
            </button>
        </p>

        <!-- Password -->
        <div class="acc-block">
            <div class="acc-block-head">
                <div>
                    <h2 class="acc-subtitle">Password</h2>
                    <p class="acc-hint">You&rsquo;ll confirm your current password before changing it.</p>
                </div>
                <button
                    v-if="!pwOpen"
                    type="button"
                    class="btn btn-secondary"
                    aria-controls="acc-password-form"
                    :aria-expanded="pwOpen"
                    @click="pwOpen = true"
                >
                    Change password
                </button>
            </div>

            <Transition name="acc-expand">
                <form
                    v-if="pwOpen"
                    id="acc-password-form"
                    class="acc-form acc-panel"
                    novalidate
                    @submit.prevent="changePassword"
                >
                    <!-- Username hint for password managers -->
                    <input
                        type="email"
                        class="sr-only"
                        autocomplete="username"
                        :value="signInEmail"
                        tabindex="-1"
                        aria-hidden="true"
                        readonly
                    >

                    <div
                        v-for="field in [
                            { key: 'current', label: 'Current password', auto: 'current-password' },
                            { key: 'next', label: 'New password', auto: 'new-password' },
                            { key: 'confirm', label: 'Confirm new password', auto: 'new-password' }
                        ]"
                        :key="field.key"
                        class="acc-field"
                    >
                        <label :for="`acc-pw-${field.key}`">{{ field.label }}</label>
                        <div class="acc-password">
                            <input
                                :id="`acc-pw-${field.key}`"
                                v-model="pw[field.key]"
                                :type="show[field.key] ? 'text' : 'password'"
                                :autocomplete="field.auto"
                                :aria-invalid="pwErrors[field.key] ? 'true' : undefined"
                                :aria-describedby="[pwErrors[field.key] ? `acc-pw-${field.key}-err` : null, field.key === 'next' ? 'acc-pw-rules' : null].filter(Boolean).join(' ') || undefined"
                            >
                            <button
                                type="button"
                                class="acc-password-toggle"
                                :aria-label="show[field.key] ? `Hide ${field.label.toLowerCase()}` : `Show ${field.label.toLowerCase()}`"
                                :aria-pressed="show[field.key]"
                                @click="show[field.key] = !show[field.key]"
                            >
                                <svg v-if="show[field.key]" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A10 10 0 0 1 12 5c5 0 9 4.5 10 7a13 13 0 0 1-3 4.2M6.6 6.6A13 13 0 0 0 2 12c1 2.5 5 7 10 7a9.7 9.7 0 0 0 4.2-.9" /></svg>
                                <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></svg>
                            </button>
                        </div>
                        <ul
                            v-if="field.key === 'next'"
                            id="acc-pw-rules"
                            class="acc-rules"
                        >
                            <li
                                v-for="rule in rules"
                                :key="rule.id"
                                :class="{ 'is-met': rule.met }"
                            >
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path v-if="rule.met" d="m5 12.5 4.5 4.5L19 7.5" /><circle v-else cx="12" cy="12" r="4" /></svg>
                                {{ rule.label }}<span class="sr-only">{{ rule.met ? ': done' : ': not yet' }}</span>
                            </li>
                        </ul>
                        <p
                            v-if="pwErrors[field.key]"
                            :id="`acc-pw-${field.key}-err`"
                            class="acc-error"
                        >
                            {{ pwErrors[field.key] }}
                        </p>
                    </div>

                    <p
                        v-if="pwFormError"
                        class="acc-error acc-form-error"
                        role="alert"
                    >
                        {{ pwFormError }}
                    </p>

                    <div class="acc-actions">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="pwSaving"
                        >
                            {{ pwSaving ? 'Updating…' : 'Update password' }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-ghost"
                            :disabled="pwSaving"
                            @click="resetPassword"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </Transition>
        </div>

        <!-- Sessions -->
        <div class="acc-block">
            <div class="acc-block-head">
                <div>
                    <h2 class="acc-subtitle">Other devices</h2>
                    <p class="acc-hint">Signed in somewhere you don&rsquo;t recognise? Sign out everywhere except here, then change your password.</p>
                </div>
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="signingOutOthers"
                    @click="signOutOthers"
                >
                    {{ signingOutOthers ? 'Signing out…' : 'Sign out other devices' }}
                </button>
            </div>
        </div>
    </section>

</template>
