<!-- resources/js/invite/AcceptInvite.vue
     Landing page for a logistics team invitation link. New users create
     their account here; existing users sign in (or confirm, if already
     signed in as the invited email) and are joined to the company. -->
<template>
    <main class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="flex items-center justify-center gap-2 mb-6">
                <span class="grid place-items-center w-9 h-9 rounded-lg bg-teal-700 text-white font-bold">N</span>
                <span class="font-semibold text-slate-800">BuyTheWay Logistics</span>
            </div>

            <section class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 p-6 sm:p-8">
                <!-- Loading -->
                <div v-if="state === 'loading'" class="animate-pulse space-y-4" aria-label="Loading invitation">
                    <div class="h-12 w-12 rounded-full bg-slate-200 mx-auto"></div>
                    <div class="h-5 bg-slate-200 rounded w-3/4 mx-auto"></div>
                    <div class="h-4 bg-slate-100 rounded w-1/2 mx-auto"></div>
                    <div class="h-10 bg-slate-100 rounded mt-6"></div>
                </div>

                <!-- Invalid / revoked -->
                <div v-else-if="state === 'invalid'" class="text-center">
                    <StatusIcon tone="red" icon="x" />
                    <h1 class="text-lg font-semibold text-slate-900">Invitation not found</h1>
                    <p class="mt-2 text-sm text-slate-600">{{ message || 'This link is invalid or was revoked. Ask your team admin to send a new one.' }}</p>
                    <a href="/logistics-login" class="btn-secondary mt-6">Go to sign in</a>
                </div>

                <!-- Expired -->
                <div v-else-if="state === 'expired'" class="text-center">
                    <StatusIcon tone="amber" icon="clock" />
                    <h1 class="text-lg font-semibold text-slate-900">This invitation has expired</h1>
                    <p class="mt-2 text-sm text-slate-600">
                        Your invite to <strong>{{ invite.company_name }}</strong> is no longer valid.
                    </p>
                    <p v-if="renewalSent" class="mt-6 text-sm text-teal-800 bg-teal-50 rounded-lg p-3" role="status">
                        {{ message || 'Request sent. The team will email you a new link.' }}
                    </p>
                    <button v-else type="button" class="btn-primary mt-6" :disabled="busy" @click="requestRenewal">
                        {{ busy ? 'Sending…' : 'Request a new invite' }}
                    </button>
                    <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>
                </div>

                <!-- Already used -->
                <div v-else-if="state === 'accepted'" class="text-center">
                    <StatusIcon tone="teal" icon="check" />
                    <h1 class="text-lg font-semibold text-slate-900">Invitation already accepted</h1>
                    <p class="mt-2 text-sm text-slate-600">Sign in to open the {{ invite.company_name }} portal.</p>
                    <a href="/logistics-login" class="btn-primary mt-6">Sign in</a>
                </div>

                <!-- Joined -->
                <div v-else-if="state === 'joined'" class="text-center">
                    <StatusIcon tone="teal" icon="check" />
                    <h1 class="text-lg font-semibold text-slate-900">Welcome to {{ invite.company_name }}</h1>
                    <p class="mt-2 text-sm text-slate-600">Opening your portal…</p>
                </div>

                <!-- Pending -->
                <template v-else>
                    <header class="text-center">
                        <StatusIcon tone="teal" icon="users" />
                        <p class="text-sm text-slate-500">You're invited to join</p>
                        <h1 class="text-xl font-semibold text-slate-900">{{ invite.company_name }}</h1>
                        <span class="inline-flex items-center mt-3 rounded-full bg-teal-50 px-3 py-1 text-xs font-medium text-teal-800 ring-1 ring-teal-200">
                            {{ roleLabel }} · {{ ROLE_HINTS[invite.role] }}
                        </span>
                    </header>

                    <!-- Signed in as the invited email: one click. -->
                    <div v-if="sessionEmail === invite.email" class="mt-6">
                        <p class="text-sm text-slate-600 text-center">Signed in as <strong>{{ sessionEmail }}</strong></p>
                        <button type="button" class="btn-primary mt-4" :disabled="busy" @click="acceptExisting">
                            {{ busy ? 'Joining…' : `Join ${invite.company_name}` }}
                        </button>
                    </div>

                    <!-- Existing account: sign in. -->
                    <form v-else-if="invite.account_exists" class="mt-6 space-y-4" @submit.prevent="signInAndAccept">
                        <p v-if="sessionEmail" class="text-xs text-amber-800 bg-amber-50 rounded-lg p-3">
                            You're signed in as {{ sessionEmail }}. Signing in below switches to the invited account.
                        </p>
                        <Field label="Email">
                            <input :value="invite.email" type="email" class="input bg-slate-50" readonly />
                        </Field>
                        <Field label="Password">
                            <input v-model="form.password" type="password" class="input" autocomplete="current-password" required />
                        </Field>
                        <button type="submit" class="btn-primary" :disabled="busy">
                            {{ busy ? 'Signing in…' : 'Sign in & join' }}
                        </button>
                        <a href="/logistics-login" class="block text-center text-xs text-slate-500 hover:text-teal-700">Forgot password? Reset it from the sign-in page</a>
                    </form>

                    <!-- New account: Personal → Address → Security. -->
                    <form v-else class="mt-6" novalidate @submit.prevent="nextStep">
                        <ol class="flex items-start gap-2 mb-6" aria-label="Sign-up progress">
                            <li v-for="(label, i) in STEPS" :key="label" class="flex-1" :aria-current="i === step ? 'step' : undefined">
                                <div class="h-1.5 rounded-full" :class="i <= step ? 'bg-teal-600' : 'bg-slate-200'"></div>
                                <span class="block mt-1.5 text-xs" :class="i === step ? 'font-semibold text-teal-800' : 'text-slate-500'">
                                    {{ i + 1 }}. {{ label }}
                                </span>
                            </li>
                        </ol>

                        <!-- Personal information -->
                        <div v-show="step === 0" class="space-y-4">
                            <Field label="Email">
                                <input :value="invite.email" type="email" class="input bg-slate-50" readonly />
                            </Field>
                            <div class="grid grid-cols-[1fr_5rem] gap-3">
                                <Field label="First name" :error="fieldErrors.first_name">
                                    <input v-model.trim="form.first_name" class="input" autocomplete="given-name" />
                                </Field>
                                <Field label="M.I." :error="fieldErrors.middle_initial">
                                    <input v-model.trim="form.middle_initial" class="input text-center uppercase" maxlength="1" />
                                </Field>
                            </div>
                            <Field label="Last name" :error="fieldErrors.last_name">
                                <input v-model.trim="form.last_name" class="input" autocomplete="family-name" />
                            </Field>
                            <Field label="Birthdate" :error="fieldErrors.birthday">
                                <input v-model="form.birthday" type="date" class="input" :max="today" autocomplete="bday" />
                            </Field>
                            <div>
                                <span class="block text-sm font-medium text-slate-700 mb-1">Sex</span>
                                <div class="grid grid-cols-2 gap-3" role="radiogroup" aria-label="Sex">
                                    <button
                                        v-for="option in SEXES"
                                        :key="option"
                                        type="button"
                                        role="radio"
                                        :aria-checked="form.sex === option"
                                        class="min-h-11 rounded-lg border text-sm font-medium transition-colors"
                                        :class="form.sex === option ? 'border-teal-600 bg-teal-50 text-teal-800' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
                                        @click="form.sex = option"
                                    >
                                        {{ option }}
                                    </button>
                                </div>
                                <span v-if="fieldErrors.sex" class="block mt-1 text-xs text-red-600">{{ fieldErrors.sex }}</span>
                            </div>
                        </div>

                        <!-- Address -->
                        <div v-show="step === 1" class="space-y-4">
                            <p v-if="addressError" class="flex items-center justify-between gap-2 text-xs text-red-600 bg-red-50 rounded-lg p-3" role="alert">
                                {{ addressError }}
                                <button type="button" class="font-semibold underline" @click="retryAddress">Retry</button>
                            </p>
                            <Field label="Province" :error="fieldErrors.province_code">
                                <select v-model="form.province_code" class="input" :disabled="!provinces.length" @change="onProvinceChange">
                                    <option value="">{{ provinces.length ? 'Select province' : 'Loading…' }}</option>
                                    <option v-for="p in provinces" :key="p.code" :value="p.code">{{ p.name }}</option>
                                </select>
                            </Field>
                            <Field label="City / Municipality" :error="fieldErrors.municipality_code">
                                <select v-model="form.municipality_code" class="input" :disabled="!municipalities.length" @change="onMunicipalityChange">
                                    <option value="">{{ form.province_code && !municipalities.length ? 'Loading…' : 'Select city / municipality' }}</option>
                                    <option v-for="m in municipalities" :key="m.code" :value="m.code">{{ m.name }}</option>
                                </select>
                            </Field>
                            <Field label="Barangay" :error="fieldErrors.barangay">
                                <select v-model="form.barangay" class="input" :disabled="!barangays.length">
                                    <option value="">{{ form.municipality_code && !barangays.length ? 'Loading…' : 'Select barangay' }}</option>
                                    <option v-for="b in barangays" :key="b.code || b.name" :value="b.name">{{ b.name }}</option>
                                </select>
                            </Field>
                            <div class="grid grid-cols-[6rem_1fr] gap-3">
                                <Field label="House no.">
                                    <input v-model.trim="form.house_no" class="input" />
                                </Field>
                                <Field label="Street">
                                    <input v-model.trim="form.street" class="input" autocomplete="address-line1" />
                                </Field>
                            </div>
                        </div>

                        <!-- Security -->
                        <div v-show="step === 2" class="space-y-4">
                            <Field label="Password" :error="fieldErrors.password" hint="At least 8 characters. Mix cases, numbers and symbols for a stronger password.">
                                <input v-model="form.password" type="password" class="input" autocomplete="new-password" />
                                <PasswordStrength :password="form.password" />
                            </Field>
                            <Field label="Confirm password" :error="fieldErrors.password_confirmation || (confirmMismatch ? 'Passwords do not match.' : '')">
                                <input v-model="form.password_confirmation" type="password" class="input" autocomplete="new-password" />
                            </Field>
                        </div>

                        <div class="flex gap-3 mt-6">
                            <button v-if="step > 0" type="button" class="btn-secondary !w-auto px-5" :disabled="busy" @click="step--">Back</button>
                            <button type="submit" class="btn-primary flex-1" :disabled="busy">
                                {{ step < STEPS.length - 1 ? 'Continue' : busy ? 'Creating account…' : 'Create account & join' }}
                            </button>
                        </div>
                    </form>

                    <p v-if="error" class="mt-4 text-sm text-red-600 bg-red-50 rounded-lg p-3" role="alert">{{ error }}</p>
                </template>
            </section>
        </div>
    </main>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref } from 'vue';
