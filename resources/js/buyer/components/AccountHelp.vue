<script setup>
/*
|--------------------------------------------------------------------------
| Help & Support
|--------------------------------------------------------------------------
|
| Answers drawn from how BuyTheWay actually works: payment
| (usePayment / CheckoutOptions), shipping fees (useShipping), cancelling
| (OrderCancellationService: only while an order is New), returns
| (ReturnController: after delivery, one open request per item) and
| reviews (ReviewController: delivered items only).
|
| Contact: sellers are reachable through the existing messaging. A
| BuyTheWay support address is shown only when one is configured
| (VITE_SUPPORT_EMAIL); there's no platform helpdesk to point to otherwise.
|
*/
import { computed, ref } from 'vue';
import { availablePaymentMethods } from '../composables/usePayment';
import { shippingOptions } from '../composables/useShipping';
import { formatPrice } from '../composables/useCategoryMeta';
import { goToAccount } from '../composables/useAccountNav';
import { requestBuyerView } from '../composables/useBuyerNav';

const SUPPORT_EMAIL = (import.meta.env.VITE_SUPPORT_EMAIL || '').trim();

const payments = availablePaymentMethods().map(method => method.name).join(', ');
const shipping = shippingOptions.map(option => `${option.shortName} ${formatPrice(option.fee)} (${option.eta})`).join(' or ');

const FAQS = computed(() => [
    {
        id: 'pay',
        q: 'How can I pay?',
        a: `At checkout you can pay with ${payments}. You pay the courier when your parcel arrives; nothing is charged online.`
    },
    {
        id: 'shipping',
        q: 'How much is shipping and how long does it take?',
        a: `Each seller ships their own parcel. Choose ${shipping}. The fee is charged once per seller in your order, however many items you buy from them.`
    },
    {
        id: 'track',
        q: 'Where is my order?',
        a: 'Open My Orders and choose the order to see its status and tracking timeline.',
        action: { label: 'Go to My Orders', to: 'orders' }
    },
    {
        id: 'cancel',
        q: 'Can I cancel an order?',
        a: 'Yes, while it is still New, before the seller starts preparing it. Open the order in My Orders and choose Cancel. After that, message the seller.',
        action: { label: 'Go to My Orders', to: 'orders' }
    },
    {
        id: 'return',
        q: 'How do returns and refunds work?',
        a: 'Once an order is delivered, open it in My Orders and request a return or refund for the item. Each item can have one open request at a time, and the seller reviews it. Some stores also describe their own policy on their store page.'
    },
    {
        id: 'review',
        q: 'When can I review a product?',
        a: 'After the order is delivered. Items waiting for your review appear in My Reviews.',
        action: { label: 'Go to My Reviews', to: 'reviews' }
    },
    {
        id: 'seller',
        q: 'How do I contact a seller?',
        a: 'Use Message seller on the product page or the store page. Replies appear in Messages, the chat icon at the top of the page.'
    }
]);

const open = ref(new Set(['pay']));

function toggle(id) {
    const next = new Set(open.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    open.value = next;
}
</script>

<template>

    <section
        class="acc-section"
        aria-labelledby="acc-help-title"
    >
        <header class="acc-head">
            <h1
                id="acc-help-title"
                class="acc-title"
            >
                Help &amp; Support
            </h1>
            <p class="acc-lede">Answers to common questions about ordering on BuyTheWay.</p>
        </header>

        <div class="acc-faq">
            <div
                v-for="item in FAQS"
                :key="item.id"
                class="acc-faq-item"
                :class="{ 'is-open': open.has(item.id) }"
            >
                <h2 class="acc-faq-q">
                    <button
                        :id="`acc-faq-${item.id}`"
                        type="button"
                        :aria-expanded="open.has(item.id)"
                        :aria-controls="`acc-faq-${item.id}-a`"
                        @click="toggle(item.id)"
                    >
                        {{ item.q }}
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                    </button>
                </h2>
                <div
                    :id="`acc-faq-${item.id}-a`"
                    class="acc-faq-a"
                    role="region"
                    :aria-labelledby="`acc-faq-${item.id}`"
                    :inert="!open.has(item.id) || undefined"
                >
                    <div class="acc-faq-a-inner">
                        <p>{{ item.a }}</p>
                        <button
                            v-if="item.action"
                            type="button"
                            class="link-btn"
                            @click="goToAccount(item.action.to)"
                        >
                            {{ item.action.label }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="acc-block">
            <h2 class="acc-subtitle">Still need help?</h2>
            <ul class="acc-contact">
                <li>
                    <p class="acc-switch-title">About an order or product</p>
                    <p class="acc-hint">Message the seller directly; they know the item and the parcel best.</p>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="requestBuyerView('stores')"
                    >
                        Find a store
                    </button>
                </li>
                <li v-if="SUPPORT_EMAIL">
                    <p class="acc-switch-title">About your account or BuyTheWay</p>
                    <p class="acc-hint">Email our team and include your order number if it&rsquo;s about an order.</p>
                    <a
                        :href="`mailto:${SUPPORT_EMAIL}`"
                        class="btn btn-secondary"
                    >
                        Email {{ SUPPORT_EMAIL }}
                    </a>
                </li>
            </ul>
        </div>
    </section>

</template>
