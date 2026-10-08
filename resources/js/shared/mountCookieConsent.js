import { createApp } from 'vue';
import CookieConsentBanner from './CookieConsentBanner.vue';

export function mountCookieConsent() {
    if (document.querySelector('[data-cookie-consent-root]')) {
        return;
    }

    const root = document.createElement('div');
    root.dataset.cookieConsentRoot = '';
    document.body.append(root);
    createApp(CookieConsentBanner).mount(root);
}