import { usePsgc } from '../logistics/composables/usePsgc';
import PasswordStrength from '../shared/PasswordStrength.vue';

const ROLE_HINTS = {
    admin: 'Full access, manages team',
    manager: 'Runs operations',
    operator: 'Day-to-day parcel work',
    viewer: 'View-only access',
};

const ICONS = {
    check: 'M5 12.5l4.5 4.5L19 7.5',
    x: 'M6 6l12 12M18 6L6 18',
    clock: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    users: 'M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19M10 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM20 19v-1.5a3.5 3.5 0 0 0-2.5-3.35M15.5 4.6a3 3 0 0 1 0 5.8',
};
const TONES = {
    teal: 'bg-teal-50 text-teal-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-600',
};

const StatusIcon = defineComponent({
    props: { tone: String, icon: String },
    setup: (props) => () =>
        h('span', { class: `grid place-items-center w-12 h-12 rounded-full mx-auto mb-3 ${TONES[props.tone]}` }, [
            h('svg', { width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.8, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' }, [
                h('path', { d: ICONS[props.icon] }),
            ]),
        ]),
});

const Field = defineComponent({
    props: { label: String, error: String, hint: String },
    setup: (props, { slots }) => () =>
        h('label', { class: 'block' }, [
            h('span', { class: 'block text-sm font-medium text-slate-700 mb-1' }, props.label),
            slots.default?.(),
            props.error
                ? h('span', { class: 'block mt-1 text-xs text-red-600' }, props.error)
                : props.hint
                  ? h('span', { class: 'block mt-1 text-xs text-slate-500' }, props.hint)
                  : null,
        ]),
});

