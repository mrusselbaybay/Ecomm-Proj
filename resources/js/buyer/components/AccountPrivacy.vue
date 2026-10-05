<script setup>
/*
|--------------------------------------------------------------------------
| Privacy & Account
|--------------------------------------------------------------------------
|
| - Download my data: GET /api/buyer/account/export (JSON file).
| - What others see: facts from how the app actually works (sellers get
|   the delivery details on their own orders; reviews show "First L.").
| - Closing the account: there is no self-service deactivation or
|   deletion. Account status is changed by BuyTheWay admins, and order
|   records must stay with the sellers who fulfilled them. When a support
|   address is configured (VITE_SUPPORT_EMAIL) the buyer can send a
|   prefilled request; otherwise the page says plainly it isn't available.
|
*/
import { computed, ref } from 'vue';
import { useAccountSettings } from '../composables/useAccountSettings';
import { goToAccount } from '../composables/useAccountNav';
import { useBuyerSession } from '../composables/useBuyerSession';
import { useToasts } from '../composables/useToasts';

const { downloadMyData } = useAccountSettings();
const { buyerProfile } = useBuyerSession();
const toasts = useToasts();

const SUPPORT_EMAIL = (import.meta.env.VITE_SUPPORT_EMAIL || '').trim();

const downloading = ref(false);
const downloadError = ref('');

async function download() {
    if (downloading.value) {
        return;
    }

    downloading.value = true;
    downloadError.value = '';

    try {
        const name = await downloadMyData();
        toasts.success(`Downloaded ${name}.`);
    } catch (err) {
        downloadError.value = err?.message || 'Could not prepare your data.';
    } finally {
        downloading.value = false;
    }
}

const closeRequestHref = computed(() => {
    if (!SUPPORT_EMAIL) {
        return '';
    }

    const subject = encodeURIComponent('Close my BuyTheWay account');
    const body = encodeURIComponent(
        `Please close my BuyTheWay buyer account.\n\nAccount email: ${buyerProfile.value?.email || ''}\nAccount ID: ${buyerProfile.value?.id || ''}\n\nI'd like it: deactivated / deleted (keep one)`
    );

    return `mailto:${SUPPORT_EMAIL}?subject=${subject}&body=${body}`;
});
</script>

<template>

    <section
        class="acc-section"
        aria-labelledby="acc-privacy-title"
    >
        <header class="acc-head">
            <h1
                id="acc-privacy-title"
                class="acc-title"
            >
                Privacy &amp; Account
            </h1>
            <p class="acc-lede">What&rsquo;s shared, a copy of your data, and closing your account.</p>
        </header>

        <div class="acc-block is-first">
            <h2 class="acc-subtitle">What others can see</h2>
            <ul class="acc-plain-list">
                <li>Sellers see your name, mobile number and delivery address on orders you place with them, so they can deliver.</li>
                <li>Reviews you write show your first name and last initial, for example &ldquo;Maria S.&rdquo;</li>
                <li>Your email, birthday and saved addresses aren&rsquo;t shown to sellers or other buyers.</li>
            </ul>
            <p class="acc-hint">
                Marketing emails are off unless you turn them on in
                <button
                    type="button"
                    class="link-btn"
                    @click="goToAccount('notifications')"
                >
                    Notifications
                </button>.
            </p>
        </div>

        <div class="acc-block">
            <div class="acc-block-head">
                <div>
                    <h2 class="acc-subtitle">Download your data</h2>
                    <p class="acc-hint">A JSON file with your profile, addresses, orders, reviews, wishlist, followed stores and email choices.</p>
                </div>
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="downloading"
                    :aria-busy="downloading"
                    @click="download"
                >
                    {{ downloading ? 'Preparing…' : 'Download my data' }}
                </button>
            </div>
            <p
                v-if="downloadError"
                class="acc-error"
                role="alert"
            >
                {{ downloadError }}
            </p>
        </div>

        <div class="acc-block acc-danger">
            <h2 class="acc-subtitle">Close your account</h2>
            <dl class="acc-compare">
                <div>
                    <dt>Deactivate</dt>
                    <dd>Your account is switched off and you can&rsquo;t sign in. It can be turned back on later, with everything as you left it.</dd>
                </div>
                <div>
                    <dt>Delete</dt>
                    <dd>Your profile, addresses, wishlist and preferences are removed for good. Records of past orders stay with the sellers who fulfilled them.</dd>
                </div>
            </dl>
            <p class="acc-hint">
                Closing an account isn&rsquo;t self-service on BuyTheWay yet; it&rsquo;s done by our team.
                <template v-if="!closeRequestHref">There&rsquo;s no way to request it from here yet.</template>
            </p>
            <a
                v-if="closeRequestHref"
                :href="closeRequestHref"
                class="btn btn-danger-outline"
            >
                Request account closure by email
            </a>
        </div>
    </section>

</template>
