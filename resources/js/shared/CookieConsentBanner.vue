<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const STORAGE_KEY = 'btw.cookie-consent';
const GUEST_KEY = 'btw.consent-guest-id';

const messages = {
    'en-PH': {
        title: 'Cookies and Tracking',
        body: 'We use cookies to remember your cart, keep you logged in, and understand how you use our site. You can control non-essential cookies through Cookie Preferences on our Cookie Policy page. We do not use cookies to force you to accept marketing.',
        accept: 'Accept All', reject: 'Reject Non-Essential', preferences: 'Cookie Preferences',
        save: 'Save Preferences', necessary: 'Strictly Necessary', functional: 'Functional',
        analytics: 'Analytics', marketing: 'Marketing', always: 'Always on', error: 'Could not save your preferences. Please try again.',
    },
    'fil-PH': {
        title: 'Cookies and Tracking',
        body: 'We use cookies to remember your cart, keep you logged in, and understand how you use our site. You can control non-essential cookies through Cookie Preferences on our Cookie Policy page. We do not use cookies to force you to accept marketing.',
        accept: 'Accept All', reject: 'Reject Non-Essential', preferences: 'Cookie Preferences',
        save: 'Save Preferences', necessary: 'Strictly Necessary', functional: 'Functional',
        analytics: 'Analytics', marketing: 'Marketing', always: 'Always on', error: 'Hindi ma-save ang iyong preferences. Subukan muli.',
    },
};

const locale = document.documentElement.lang?.toLowerCase().startsWith('fil') ? 'fil-PH' : 'en-PH';
const copy = computed(() => messages[locale]);
const visible = ref(true);
const expanded = ref(false);
const saving = ref(false);
const error = ref('');
const categories = reactive({ strictly_necessary: true, functional: false, analytics: false, marketing: false });

function storedSessionToken() {
    try {
        return JSON.parse(localStorage.getItem('btw.auth.session') || 'null')?.access_token || null;
    } catch {
        return null;
    }
}

function guestId() {
    let id = localStorage.getItem(GUEST_KEY);

    if (!id) {
        id = crypto.randomUUID();
        localStorage.setItem(GUEST_KEY, id);
    }

    return id;
}

async function persist(next, action) {
    if (saving.value) {
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        const token = storedSessionToken();
        const response = await fetch('/api/consent/cookies', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: JSON.stringify({ guest_id: guestId(), categories: next, action }),
        });

        if (!response.ok) {
            error.value = copy.value.error;

            return;
        }

        localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        Object.assign(categories, next);
        window.dispatchEvent(new CustomEvent('btw:cookie-consent-changed', { detail: next }));
        visible.value = false;
        expanded.value = false;
    } catch {
        error.value = copy.value.error;
    } finally {
        saving.value = false;
    }
}

function acceptAll() {
    persist({ strictly_necessary: true, functional: true, analytics: true, marketing: true }, 'granted');
}

function rejectNonEssential() {
    persist({ strictly_necessary: true, functional: false, analytics: false, marketing: false }, 'withdrawn');
}

function savePreferences() {
    persist({ ...categories, strictly_necessary: true }, 'updated');
}

function openPreferences() {
    visible.value = true;
    expanded.value = true;
}

onMounted(() => {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');

        if (saved?.strictly_necessary === true
            && ['functional', 'analytics', 'marketing'].every(key => typeof saved[key] === 'boolean')) {
            Object.assign(categories, saved);
            visible.value = false;
        }
    } catch {
        visible.value = true;
    }

    window.addEventListener('btw:open-cookie-preferences', openPreferences);
});

onBeforeUnmount(() => window.removeEventListener('btw:open-cookie-preferences', openPreferences));
</script>

<template>
    <section v-if="visible" class="cookie-consent" role="dialog" aria-modal="false" :aria-label="copy.title">
        <div class="cookie-consent__copy">
            <strong>{{ copy.title }}</strong>
            <p>{{ copy.body }}</p>
            <a href="/cookies">Cookie Policy</a>
        </div>
        <div v-if="expanded" class="cookie-consent__preferences">
            <label><span>{{ copy.necessary }} <small>{{ copy.always }}</small></span><input type="checkbox" checked disabled></label>
            <label><span>{{ copy.functional }}</span><input v-model="categories.functional" type="checkbox"></label>
            <label><span>{{ copy.analytics }}</span><input v-model="categories.analytics" type="checkbox"></label>
            <label><span>{{ copy.marketing }}</span><input v-model="categories.marketing" type="checkbox"></label>
        </div>
        <p v-if="error" class="cookie-consent__error" role="alert">{{ error }}</p>
        <div class="cookie-consent__actions">
            <button type="button" :disabled="saving" @click="acceptAll">{{ copy.accept }}</button>
            <button type="button" :disabled="saving" class="secondary" @click="rejectNonEssential">{{ copy.reject }}</button>
            <button v-if="!expanded" type="button" :disabled="saving" class="link" @click="expanded = true">{{ copy.preferences }}</button>
            <button v-else type="button" :disabled="saving" class="secondary" @click="savePreferences">{{ copy.save }}</button>
        </div>
    </section>
</template>

<style scoped>
.cookie-consent { position: fixed; z-index: 100000; inset: auto 1rem 1rem; max-width: 760px; margin-inline: auto; padding: 1rem; color: #17324d; background: #fff; border: 1px solid #d8e3eb; border-radius: 16px; box-shadow: 0 18px 60px rgba(16, 42, 67, .2); font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
.cookie-consent__copy strong { display: block; font-size: 1.05rem; }
.cookie-consent__copy p { margin: .45rem 0; line-height: 1.5; font-size: .9rem; }
.cookie-consent a { color: #0f766e; font-size: .85rem; font-weight: 650; }
.cookie-consent__preferences { display: grid; gap: .5rem; margin-top: .85rem; padding: .75rem; background: #f6faf9; border-radius: 10px; }
.cookie-consent__preferences label { display: flex; justify-content: space-between; align-items: center; gap: 1rem; font-size: .9rem; }
.cookie-consent__preferences small { color: #60758a; margin-left: .35rem; }
.cookie-consent__error { color: #b42318; font-size: .85rem; }
.cookie-consent__actions { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .9rem; }
.cookie-consent button { border: 1px solid #0f766e; border-radius: 9px; padding: .58rem .85rem; background: #0f766e; color: #fff; font: inherit; font-size: .83rem; font-weight: 700; cursor: pointer; }
.cookie-consent button.secondary { background: #fff; color: #0f766e; }
.cookie-consent button.link { border-color: transparent; background: transparent; color: #0f766e; }
.cookie-consent button:disabled { opacity: .6; cursor: wait; }
@media (min-width: 800px) { .cookie-consent { left: 50%; right: auto; width: calc(100% - 2rem); transform: translateX(-50%); } }
</style>