const token = new URLSearchParams(window.location.search).get('token') || '';
const base = `/api/logistics/invitations/${encodeURIComponent(token)}`;

const state = ref('loading');
const invite = ref({});
const busy = ref(false);
const error = ref('');
const message = ref('');
const renewalSent = ref(false);
const sessionEmail = ref('');
const fieldErrors = ref({});
const form = reactive({
    first_name: '',
    middle_initial: '',
    last_name: '',
    birthday: '',
    sex: '',
    province_code: '',
    municipality_code: '',
    barangay: '',
    street: '',
    house_no: '',
    password: '',
    password_confirmation: '',
});

const STEPS = ['Personal', 'Address', 'Security'];
const SEXES = ['Male', 'Female'];
// Which wizard step holds each server-validated field.
const STEP_OF_FIELD = {
    region: 1,
    province_code: 1,
    province_name: 1,
    municipality_code: 1,
    municipality_name: 1,
    barangay: 1,
    street: 1,
    house_no: 1,
    password: 2,
};
const step = ref(0);
const today = new Date().toISOString().slice(0, 10);

// ---- Address: same PSGC lookups (and caching) as the portal ----
const { fetchProvinces, fetchMunicipalities, fetchBarangays } = usePsgc();
const provinces = ref([]);
const municipalities = ref([]);
const barangays = ref([]);
const addressError = ref('');

async function loadList(target, loader, stillCurrent = () => true) {
    addressError.value = '';

    try {
        const list = await loader();
        if (stillCurrent()) target.value = list;
    } catch (e) {
        addressError.value = e.message || "Couldn't load addresses.";
    }
}

const loadProvinces = () => loadList(provinces, fetchProvinces);

function onProvinceChange() {
    const code = form.province_code;
    form.municipality_code = '';
    form.barangay = '';
    municipalities.value = [];
    barangays.value = [];
    if (code) loadList(municipalities, () => fetchMunicipalities(code), () => form.province_code === code);
}

function onMunicipalityChange() {
    const code = form.municipality_code;
    form.barangay = '';
    barangays.value = [];
    if (code) loadList(barangays, () => fetchBarangays(code), () => form.municipality_code === code);
}

function retryAddress() {
    if (!provinces.value.length) loadProvinces();
    else if (form.province_code && !municipalities.value.length) onProvinceChange();
    else if (form.municipality_code) onMunicipalityChange();
}

/** Per-step client checks; the server re-validates everything. */
function validateStep(index) {
    const errors = {};
    const need = (key, label) => {
        if (!form[key]) errors[key] = `${label} is required.`;
    };

    if (index === 0) {
        need('first_name', 'First name');
        need('last_name', 'Last name');
        need('birthday', 'Birthdate');
        need('sex', 'Sex');
        if (form.birthday && form.birthday > today) errors.birthday = "Birthdate can't be in the future.";
    } else if (index === 1) {
        need('province_code', 'Province');
        need('municipality_code', 'City / municipality');
        need('barangay', 'Barangay');
    } else {
        if (form.password.length < 8) errors.password = 'Password must be at least 8 characters.';
        if (form.password !== form.password_confirmation) errors.password_confirmation = 'Passwords do not match.';
    }

    fieldErrors.value = errors;

    return Object.keys(errors).length === 0;
}

function nextStep() {
    error.value = '';

    if (!validateStep(step.value)) return;

    if (step.value < STEPS.length - 1) {
        step.value++;
        // Warm the province list while the user reads the address step.
        if (!provinces.value.length) loadProvinces();

        return;
    }

    register();
}

const roleLabel = computed(() => (invite.value.role || '').replace(/^\w/, (c) => c.toUpperCase()));
const confirmMismatch = computed(
    () => form.password_confirmation.length > 0 && form.password !== form.password_confirmation,
);

let supabase = null;
function getSupabase() {
    supabase ??= window.supabase.createClient(window.CONFIG.SUPABASE_URL, window.CONFIG.SUPABASE_ANON_KEY);

    return supabase;
}

async function api(path, { method = 'GET', body, accessToken } = {}) {
    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    if (accessToken) headers.Authorization = `Bearer ${accessToken}`;

    const response = await fetch(base + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const err = new Error(
            Object.values(payload.errors || {}).flat()[0] || payload.message || 'Something went wrong. Please try again.',
        );
        err.status = response.status;
        err.errors = payload.errors || {};
        throw err;
    }

    return payload;
}

async function load() {
    if (!token) {
        state.value = 'invalid';

        return;
    }

    try {
        const [data, session] = await Promise.all([
            api(''),
            getSupabase().auth.getSession().then(({ data }) => data.session),
        ]);
        invite.value = data;
        sessionEmail.value = session?.user?.email?.toLowerCase() || '';
        renewalSent.value = data.renewal_requested;
        state.value = data.state;
    } catch (e) {
        message.value = e.status === 404 ? '' : e.message;
        state.value = 'invalid';
    }
}

function run(fn) {
    return async () => {
        busy.value = true;
        error.value = '';
        fieldErrors.value = {};

        try {
            await fn();
        } catch (e) {
            if (e.status === 410) {
                await load();
            }
            fieldErrors.value = Object.fromEntries(Object.entries(e.errors || {}).map(([k, v]) => [k, v[0]]));
            error.value = e.message;
        } finally {
            busy.value = false;
        }
    };
}

async function finish(session) {
    // UI hint cookie the logistics login page sets — keeps the portal's
    // session checks identical to a normal sign-in.
    const cookie = JSON.stringify({ email: session.user.email, role: 'logistics', status: 'active' });
    document.cookie = `buytheway_session=${encodeURIComponent(cookie)};max-age=${60 * 60 * 24};path=/;SameSite=Lax`;
    state.value = 'joined';
    window.location.href = '/logistics/dashboard';
}

async function signIn(password) {
    const { data, error: authError } = await getSupabase().auth.signInWithPassword({
        email: invite.value.email,
        password,
    });

    if (authError || !data.session) {
        throw new Error('Incorrect password for this email.');
    }

    return data.session;
}

async function acceptWith(session) {
    await api('/accept', { method: 'POST', accessToken: session.access_token });
    await finish(session);
}

const acceptExisting = run(async () => {
    const { data } = await getSupabase().auth.getSession();
    await acceptWith(data.session);
});

const signInAndAccept = run(async () => {
    await acceptWith(await signIn(form.password));
});

const register = run(async () => {
    const province = provinces.value.find((p) => p.code === form.province_code);
    // Provinces carry their island group (luzon/visayas/mindanao) — the
    // same `region` value the regular signup sends.
    const island = province?.islandGroupCode || '';

    try {
        await api('/register', {
            method: 'POST',
            body: {
                ...form,
                middle_initial: form.middle_initial.toUpperCase(),
                region: island.charAt(0).toUpperCase() + island.slice(1),
                province_name: province?.name || '',
                municipality_name: municipalities.value.find((m) => m.code === form.municipality_code)?.name || '',
            },
        });
    } catch (e) {
        // Jump back to the step that holds the first server-side error.
        const first = Object.keys(e.errors || {})[0];
        if (first) step.value = STEP_OF_FIELD[first] ?? 0;
        throw e;
    }
    await finish(await signIn(form.password));
});

const requestRenewal = run(async () => {
    const data = await api('/request-renewal', { method: 'POST' });
    message.value = data.message;
    renewalSent.value = true;
});

onMounted(load);
</script>

<style scoped>
@reference '../../css/app.css';

.input {
    @apply w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:border-teal-600;
}
.btn-primary {
    @apply flex w-full min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60 disabled:cursor-not-allowed transition-colors;
}
.btn-secondary {
    @apply flex w-full min-h-11 items-center justify-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors;
}
</style>
